<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AcLog;
use App\Models\NodeBatteryStatus;
use App\Models\NodeTelemetryLog;
use App\Models\NodeTelemetryStatus;
use App\Models\OccupancyLog;
use App\Models\RemoteDeviceStatus;
use App\Models\Room;
use App\Models\SensorLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class ExpoDashboardController extends Controller
{
    /**
     * Helper untuk downsampling data agar grafik rentang waktu tetap rapi, cepat, dan tidak overload.
     */
    private function samplePoints(Collection $items, int $targetCount = 75): Collection
    {
        $count = $items->count();
        if ($count <= $targetCount) {
            return $items;
        }

        $step = $count / $targetCount;
        $sampled = collect();
        for ($i = 0; $i < $targetCount; $i++) {
            $index = (int) floor($i * $step);
            if ($items->has($index)) {
                $sampled->push($items->get($index));
            }
        }

        // Pastikan titik data paling akhir selalu ikut masuk
        $lastItem = $items->last();
        if ($lastItem && $sampled->last()?->id !== $lastItem->id) {
            $sampled->push($lastItem);
        }

        return $sampled;
    }

    /**
     * Tampilkan halaman utama Expo Live Showcase Dashboard.
     */
    public function index(Request $request): View
    {
        $rooms = Room::all();
        $roomId = (int) $request->query('room_id', $rooms->first()?->id ?? 119);
        $currentRoom = Room::find($roomId) ?? $rooms->first();

        $range = $request->query('range', '1h');
        $since = match ($range) {
            '15m'      => now()->subMinutes(15),
            'realtime' => now()->subMinutes(5),
            default    => now()->subHour(),
        };

        // Data Riwayat Sensor Ruangan Aktif (Sesuai yang dikirim ke database: SensorLog)
        $sensorRaw = $currentRoom
            ? SensorLog::where('room_id', $currentRoom->id)
                ->where('created_at', '>=', $since)
                ->orderBy('created_at', 'asc')
                ->get()
            : collect();

        if ($sensorRaw->count() < 5 && $currentRoom) {
            $sensorRaw = SensorLog::where('room_id', $currentRoom->id)
                ->latest('created_at')
                ->limit(35)
                ->get()
                ->reverse()
                ->values();
        }

        $sensorLogs = $this->samplePoints($sensorRaw, 75);

        // Statistik Suhu & Kelembapan
        $stats = [
            'temp_min' => $sensorRaw->count() > 0 ? round((float) $sensorRaw->min('temperature'), 2) : null,
            'temp_max' => $sensorRaw->count() > 0 ? round((float) $sensorRaw->max('temperature'), 2) : null,
            'temp_avg' => $sensorRaw->count() > 0 ? round((float) $sensorRaw->avg('temperature'), 2) : null,
            'hum_min'  => $sensorRaw->count() > 0 ? round((float) $sensorRaw->min('humidity'), 2) : null,
            'hum_max'  => $sensorRaw->count() > 0 ? round((float) $sensorRaw->max('humidity'), 2) : null,
            'hum_avg'  => $sensorRaw->count() > 0 ? round((float) $sensorRaw->avg('humidity'), 2) : null,
        ];

        // Nilai sensor terbaru
        $latestSensor = $currentRoom
            ? SensorLog::where('room_id', $currentRoom->id)->latest('created_at')->first()
            : null;

        // Status Terkini Remote AC
        $latestAc = $currentRoom
            ? AcLog::where('room_id', $currentRoom->id)->latest('created_at')->first()
            : null;

        // Riwayat Perubahan Status AC (10 perubahan terakhir)
        $acHistory = $currentRoom
            ? AcLog::where('room_id', $currentRoom->id)
                ->latest('created_at')
                ->limit(10)
                ->get()
            : collect();

        // Okupansi Terkini (YOLO)
        $latestOccupancy = $currentRoom
            ? OccupancyLog::where('room_id', $currentRoom->id)->latest('created_at')->first()
            : null;

        // Status Online Perangkat Remote ESP32 (threshold 45 detik)
        $remoteStatus = $currentRoom ? RemoteDeviceStatus::where('room_id', $currentRoom->id)->first() : null;
        $deviceOnline = false;
        if ($remoteStatus && $remoteStatus->last_seen_at) {
            $deviceOnline = (now()->getTimestamp() - $remoteStatus->last_seen_at->getTimestamp()) <= 45;
        }

        // Status Node
        $nodeStatus = $currentRoom ? NodeTelemetryStatus::where('room_id', $currentRoom->id)->first() : null;
        $nodeOnline = false;
        if ($nodeStatus && $nodeStatus->received_at && $nodeStatus->valid) {
            $nodeOnline = (now()->getTimestamp() - $nodeStatus->received_at->getTimestamp()) <= 60;
        }

        // Baterai Node
        $batteryStatus = $currentRoom ? NodeBatteryStatus::where('room_id', $currentRoom->id)->first() : null;

        return view('expo', compact(
            'rooms',
            'currentRoom',
            'sensorLogs',
            'latestSensor',
            'stats',
            'latestAc',
            'acHistory',
            'latestOccupancy',
            'deviceOnline',
            'nodeOnline',
            'remoteStatus',
            'nodeStatus',
            'batteryStatus',
            'range'
        ));
    }

    /**
     * Endpoint API untuk update realtime (AJAX polling per 2.5 detik) tanpa reload halaman.
     */
    public function liveData(Request $request, int $roomId): JsonResponse
    {
        $room = Room::find($roomId);
        if (!$room) {
            return response()->json(['status' => 'error', 'message' => 'Room tidak ditemukan'], 404);
        }

        $range = $request->query('range', '1h');
        $since = match ($range) {
            '15m'      => now()->subMinutes(15),
            'realtime' => now()->subMinutes(5),
            default    => now()->subHour(),
        };

        // Ambil data Sensor Ruangan Aktif
        if ($range === 'realtime') {
            $sensorRaw = SensorLog::where('room_id', $roomId)
                ->latest('created_at')
                ->limit(30)
                ->get()
                ->reverse()
                ->values();
        } else {
            $sensorRaw = SensorLog::where('room_id', $roomId)
                ->where('created_at', '>=', $since)
                ->orderBy('created_at', 'asc')
                ->get();

            if ($sensorRaw->count() < 5) {
                $sensorRaw = SensorLog::where('room_id', $roomId)
                    ->latest('created_at')
                    ->limit(30)
                    ->get()
                    ->reverse()
                    ->values();
            }
        }

        $sensorSampled = $this->samplePoints($sensorRaw, 75);

        $sensorChart = $sensorSampled->map(function ($log) {
            return [
                'time'        => Carbon::parse($log->created_at)->timezone('Asia/Jakarta')->format('H:i:s'),
                'temperature' => (float) $log->temperature,
                'humidity'    => (float) $log->humidity,
            ];
        })->values();

        // Statistik Suhu & Kelembapan
        $stats = [
            'temp_min' => $sensorRaw->count() > 0 ? round((float) $sensorRaw->min('temperature'), 2) : null,
            'temp_max' => $sensorRaw->count() > 0 ? round((float) $sensorRaw->max('temperature'), 2) : null,
            'temp_avg' => $sensorRaw->count() > 0 ? round((float) $sensorRaw->avg('temperature'), 2) : null,
            'hum_min'  => $sensorRaw->count() > 0 ? round((float) $sensorRaw->min('humidity'), 2) : null,
            'hum_max'  => $sensorRaw->count() > 0 ? round((float) $sensorRaw->max('humidity'), 2) : null,
            'hum_avg'  => $sensorRaw->count() > 0 ? round((float) $sensorRaw->avg('humidity'), 2) : null,
        ];

        // Status Terkini Remote AC
        $latestAc = AcLog::where('room_id', $roomId)->latest('created_at')->first();

        // Riwayat Perubahan Status AC
        $acHistory = AcLog::where('room_id', $roomId)
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                return [
                    'time'           => Carbon::parse($log->created_at)->timezone('Asia/Jakarta')->format('H:i:s'),
                    'power'          => $log->power,
                    'setpoint'       => $log->setpoint,
                    'mode'           => $log->mode,
                    'fan_speed'      => $log->fan_speed,
                    'swing'          => $log->swing,
                    'control_source' => $log->control_source,
                ];
            });

        // Okupansi Terkini
        $latestOccupancy = OccupancyLog::where('room_id', $roomId)->latest('created_at')->first();

        // Status Online
        $remoteStatus = RemoteDeviceStatus::where('room_id', $roomId)->first();
        $deviceOnline = false;
        if ($remoteStatus && $remoteStatus->last_seen_at) {
            $deviceOnline = (now()->getTimestamp() - $remoteStatus->last_seen_at->getTimestamp()) <= 45;
        }

        $nodeStatus = NodeTelemetryStatus::where('room_id', $roomId)->first();
        $nodeOnline = false;
        if ($nodeStatus && $nodeStatus->received_at && $nodeStatus->valid) {
            $nodeOnline = (now()->getTimestamp() - $nodeStatus->received_at->getTimestamp()) <= 60;
        }

        $batteryStatus = NodeBatteryStatus::where('room_id', $roomId)->first();

        // Nilai sensor realtime terbaru
        $lastSensor = SensorLog::where('room_id', $roomId)->latest('created_at')->first();

        return response()->json([
            'status'           => 'success',
            'room_id'          => $roomId,
            'range'            => $range,
            'sensor_chart'     => $sensorChart,
            'remote_chart'     => $sensorChart, // kompatibilitas
            'node_chart'       => $sensorChart, // kompatibilitas
            'latest_sensor'    => [
                'temperature' => $lastSensor ? (float) $lastSensor->temperature : null,
                'humidity'    => $lastSensor ? (float) $lastSensor->humidity : null,
                'time'        => $lastSensor ? Carbon::parse($lastSensor->created_at)->timezone('Asia/Jakarta')->format('H:i:s') : null,
            ],
            'latest_remote'    => [
                'temperature' => $lastSensor ? (float) $lastSensor->temperature : null,
                'humidity'    => $lastSensor ? (float) $lastSensor->humidity : null,
            ],
            'stats'            => $stats,
            'ac_status'        => $latestAc ? [
                'power'          => $latestAc->power,
                'setpoint'       => $latestAc->setpoint,
                'mode'           => $latestAc->mode,
                'ac_mode'        => $latestAc->ac_mode,
                'fan_speed'      => $latestAc->fan_speed,
                'swing'          => $latestAc->swing,
                'control_source' => $latestAc->control_source,
                'node_connected' => (bool) $latestAc->node_connected,
                'node_paired'    => (bool) $latestAc->node_paired,
                'updated_at'     => Carbon::parse($latestAc->created_at)->timezone('Asia/Jakarta')->format('H:i:s'),
            ] : null,
            'ac_history'       => $acHistory,
            'occupancy'        => $latestOccupancy ? [
                'count'      => (int) $latestOccupancy->people_count,
                'updated_at' => Carbon::parse($latestOccupancy->created_at)->timezone('Asia/Jakarta')->format('H:i:s'),
            ] : null,
            'device_online'    => $deviceOnline,
            'node_online'      => $nodeOnline,
            'battery'          => $batteryStatus && $batteryStatus->valid ? [
                'percent'    => $batteryStatus->percent,
                'millivolts' => $batteryStatus->millivolts,
            ] : null,
            'server_time_wib'  => now()->timezone('Asia/Jakarta')->format('H:i:s'),
        ]);
    }

    /**
     * Trigger pengambilan frame kamera & inferensi YOLO instan.
     */
    public function captureAndDetect(Request $request, int $roomId): JsonResponse
    {
        $room = Room::find($roomId);
        if (!$room) {
            return response()->json(['status' => 'error', 'message' => 'Room tidak ditemukan'], 404);
        }

        try {
            // Panggil internal daemon port 5001 yang sudah memuat YOLO di RAM
            $response = Http::timeout(10)->post('http://127.0.0.1:5001/trigger_capture', [
                'room_id' => $roomId,
            ]);

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'status'  => 'error',
                'message' => 'Layanan deteksi mengembalikan status ' . $response->status(),
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal menghubungi service deteksi kamera: ' . $e->getMessage(),
            ], 500);
        }
    }
}
