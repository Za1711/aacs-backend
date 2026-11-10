# AACS Backend (Automatic Air Conditioner System)

Backend sistem kontrol pendingin udara pintar (*smart air conditioner*) berbasis IoT dan Vision AI untuk optimalisasi efisiensi energi dan kenyamanan ruangan, dikembangkan sebagai proyek Capstone / Skripsi.

---

## 1. Ikhtisar Arsitektur Sistem

Sistem AACS mengintegrasikan perangkat keras nirkabel, broker pesan MQTT, framework Laravel, serta layanan visi komputer berbasis YOLOv8n yang berjalan di atas Raspberry Pi:

```text
[ESP32 Node Portabel] 
       │  (ESP-NOW Nirkabel)
       ▼
[ESP32 Remote IR] 
       │  (WiFi / MQTT - JSON v1)
       ▼
[Raspberry Pi 4]
  ├── Mosquitto MQTT Broker (Port 1883)
  ├── Laravel MQTT Worker (MqttSubscribeCommand - Supervisor)
  ├── MariaDB / MySQL (aacs_db)
  ├── Camera Service (stream.py: port 5000 | detect_occupancy.py: port 5001)
  └── Nginx Web Server (Laravel Web & REST API: https://api.aacs.my.id)
       ├── Dashboard Live Expo (/expo)
       ├── Hub Ekspor CSV Riset (/ )
       └── REST API Endpoints (/api/v1/...)
```

---

## 2. Referensi Topik MQTT

Semua komunikasi data antara mikrokontroler (ESP32) dan backend Raspberry Pi menggunakan broker MQTT dengan standar topik terstruktur:

- `room/{roomId}/telemetry`
  - Data suhu dan kelembaban ruangan aktif yang dipilih (Remote atau Node) dalam format JSON: `{"suhu": 28.5, "kelembaban": 77.2}`.
- `room/{roomId}/node/link-metrics`
  - Evaluasi performa transmisi ESP-NOW dari Node ke Remote (Session ID, sent_total, acked_total, failed_total, retries_total, RSSI dBm, tegangan & persentase baterai).
- `room/{roomId}/node/telemetry`
  - Data telemetri langsung dari sensor SHT31 modul Node portabel.
- `room/{roomId}/node/battery`
  - Status pemantauan daya baterai Node (tegangan milivolt dan persentase).
- `room/{roomId}/occupancy`
  - Hasil estimasi jumlah orang/kepala di ruangan dari deteksi YOLOv8n: `{"count": 2, "timestamp": ...}`.
- `room/{roomId}/ac/status`
  - Telemetri status operasional AC saat ini (Power, Setpoint, Mode, Fan Speed, Swing, Control Source).
- `room/{roomId}/ac/command`
  - Perintah kendali dari backend atau aplikasi ke perangkat Remote (POWER, SET_TEMP, MODE, FAN, SWING, UNPAIR).

---

## 3. Logika Evaluasi ESP-NOW & Link Metrics

Modul pengujian link metrics pada backend dirancang untuk riset transmisi nirkabel dan masa pakai baterai:

1. **Integritas Counter**: Backend memvalidasi bahwa `sent_total == acked_total + failed_total`.
2. **Kalkulasi Ulang PDR**: Nilai Packet Delivery Ratio dihitung ulang oleh backend untuk memastikan akurasi data riset:
   $$\text{PDR} = \left(\frac{\text{acked\_total}}{\text{sent\_total}}\right) \times 100\%$$
3. **Isolasi Berbasis Sesi**: Setiap kali Node di-reset atau dihidupkan ulang, pengujian dicatat di bawah `session_id` baru. Sesi lama tidak tertimpa.
4. **Kriteria Selesai Pengujian Baterai**: Sesi otomatis ditandai `SELESAI (<= 20%)` saat baterai menyentuh nilai $\le 20\%$ (3200 mV) atau flag `test_complete: true`.
5. **Penyimpanan Permanen**: Tabel `node_link_samples` tidak menggunakan *auto-pruning* agar seluruh data eksperimen skripsi tersimpan permanen.

---

## 4. Format Ekspor CSV Riset

Endpoint ekspor CSV dapat diakses langsung melalui:
`GET /api/v1/rooms/{roomId}/node-link-sessions/{sessionId}/export.csv`

File CSV memiliki struktur dua bagian:
- **Baris SAMPLE**: Baris data rekaman individual per paket pengiriman ESP-NOW (Seq, Timestamp UTC & WIB, Sent, Acked, Failed, Retries, PDR, RSSI dBm, Baterai mV & %).
- **Baris SUMMARY (Tepat 1 baris terakhir)**: Rekapitulasi performa komprehensif satu sesi (Total kirim, total sukses, PDR akhir, RSSI rata-rata/min/max, baterai awal vs akhir, dan durasi pengujian).

---

## 5. Layanan Vision AI & Kamera (`camera-service/`)

Folder `camera-service/` berisi layanan visi komputer yang berjalan mandiri:
- `stream.py`: Layanan Flask HTTP MJPEG stream (port 5000) yang mengunci fokus optik webcam hardware (v4l2 control `focus_absolute=240`) untuk kestabilan visual.
- `detect_occupancy.py`: Layanan daemon YOLOv8n Head Detector (port 5001) yang memuat model ke dalam RAM (*resident in-memory*) untuk menghasilkan latensi inferensi instan (~330 ms) saat tombol *Capture* di dashboard ditekan, lalu mempublikasikan hasilnya ke MQTT.

---

## 6. Struktur Direktori Utama

```text
aacs-backend/
├── app/
│   ├── Console/Commands/
│   │   └── MqttSubscribeCommand.php    # Background MQTT Worker (Supervisor)
│   ├── Http/Controllers/
│   │   ├── Api/
│   │   │   ├── AcCommandController.php
│   │   │   ├── NodeLinkMetricsController.php
│   │   │   └── TelemetryController.php
│   │   └── Web/
│   │       ├── DashboardController.php      # Hub Ekspor CSV
│   │       └── ExpoDashboardController.php  # Live Showcase Expo
│   └── Models/
│       ├── AcLog.php
│       ├── NodeBatteryLog.php
│       ├── NodeLinkSample.php
│       ├── NodeTelemetryLog.php
│       ├── OccupancyLog.php
│       └── SensorLog.php
├── camera-service/
│   ├── detect_occupancy.py              # YOLOv8n resident daemon & MQTT publisher
│   └── stream.py                        # USB webcam MJPEG stream proxy
├── database/
│   └── migrations/                      # Schema database MySQL/MariaDB
├── resources/views/
│   ├── dashboard.blade.php              # UI Hub Unduh CSV
│   └── expo.blade.php                   # UI Showcase Live Expo
├── routes/
│   ├── api.php
│   └── web.php
└── tests/                               # 127 pengujian otomatis PHPUnit
```

---

## 7. Instalasi & Menjalankan Pengujian Lokal

### Persyaratan
- PHP >= 8.2
- Composer
- Ekstensi PHP: `pdo_mysql`, `mbstring`, `bcmath`, `xml`, `curl`

### Langkah Setup
1. Clone repositori:
   ```bash
   git clone <URL_REPOSITORI_ANDA>
   cd aacs-backend
   ```
2. Pasang dependensi PHP:
   ```bash
   composer install
   ```
3. Salin konfigurasi environment:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
4. Jalankan pengujian unit dan fitur (semua 127 pengujian harus berstatus hijau):
   ```bash
   php artisan test
   ```

---

## 8. Lisensi & Hak Cipta

Dikembangkan untuk proyek Capstone & Penelitian Skripsi AACS &copy; 2026.
