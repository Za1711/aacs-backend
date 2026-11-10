<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SensorLog;
use App\Models\OccupancyLog;
use App\Models\AcLog;
use App\Models\NodeLinkSample;
use App\Models\ControlLog;
use App\Models\NodeBatteryLog;
use App\Models\RemoteDeviceStatus;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TelemetryController extends Controller
{
    /**
     * Get latest status for a room with dynamic freshness calculation.
     */
    public function latestStatus(int $roomId): JsonResponse
    {
        $room = Room::where('user_id', auth()->id())->findOrFail($roomId);

        $now = Carbon::now('UTC');

        // Sensor telemetry & dynamic freshness calculation (< 20 detik)
        $sensor = SensorLog::where('room_id', $roomId)->latest('created_at')->first();
        $sensorData = [
            'temperature' => null,
            'humidity'    => null,
            'updated_at'  => null,
            'is_stale'    => true,
        ];

        $telemetryRecent = false;
        if ($sensor && $sensor->created_at) {
            $sensorCreatedAt = Carbon::parse($sensor->created_at)->utc();
            $ageSeconds = max(0, $now->getTimestamp() - $sensorCreatedAt->getTimestamp());
            $isStale = ($ageSeconds >= 20);

            $sensorData = [
                'temperature' => (float) $sensor->temperature,
                'humidity'    => (float) $sensor->humidity,
                'updated_at'  => $sensorCreatedAt->toIso8601ZuluString(),
                'is_stale'    => $isStale,
            ];

            if (!$isStale) {
                $telemetryRecent = true;
            }
        }

        // Status online Remote ESP32 murni berdasarkan heartbeat ac/status (RemoteDeviceStatus)
        $remoteStatus = $room->remoteDeviceStatus;
        $lastSeenAt = $remoteStatus?->last_seen_at ? Carbon::parse($remoteStatus->last_seen_at)->utc() : null;
        $timeout = (int) config('aacs.remote_online_timeout_seconds', 20);
        $deviceOnline = ($lastSeenAt !== null && $lastSeenAt->gt($now->copy()->subSeconds($timeout)));
        $deviceLastSeenAt = $lastSeenAt?->toIso8601ZuluString();

        $devicePaired = $room->devices()->where('is_paired', true)->exists();

        $occupancy = OccupancyLog::where('room_id', $roomId)->latest('created_at')->first();
        
        $occupancyData = null;
        if ($occupancy) {
            $occupancyAgeSeconds = max(0, $now->getTimestamp() - $occupancy->created_at->getTimestamp());
            $occupancyData = [
                'id'           => $occupancy->id,
                'room_id'      => $occupancy->room_id,
                'people_count' => $occupancy->people_count,
                'created_at'   => Carbon::parse($occupancy->created_at)->utc()->toIso8601ZuluString(),
                'age_seconds'  => $occupancyAgeSeconds,
                'is_stale'     => $occupancyAgeSeconds > 180,
            ];
        }

        $battery = $room->getNodeBatteryStatus();

        // Node sensor telemetry (stale timeout 60 detik)
        $nodeStatus = $room->nodeTelemetryStatus;
        $isNodeStale = !$nodeStatus
            || !$nodeStatus->valid
            || is_null($nodeStatus->received_at)
            || Carbon::parse($nodeStatus->received_at)->utc()->lt($now->copy()->subSeconds(60));

        $nodeSensorData = [
            'temperature' => ($nodeStatus && $nodeStatus->temperature !== null) ? (float) $nodeStatus->temperature : null,
            'humidity'    => ($nodeStatus && $nodeStatus->humidity !== null) ? (float) $nodeStatus->humidity : null,
            'updated_at'  => $nodeStatus?->received_at ? Carbon::parse($nodeStatus->received_at)->utc()->toIso8601ZuluString() : null,
            'is_stale'    => $isNodeStale,
            'valid'       => $nodeStatus ? (bool) $nodeStatus->valid : false,
        ];

        $acStatus = AcLog::where('room_id', $roomId)->latest('created_at')->first();
        $rawControlSource = $acStatus?->control_source ?? 'REMOTE';
        $controlSource   = ($rawControlSource === 'NODE' || $rawControlSource === 'USER_NODE') ? 'USER_NODE' : 'REMOTE';
        $nodePaired      = $acStatus ? (bool) $acStatus->node_paired : false;
        $nodeConnected   = $acStatus ? (bool) $acStatus->node_connected : false;

        // node_battery_low = true hanya jika node connected, battery valid, fresh (tidak stale), dan millivolts <= 3300
        $isBatteryFreshAndValid = ($battery['valid'] === true) && ($battery['is_stale'] === false);
        $mv = $battery['millivolts'] ?? null;
        $pct = $battery['percent'] ?? null;

        $nodeBatteryLow = $nodeConnected
            && $isBatteryFreshAndValid
            && $mv !== null
            && $mv <= 3300
            && ($pct === null || $pct <= 20);

        if ($acStatus) {
            $acStatus->node_battery_low = (bool) $nodeBatteryLow;
        }

        // Node link metrics summary for latest-status
        $latestLinkSample = NodeLinkSample::where('room_id', $roomId)
            ->orderBy('received_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($latestLinkSample) {
            $linkSessionId = (int) $latestLinkSample->session_id;
            $hasComplete = NodeLinkSample::where('room_id', $roomId)
                ->where('session_id', $linkSessionId)
                ->where('test_complete', true)
                ->exists();

            $linkLastSampleAt = Carbon::parse($latestLinkSample->received_at)->utc();
            $linkAgeSeconds = max(0, $now->getTimestamp() - $linkLastSampleAt->getTimestamp());
            $linkTimeout = (int) config('aacs.node_link_active_timeout_seconds', 30);

            if ($hasComplete) {
                $linkState = 'COMPLETED';
            } elseif ($linkAgeSeconds <= $linkTimeout) {
                $linkState = 'ACTIVE';
            } else {
                $linkState = 'STALE';
            }

            $rssiStats = NodeLinkSample::where('room_id', $roomId)
                ->where('session_id', $linkSessionId)
                ->where('telemetry_received', true)
                ->whereNotNull('rssi_dbm')
                ->selectRaw('AVG(rssi_dbm) as avg_rssi, MIN(rssi_dbm) as min_rssi, MAX(rssi_dbm) as max_rssi')
                ->first();

            $pdrPercent = $latestLinkSample->sent_total > 0
                ? round(100.0 * ($latestLinkSample->acked_total / $latestLinkSample->sent_total), 2)
                : null;

            $nodeLinkData = [
                'available'             => true,
                'session_id'            => $linkSessionId,
                'state'                 => $linkState,
                'last_delivery_success' => (bool) $latestLinkSample->telemetry_acked,
                'last_rssi_dbm'         => ($latestLinkSample->telemetry_received && $latestLinkSample->rssi_dbm !== null) ? (int) $latestLinkSample->rssi_dbm : null,
                'average_rssi_dbm'      => $rssiStats && $rssiStats->avg_rssi !== null ? round((float) $rssiStats->avg_rssi, 2) : null,
                'minimum_rssi_dbm'      => $rssiStats && $rssiStats->min_rssi !== null ? (int) $rssiStats->min_rssi : null,
                'maximum_rssi_dbm'      => $rssiStats && $rssiStats->max_rssi !== null ? (int) $rssiStats->max_rssi : null,
                'pdr_percent'           => $pdrPercent,
                'sent_total'            => (int) $latestLinkSample->sent_total,
                'acked_total'           => (int) $latestLinkSample->acked_total,
                'failed_total'          => (int) $latestLinkSample->failed_total,
                'updated_at'            => $linkLastSampleAt->toIso8601ZuluString(),
                'is_stale'              => ($linkState === 'STALE'),
                'test_complete'         => (bool) $hasComplete,
            ];
        } else {
            $nodeLinkData = [
                'available'             => false,
                'session_id'            => null,
                'state'                 => 'NO_DATA',
                'last_delivery_success' => null,
                'last_rssi_dbm'         => null,
                'average_rssi_dbm'      => null,
                'minimum_rssi_dbm'      => null,
                'maximum_rssi_dbm'      => null,
                'pdr_percent'           => null,
                'sent_total'            => 0,
                'acked_total'           => 0,
                'failed_total'          => 0,
                'updated_at'            => null,
                'is_stale'              => true,
                'test_complete'         => false,
            ];
        }

        return response()->json([
            'data' => [
                'sensor'              => $sensorData,
                'node_sensor'         => $nodeSensorData,
                'occupancy'           => $occupancyData,
                'ac_status'           => $acStatus,
                'last_control'        => ControlLog::where('room_id', $roomId)->latest('created_at')->first(),
                'node_battery'        => $battery,
                'node_link'           => $nodeLinkData,
                'device_paired'       => $devicePaired,
                'control_source'      => $controlSource,
                'node_paired'         => $nodePaired,
                'node_connected'      => $nodeConnected,
                'node_battery_low'    => $nodeBatteryLow,
                'device_online'       => (bool) $deviceOnline,
                'device_last_seen_at' => $deviceLastSeenAt,
            ],
            'node_battery'     => $battery,
        ]);
    }

    public function storeSensorLog(Request $request, int $roomId): JsonResponse
    {
        $validated = $request->validate([
            'temperature' => 'required|numeric',
            'humidity'    => 'required|numeric',
        ]);
        $log = SensorLog::create(array_merge($validated, ['room_id' => $roomId]));
        return response()->json(['message' => 'Sensor log stored.', 'data' => $log], 201);
    }

    public function storeOccupancyLog(Request $request, int $roomId): JsonResponse
    {
        $validated = $request->validate([
            'people_count' => 'required|integer|min:0',
        ]);
        $log = OccupancyLog::create(array_merge($validated, ['room_id' => $roomId]));
        return response()->json(['message' => 'Occupancy log stored.', 'data' => $log], 201);
    }

    /**
     * Export node battery history to CSV file (retensi 7 hari).
     * Endpoint: GET /api/v1/rooms/{roomId}/battery/export-csv?days=7
     */
    public function exportBatteryCsv(Request $request, int $roomId): StreamedResponse
    {
        $user = auth('sanctum')->user();
        if ($user) {
            $room = Room::where('user_id', $user->id)->findOrFail($roomId);
        } else {
            $room = Room::findOrFail($roomId);
        }

        $days = (int) $request->query('days', 7);
        if ($days <= 0) {
            $days = 7;
        }

        $since = Carbon::now()->subDays($days);
        $filename = "battery_room_{$roomId}_" . now()->format('Ymd_His') . ".csv";

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () use ($roomId, $since) {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 for Excel compatibility
            fputs($handle, "\xEF\xBB\xBF");

            // Header CSV
            fputcsv($handle, [
                'id',
                'room_id',
                'timestamp_utc',
                'timestamp_wib',
                'valid',
                'percent',
                'millivolts',
                'voltage_v'
            ]);

            $logs = NodeBatteryLog::where('room_id', $roomId)
                ->where('created_at', '>=', $since)
                ->orderBy('created_at', 'asc');

            foreach ($logs->cursor() as $log) {
                $utcTs = Carbon::parse($log->created_at)->utc()->toIso8601ZuluString();
                $wibTs = Carbon::parse($log->created_at)->timezone('Asia/Jakarta')->format('Y-m-d H:i:s');

                fputcsv($handle, [
                    $log->id,
                    $log->room_id,
                    $utcTs,
                    $wibTs,
                    $log->valid ? 'TRUE' : 'FALSE',
                    $log->percent !== null ? $log->percent : '',
                    $log->millivolts !== null ? $log->millivolts : '',
                    $log->voltage !== null ? number_format($log->voltage, 3, '.', '') : ''
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Get paginated battery logs in JSON format.
     */
    public function batteryLogs(Request $request, int $roomId): JsonResponse
    {
        $user = auth('sanctum')->user();
        if ($user) {
            $room = Room::where('user_id', $user->id)->findOrFail($roomId);
        } else {
            $room = Room::findOrFail($roomId);
        }

        $days = (int) $request->query('days', 7);
        $since = Carbon::now()->subDays($days > 0 ? $days : 7);

        $limit = min((int) $request->query('limit', 100), 1000);

        $logs = NodeBatteryLog::where('room_id', $roomId)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

        return response()->json([
            'status' => 'success',
            'data'   => $logs,
        ]);
    }

    public function sensorLogs(int $roomId): JsonResponse
    {
        $logs = SensorLog::where('room_id', $roomId)->latest('created_at')->limit(100)->get();
        return response()->json(['data' => $logs]);
    }

    public function occupancyLogs(int $roomId): JsonResponse
    {
        $logs = OccupancyLog::where('room_id', $roomId)->latest('created_at')->limit(100)->get();
        return response()->json(['data' => $logs]);
    }

    public function controlLogs(int $roomId): JsonResponse
    {
        $logs = ControlLog::where('room_id', $roomId)->latest('created_at')->limit(100)->get();
        return response()->json(['data' => $logs]);
    }

    public function acLogs(int $roomId): JsonResponse
    {
        $logs = AcLog::where('room_id', $roomId)->latest('created_at')->limit(100)->get();
        return response()->json(['data' => $logs]);
    }

    /**
     * Export sensor, AC, or occupancy history to CSV.
     * Endpoint: GET /api/v1/rooms/{roomId}/export-csv?type=sensor&date=today
     */
    public function exportCsv(Request $request, int $roomId): StreamedResponse
    {
        $user = auth('sanctum')->user();
        if ($user) {
            $room = Room::where('user_id', $user->id)->findOrFail($roomId);
        } else {
            $room = Room::findOrFail($roomId);
        }

        $type = $request->query('type', 'sensor');
        $dateParam = $request->query('date');
        $daysParam = $request->query('days');

        $todayWib = Carbon::today('Asia/Jakarta');
        $dateSuffix = date('Y-m-d');

        $fileName = 'room_' . $roomId . '_' . $type . '_logs_' . $dateSuffix . '.csv';
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () use ($type, $roomId, $dateParam, $daysParam, $todayWib) {
            $file = fopen('php://output', 'w');
            // BOM UTF-8 for Excel
            fputs($file, "\xEF\xBB\xBF");

            if ($type === 'sensor') {
                fputcsv($file, ['ID', 'Room_ID', 'Timestamp_UTC', 'Timestamp_WIB', 'Temperature_C', 'Humidity_RH']);
                $query = SensorLog::where('room_id', $roomId);
                if ($dateParam === 'today') {
                    $query->where('created_at', '>=', $todayWib);
                } elseif (!empty($dateParam)) {
                    $start = Carbon::parse($dateParam, 'Asia/Jakarta')->startOfDay();
                    $end = Carbon::parse($dateParam, 'Asia/Jakarta')->endOfDay();
                    $query->whereBetween('created_at', [$start, $end]);
                } elseif (!empty($daysParam)) {
                    $query->where('created_at', '>=', Carbon::now()->subDays((int)$daysParam));
                }
                $query->orderBy('created_at', 'asc')->chunk(500, function ($logs) use ($file) {
                    foreach ($logs as $log) {
                        $utcTs = Carbon::parse($log->created_at)->utc()->toIso8601ZuluString();
                        $wibTs = Carbon::parse($log->created_at)->timezone('Asia/Jakarta')->format('Y-m-d H:i:s');
                        fputcsv($file, [$log->id, $log->room_id, $utcTs, $wibTs, $log->temperature, $log->humidity]);
                    }
                });
            } elseif ($type === 'ac') {
                fputcsv($file, ['ID', 'Room_ID', 'Timestamp_UTC', 'Timestamp_WIB', 'Power', 'Setpoint', 'Mode', 'AC_Mode', 'Fan_Speed', 'Swing', 'Control_Source', 'Node_Paired', 'Node_Connected', 'Node_Battery_Low']);
                $query = AcLog::where('room_id', $roomId);
                if ($dateParam === 'today') {
                    $query->where('created_at', '>=', $todayWib);
                } elseif (!empty($dateParam)) {
                    $start = Carbon::parse($dateParam, 'Asia/Jakarta')->startOfDay();
                    $end = Carbon::parse($dateParam, 'Asia/Jakarta')->endOfDay();
                    $query->whereBetween('created_at', [$start, $end]);
                } elseif (!empty($daysParam)) {
                    $query->where('created_at', '>=', Carbon::now()->subDays((int)$daysParam));
                }
                $query->orderBy('created_at', 'asc')->chunk(500, function ($logs) use ($file) {
                    foreach ($logs as $log) {
                        $utcTs = Carbon::parse($log->created_at)->utc()->toIso8601ZuluString();
                        $wibTs = Carbon::parse($log->created_at)->timezone('Asia/Jakarta')->format('Y-m-d H:i:s');
                        fputcsv($file, [
                            $log->id,
                            $log->room_id,
                            $utcTs,
                            $wibTs,
                            $log->power,
                            $log->setpoint,
                            $log->mode,
                            $log->ac_mode,
                            $log->fan_speed,
                            $log->swing,
                            $log->control_source,
                            $log->node_paired ? 'TRUE' : 'FALSE',
                            $log->node_connected ? 'TRUE' : 'FALSE',
                            $log->node_battery_low ? 'TRUE' : 'FALSE',
                        ]);
                    }
                });
            } elseif ($type === 'occupancy') {
                fputcsv($file, ['ID', 'Room_ID', 'Timestamp_UTC', 'Timestamp_WIB', 'People_Count']);
                $query = OccupancyLog::where('room_id', $roomId);
                if ($dateParam === 'today') {
                    $query->where('created_at', '>=', $todayWib);
                } elseif (!empty($dateParam)) {
                    $start = Carbon::parse($dateParam, 'Asia/Jakarta')->startOfDay();
                    $end = Carbon::parse($dateParam, 'Asia/Jakarta')->endOfDay();
                    $query->whereBetween('created_at', [$start, $end]);
                } elseif (!empty($daysParam)) {
                    $query->where('created_at', '>=', Carbon::now()->subDays((int)$daysParam));
                }
                $query->orderBy('created_at', 'asc')->chunk(500, function ($logs) use ($file) {
                    foreach ($logs as $log) {
                        $utcTs = Carbon::parse($log->created_at)->utc()->toIso8601ZuluString();
                        $wibTs = Carbon::parse($log->created_at)->timezone('Asia/Jakarta')->format('Y-m-d H:i:s');
                        fputcsv($file, [$log->id, $log->room_id, $utcTs, $wibTs, $log->people_count]);
                    }
                });
            }

            fclose($file);
        }, 200, $headers);
    }

}
