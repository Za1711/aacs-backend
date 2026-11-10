from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
import threading
import time
import json
import logging
import cv2
import requests
import os
import shutil
from ultralytics import YOLO
import paho.mqtt.client as mqtt
import ssl
from datetime import datetime, timedelta

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

def load_env(filepath=None):
    if filepath is None:
        filepath = os.path.join(BASE_DIR, ".env")
    env_vars = {}
    if os.path.exists(filepath):
        with open(filepath, "r") as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    key, val = line.split("=", 1)
                    env_vars[key.strip()] = val.strip()
    return env_vars

# Load initial env
ENV = load_env()
API_URL = ENV.get("AACS_API_URL", "http://127.0.0.1/api/v1")

STREAM_URL = "http://localhost:5000/video_feed"
MODEL_PATH = os.path.join(BASE_DIR, "models/best.pt")
CONF_THRESHOLD = float(ENV.get("CONF_THRESHOLD", "0.6"))  # Ambang batas deteksi kepala (0.6 untuk penelitian)
INTERVAL_SECONDS = 120       # Ambil gambar & inferensi tiap 2 menit (120 detik)
HEARTBEAT_INTERVAL = 120     # Kirim heartbeat berkala tiap 120 detik
ROOM_REFRESH_INTERVAL = 300  # Refresh mapping room tiap 5 menit

# Konfigurasi Jam Operasional (07:00 - 17:00 WIB)
START_HOUR = 7   # 07:00 Pagi
END_HOUR = 17    # 05:00 Sore (17:00)

# Konfigurasi Penyimpanan Gambar & Retensi
CAPTURES_DIR = os.path.join(BASE_DIR, "captures")
RETENTION_DAYS = 4  # Hapus folder jika sudah >= 4 hari (menyimpan data 3 hari terakhir)

MQTT_HOST = "f62988abf955495196dcc5ef9ec94248.s1.eu.hivemq.cloud"
MQTT_PORT = 8883
MQTT_USER = "AACS"
MQTT_PASS = "Capstone123"

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")

logging.info(f"Memuat model YOLO dari {MODEL_PATH}...")
model = YOLO(MODEL_PATH)

class State:
    room_id = None
    occupancy_topic = None
    last_room_check = datetime.min
    last_publish_time = datetime.min
    last_people_count = -1
    is_active_hours = True
    last_cleanup_check = datetime.min

def is_operating_hours(now: datetime) -> bool:
    """Cek apakah saat ini berada dalam jam operasional 07:00 - 17:00 WIB."""
    return START_HOUR <= now.hour < END_HOUR

def cleanup_old_captures():
    """Hapus folder harian yang usianya sudah >= 4 hari agar penyimpanan aman."""
    if not os.path.exists(CAPTURES_DIR):
        return

    today = datetime.now().date()
    try:
        for entry in os.listdir(CAPTURES_DIR):
            folder_path = os.path.join(CAPTURES_DIR, entry)
            if os.path.isdir(folder_path):
                try:
                    folder_date = datetime.strptime(entry, "%Y-%m-%d").date()
                    diff_days = (today - folder_date).days
                    if diff_days >= RETENTION_DAYS:
                        shutil.rmtree(folder_path)
                        logging.info(f"[CLEANUP] Folder gambar lama dihapus (usia {diff_days} hari): {entry}")
                except ValueError:
                    # Lewati jika bukan format tanggal YYYY-MM-DD
                    pass
    except Exception as e:
        logging.error(f"[CLEANUP] Error saat membersihkan folder lama: {e}")

def save_captured_images(frame, results, now: datetime, count: int) -> tuple[str, str]:
    """
    Simpan frame foto asli (raw) dan frame hasil inferensi (annotated)
    ke subfolder harian masing-masing:
    - captures/YYYY-MM-DD/raw/YYYY-MM-DD_HH-MM-SS_count_X.jpg
    - captures/YYYY-MM-DD/annotated/YYYY-MM-DD_HH-MM-SS_count_X.jpg
    """
    raw_path = ""
    annotated_path = ""
    try:
        date_str = now.strftime("%Y-%m-%d")
        time_str = now.strftime("%H-%M-%S")
        
        day_dir = os.path.join(CAPTURES_DIR, date_str)
        raw_dir = os.path.join(day_dir, "raw")
        annotated_dir = os.path.join(day_dir, "annotated")
        
        os.makedirs(raw_dir, exist_ok=True)
        os.makedirs(annotated_dir, exist_ok=True)
        
        filename = f"{date_str}_{time_str}_count_{count}.jpg"
        raw_path = os.path.join(raw_dir, filename)
        annotated_path = os.path.join(annotated_dir, filename)
        
        # 1. Simpan frame mentah kamera (raw) untuk keperluan dataset training
        if frame is not None:
            cv2.imwrite(raw_path, frame)
            logging.info(f"[IMAGE] Raw disimpan: {raw_path}")
            
        # 2. Simpan frame dengan bounding box (annotated) untuk monitoring deteksi
        if results and len(results) > 0:
            annotated_frame = results[0].plot()
            cv2.imwrite(annotated_path, annotated_frame)
            logging.info(f"[IMAGE] Annotated disimpan: {annotated_path}")
            
        return raw_path, annotated_path
    except Exception as e:
        logging.error(f"[IMAGE] Gagal menyimpan gambar: {e}")
        return raw_path, annotated_path

def save_annotated_image(results, now: datetime, count: int) -> str:
    """Fungsi pembungkus untuk kompatibilitas mundur."""
    _, annotated_path = save_captured_images(None, results, now, count)
    return annotated_path

def get_device_room_info():
    token = load_env().get("AACS_DEVICE_TOKEN", "")
    if not token:
        logging.error("[ROOM CHECK] AACS_DEVICE_TOKEN kosong!")
        return None

    try:
        url = f"{API_URL}/iot/my-room"
        headers = {"Authorization": f"Bearer {token}", "Accept": "application/json"}
        res = requests.get(url, headers=headers, timeout=10)
        
        if res.status_code == 200:
            return res.json().get("data", {})
        elif res.status_code == 401:
            logging.error("[ROOM CHECK] Token invalid (401). Hentikan publish sementara.")
            return "401"
        elif res.status_code == 403:
            logging.error("[ROOM CHECK] Device bukan RASPBERRY_PI (403).")
            return "403"
        elif res.status_code == 409:
            logging.warning("[ROOM CHECK] Device belum memiliki room (409). Retry dengan backoff.")
            return "409"
        else:
            logging.error(f"[ROOM CHECK] Gagal API: {res.status_code}")
    except Exception as e:
        logging.error(f"[ROOM CHECK] Gagal koneksi ke API.")
    return None

def publish_to_esp32(topic, count, max_retries=2):
    for attempt in range(1, max_retries + 1):
        try:
            client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id=f"yolo-node-{int(time.time())}")
            client.username_pw_set(MQTT_USER, MQTT_PASS)
            client.tls_set(cert_reqs=ssl.CERT_REQUIRED, tls_version=ssl.PROTOCOL_TLSv1_2)
            
            client.connect(MQTT_HOST, MQTT_PORT, keepalive=10)
            client.loop_start()
            
            res = client.publish(topic, str(count), qos=1)
            res.wait_for_publish(timeout=5)
            
            client.loop_stop()
            client.disconnect()
            logging.info(f"[OCCUPANCY] count={count} publish=success")
            return True
        except Exception as e:
            if attempt == max_retries:
                logging.error(f"[MQTT] Publish gagal setelah {max_retries} percobaan.")
            time.sleep(2)
    return False

def save_to_database(room_id, count):
    token = load_env().get("AACS_DEVICE_TOKEN", "")
    url = f"{API_URL}/rooms/{room_id}/occupancy"
    try:
        headers = {"Authorization": f"Bearer {token}", "Content-Type": "application/json", "Accept": "application/json"}
        requests.post(url, json={"people_count": count}, headers=headers, timeout=5)
    except:
        pass


def process_single_capture(override_room_id=None):
    now = datetime.now()
    cap = cv2.VideoCapture(STREAM_URL)
    if not cap.isOpened():
        return {"status": "error", "message": "Gagal membuka stream kamera"}

    ret, frame = cap.read()
    cap.release()

    if not ret or frame is None:
        return {"status": "error", "message": "Gagal mengambil frame gambar dari kamera"}

    t0 = time.time()
    results = model.predict(source=frame, conf=CONF_THRESHOLD, imgsz=640, verbose=False)
    dt_ms = round((time.time() - t0) * 1000, 1)

    people_count = len(results[0].boxes) if results[0].boxes is not None else 0

    # 1. Simpan ke folder captures harian (raw & annotated)
    save_captured_images(frame, results, now, people_count)

    # 2. Simpan ke web public captures untuk display instant di web browser
    public_captures = "/var/www/aacs-backend/public/captures"
    os.makedirs(public_captures, exist_ok=True)
    latest_img_path = os.path.join(public_captures, "latest_detection.jpg")
    annotated_frame = results[0].plot()
    cv2.imwrite(latest_img_path, annotated_frame)

    # 3. Tentukan room_id & topic
    target_room_id = override_room_id or State.room_id or 119
    target_topic = f"room/{target_room_id}/occupancy"

    # 4. Publish ke ESP32 Remote
    mqtt_success = publish_to_esp32(target_topic, people_count)
    if mqtt_success:
        State.last_people_count = people_count
        State.last_publish_time = now
        save_to_database(target_room_id, people_count)

    boxes_info = []
    if results[0].boxes is not None:
        for box in results[0].boxes:
            conf = float(box.conf[0])
            xyxy = [round(float(coord), 1) for coord in box.xyxy[0]]
            boxes_info.append({
                "confidence": round(conf * 100, 1),
                "box": xyxy
            })

    return {
        "status": "success",
        "people_count": people_count,
        "latency_ms": dt_ms,
        "mqtt_topic": target_topic,
        "mqtt_published": mqtt_success,
        "boxes_count": len(boxes_info),
        "boxes": boxes_info,
        "image_url": f"/captures/latest_detection.jpg?t={int(time.time() * 1000)}",
        "timestamp_wib": now.strftime("%Y-%m-%d %H:%M:%S")
    }

class ExpoCaptureHandler(BaseHTTPRequestHandler):
    def do_POST(self):
        if self.path == '/trigger_capture':
            content_length = int(self.headers.get('Content-Length', 0))
            post_data = self.rfile.read(content_length) if content_length > 0 else b'{}'
            try:
                payload = json.loads(post_data.decode('utf-8'))
            except:
                payload = {}

            room_id = payload.get('room_id')
            result = process_single_capture(override_room_id=room_id)

            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Access-Control-Allow-Origin', '*')
            self.end_headers()
            self.wfile.write(json.dumps(result).encode('utf-8'))
        else:
            self.send_response(404)
            self.end_headers()

    def do_GET(self):
        if self.path == '/status':
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps({"status": "ok", "model": MODEL_PATH, "conf": CONF_THRESHOLD}).encode('utf-8'))
        else:
            self.send_response(404)
            self.end_headers()

    def log_message(self, format, *args):
        pass

def run_http_server():
    try:
        server = ThreadingHTTPServer(('127.0.0.1', 5001), ExpoCaptureHandler)
        logging.info("[HTTP] Server capture instant aktif di http://127.0.0.1:5001")
        server.serve_forever()
    except Exception as e:
        logging.error(f"[HTTP] Server capture error: {e}")

def main():
    logging.info("=== Service Deteksi Okupansi Dimulai ===")
    os.makedirs(CAPTURES_DIR, exist_ok=True)
    cleanup_old_captures()

    # Jalankan HTTP server trigger capture di thread terpisah untuk expo
    http_thread = threading.Thread(target=run_http_server, daemon=True)
    http_thread.start()


    while True:
        now = datetime.now()

        # 1. Pengecekan Jam Operasional (07:00 - 17:00)
        if not is_operating_hours(now):
            if State.is_active_hours:
                logging.info(f"[SCHEDULE] Di luar jam operasional (07:00 - 17:00). Standby... (Saat ini: {now.strftime('%H:%M:%S')})")
                State.is_active_hours = False
            time.sleep(30)
            continue
        else:
            if not State.is_active_hours:
                logging.info(f"[SCHEDULE] Memasuki jam operasional (07:00 - 17:00). Deteksi aktif. (Saat ini: {now.strftime('%H:%M:%S')})")
                State.is_active_hours = True

        # 2. Pembersihan folder lama secara berkala (tiap 1 jam)
        if (now - State.last_cleanup_check).total_seconds() > 3600:
            cleanup_old_captures()
            State.last_cleanup_check = now

        # 3. Refresh room mapping berkala atau jika belum terisi
        if State.room_id is None or (now - State.last_room_check).total_seconds() > ROOM_REFRESH_INTERVAL:
            info = get_device_room_info()
            if isinstance(info, str):
                # Error status (401, 403, 409)
                State.room_id = None
                time.sleep(15) # Backoff
                continue
            elif isinstance(info, dict) and info.get("room_id"):
                State.room_id = info["room_id"]
                State.occupancy_topic = info["occupancy_topic"]
                State.last_room_check = now
                logging.info(f"[ROOM] room_id={State.room_id} topic={State.occupancy_topic}")
            else:
                # API Timeout/5xx, pertahankan cache jika ada, tunggu sebentar
                time.sleep(5)

        if not State.room_id:
            time.sleep(5)
            continue

        # 4. Capture & Inferensi
        cap = cv2.VideoCapture(STREAM_URL)
        if not cap.isOpened():
            time.sleep(5)
            continue

        ret, frame = cap.read()
        cap.release()

        if ret and frame is not None:
            results = model.predict(source=frame, conf=CONF_THRESHOLD, imgsz=640, verbose=False)
            people_count = len(results[0].boxes) if results[0].boxes is not None else 0

            # Simpan gambar mentah (raw) dan hasil deteksi (annotated) ke folder harian
            save_captured_images(frame, results, now, people_count)

            # Publish logic: jika nilai berubah ATAU sudah mencapai interval heartbeat
            time_since_publish = (now - State.last_publish_time).total_seconds()
            if people_count != State.last_people_count or time_since_publish >= HEARTBEAT_INTERVAL:
                success = publish_to_esp32(State.occupancy_topic, people_count)
                if success:
                    State.last_people_count = people_count
                    State.last_publish_time = now
                    save_to_database(State.room_id, people_count)

        time.sleep(INTERVAL_SECONDS)

if __name__ == "__main__":
    main()
