<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use App\Models\SensorLog;
use App\Models\AcLog;
use App\Models\OccupancyLog;
use App\Models\Room;
use App\Models\Device;
use App\Models\Notification;
use App\Models\NodeBatteryStatus;
use App\Models\NodeBatteryLog;
use App\Models\NodeTelemetryStatus;
use App\Models\NodeTelemetryLog;
use App\Models\RemoteTelemetryStatus;
use App\Models\RemoteTelemetryLog;
use App\Models\NodeLinkSample;
use App\Models\RemoteDeviceStatus;
use App\Jobs\SendFcmNotificationJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MqttSubscribeCommand extends Command
{
    protected $signature = 'mqtt:subscribe';
    protected $description = 'Subscribe to HiveMQ to receive telemetry and AC status from ESP32';

    public function handle()
    {
        $this->info('Starting MQTT Subscriber...');

        $host     = config('mqtt.host');
        $port     = config('mqtt.port');
        $username = config('mqtt.username');
        $password = config('mqtt.password');

        $mqtt = new MqttClient($host, $port, 'laravel-subscriber-' . uniqid());

        $settings = (new ConnectionSettings())
            ->setUsername($username)
            ->setPassword($password)
            ->setUseTls(true)
            ->setTlsVerifyPeer(false)
            ->setTlsVerifyPeerName(false);

        $mqtt->connect($settings, true);
        $this->info('Connected to HiveMQ Cloud.');

        $mqtt->subscribe('room/+/telemetry', function (string $topic, string $message) {
            $this->handleTelemetry($topic, $message);
        });

        $mqtt->subscribe('room/+/remote/telemetry', function (string $topic, string $message) {
            $this->handleRemoteTelemetry($topic, $message);
        });

        $mqtt->subscribe('room/+/ac/status', function (string $topic, string $message) {
            $this->handleAcStatus($topic, $message);
        });

        $mqtt->subscribe('room/+/occupancy', function (string $topic, string $message) {
            $this->handleOccupancy($topic, $message);
        });

        $mqtt->subscribe('room/+/pairing/candidate', function (string $topic, string $message) {
            $this->handlePairingCandidate($topic, $message);
        });

        $mqtt->subscribe('room/+/node/battery', function (string $topic, string $message) {
            $this->handleNodeBattery($topic, $message);
        });

        $mqtt->subscribe('room/+/node/telemetry', function (string $topic, string $message) {
            $this->handleNodeTelemetry($topic, $message);
        });

        $mqtt->subscribe('room/+/node/link-metrics', function (string $topic, string $message) {
            $this->handleNodeLinkMetrics($topic, $message);
        }, 0);

        $mqtt->subscribe('room/+/notification', function (string $topic, string $message) {
            $this->handleNotification($topic, $message);
        });

        $this->info('Subscribed to all topics including room/+/notification, room/+/node/battery, room/+/node/telemetry, room/+/node/link-metrics');
        $this->info('Listening for messages... (Press Ctrl+C to stop)');

        $mqtt->loop(true);
        $mqtt->disconnect();
    }

    public function extractRoomId(string $topic): ?int
    {
        preg_match('/room\/(\d+)\//', $topic, $matches);
        return isset($matches[1]) ? (int) $matches[1] : null;
    }

    public function handleTelemetry(string $topic, string $message): void
    {
        $roomId = $this->extractRoomId($topic);
        if (!$roomId || !Room::find($roomId)) return;

        $data = json_decode($message, true);
        if (!$data || !is_array($data)) return;

        // 1. Baca dan normalisasi source
        if (isset($data['source'])) {
            $rawSource = strtoupper(trim((string)$data['source']));
            if ($rawSource === 'NODE' || $rawSource === 'USER_NODE') {
                $source = 'USER_NODE';
            } elseif ($rawSource === 'REMOTE') {
                $source = 'REMOTE';
            } else {
                $source = 'UNKNOWN';
            }
        } else {
            $source = 'UNKNOWN';
        }

        // 2. Validasi numeric dan finite untuk suhu & kelembaban
        $suhu = $data['suhu'] ?? null;
        $kelembaban = $data['kelembaban'] ?? null;
        $payloadValid = isset($data['valid']) ? (bool)$data['valid'] : true;

        $isSuhuValid = is_numeric($suhu) && is_finite($suhu) && $suhu >= -40 && $suhu <= 125;
        $isHumValid = is_numeric($kelembaban) && is_finite($kelembaban) && $kelembaban >= 0 && $kelembaban <= 100;

        $isValid = $payloadValid && $isSuhuValid && $isHumValid;
        $temp = $isValid ? round((float) $suhu, 2) : null;
        $hum  = $isValid ? round((float) $kelembaban, 2) : null;

        SensorLog::create([
            'room_id'     => $roomId,
            'temperature' => $temp,
            'humidity'    => $hum,
            'source'      => $source,
            'valid'       => $isValid,
        ]);

        Device::where('room_id', $roomId)
            ->where('device_type', 'ESP32')
            ->where('is_paired', true)
            ->update([
                'last_ping' => now(),
                'status'    => 'ONLINE',
            ]);

        if ($this->output) {
            $this->info("Saved telemetry for room $roomId: Temp " . ($temp ?? 'null') . " (Source: {$source})");
        }
    }

    public function handleRemoteTelemetry(string $topic, string $message): bool
    {
        $roomId = $this->extractRoomId($topic);
        if (!$roomId) {
            Log::warning("[REMOTE TELEMETRY] Gagal parse room_id dari topic: {$topic}");
            return false;
        }

        $room = Room::find($roomId);
        if (!$room) {
            Log::warning("[REMOTE TELEMETRY] Room {$roomId} tidak ditemukan.");
            return false;
        }

        $data = json_decode($message, true);
        if (!is_array($data)) {
            Log::warning("[REMOTE TELEMETRY] Payload bukan JSON valid untuk room {$roomId}");
            return false;
        }

        if (($data['version'] ?? null) !== 1) {
            Log::warning("[REMOTE TELEMETRY] Versi tidak didukung untuk room {$roomId}: " . ($data['version'] ?? 'null'));
            return false;
        }

        if (strtoupper(trim((string)($data['source'] ?? ''))) !== 'REMOTE') {
            Log::warning("[REMOTE TELEMETRY] Source bukan REMOTE untuk room {$roomId}: " . ($data['source'] ?? 'null'));
            return false;
        }

        if (!isset($data['valid']) || !is_bool($data['valid'])) {
            Log::warning("[REMOTE TELEMETRY] Field 'valid' bukan boolean untuk room {$roomId}");
            return false;
        }

        $receivedAt = Carbon::now('UTC');
        $isValid = false;
        $temp = null;
        $hum = null;

        if ($data['valid'] === true) {
            $suhu = $data['suhu'] ?? null;
            $kelembaban = $data['kelembaban'] ?? null;

            $isSuhuValid = is_numeric($suhu) && is_finite($suhu) && $suhu >= -40 && $suhu <= 125;
            $isHumValid = is_numeric($kelembaban) && is_finite($kelembaban) && $kelembaban >= 0 && $kelembaban <= 100;

            if ($isSuhuValid && $isHumValid) {
                $isValid = true;
                $temp = round((float) $suhu, 2);
                $hum = round((float) $kelembaban, 2);
            }
        }

        RemoteTelemetryStatus::updateOrCreate(
            ['room_id' => $roomId],
            [
                'valid'       => $isValid,
                'temperature' => $temp,
                'humidity'    => $hum,
                'received_at' => $receivedAt,
            ]
        );

        RemoteTelemetryLog::create([
            'room_id'     => $roomId,
            'valid'       => $isValid,
            'temperature' => $temp,
            'humidity'    => $hum,
            'received_at' => $receivedAt,
            'created_at'  => $receivedAt,
        ]);

        if ($this->output) {
            $this->info("Saved remote telemetry for room $roomId: Temp " . ($temp ?? 'null') . ", Hum " . ($hum ?? 'null') . ", Valid " . ($isValid ? 'true' : 'false'));
        }

        return true;
    }

    public function handleNodeBattery(string $topic, string $message): bool
    {
        $roomId = $this->extractRoomId($topic);
        if (!$roomId) {
            Log::warning("[NODE BATTERY] Tidak bisa parse room_id dari topic: {$topic}");
            return false;
        }

        $room = Room::find($roomId);
        if (!$room) {
            Log::warning("[NODE BATTERY] Room {$roomId} tidak ditemukan.");
            return false;
        }

        $data = json_decode($message, true);
        if (!is_array($data)) {
            Log::warning("[NODE BATTERY] Payload bukan JSON object untuk room {$roomId}");
            return false;
        }

        // 1. version wajib integer dan nilainya tepat 1
        if (!array_key_exists('version', $data) || !is_int($data['version']) || $data['version'] !== 1) {
            Log::warning("[NODE BATTERY] Version invalid untuk room {$roomId}");
            return false;
        }

        // 2. valid wajib boolean
        if (!array_key_exists('valid', $data) || !is_bool($data['valid'])) {
            Log::warning("[NODE BATTERY] Field 'valid' bukan boolean untuk room {$roomId}");
            return false;
        }

        $isValid = $data['valid'];
        $receivedAt = Carbon::now('UTC');

        if ($isValid === true) {
            // millivolts wajib integer 2500–4350
            if (!array_key_exists('millivolts', $data) || !is_int($data['millivolts']) || $data['millivolts'] < 2500 || $data['millivolts'] > 4350) {
                Log::warning("[NODE BATTERY] Millivolts invalid untuk room {$roomId}: " . ($data['millivolts'] ?? 'missing'));
                return false;
            }

            // percent wajib integer 0–100
            if (!array_key_exists('percent', $data) || !is_int($data['percent']) || $data['percent'] < 0 || $data['percent'] > 100) {
                Log::warning("[NODE BATTERY] Percent invalid untuk room {$roomId}: " . ($data['percent'] ?? 'missing'));
                return false;
            }

            NodeBatteryStatus::updateOrCreate(
                ['room_id' => $roomId],
                [
                    'valid'       => true,
                    'millivolts'  => $data['millivolts'],
                    'percent'     => $data['percent'],
                    'received_at' => $receivedAt,
                ]
            );

            // Simpan riwayat baterai setiap 5 detik (retensi 7 hari)
            NodeBatteryLog::create([
                'room_id'    => $roomId,
                'valid'      => true,
                'millivolts' => $data['millivolts'],
                'percent'    => $data['percent'],
                'created_at' => $receivedAt,
            ]);

            // Jika tegangan di atas batas low (> 3300 mV), bersihkan flag low lama di AcLog
            if ($data['millivolts'] > 3300) {
                $lastAcLog = AcLog::where('room_id', $roomId)->latest('created_at')->first();
                if ($lastAcLog && $lastAcLog->node_battery_low) {
                    $lastAcLog->update(['node_battery_low' => false]);
                }
            }

            if ($this->output) { $this->info("Saved valid node battery for room {$roomId}: {$data['percent']}% ({$data['millivolts']}mV)"); }
            Log::info("[NODE BATTERY] Saved valid node battery for room {$roomId}: {$data['percent']}% ({$data['millivolts']}mV)");
            return true;
        } else {
            // valid=false: millivolts dan percent wajib null
            if (!array_key_exists('millivolts', $data) || !is_null($data['millivolts']) ||
                !array_key_exists('percent', $data) || !is_null($data['percent'])) {
                Log::warning("[NODE BATTERY] Field millivolts/percent harus null ketika valid=false untuk room {$roomId}");
                return false;
            }

            NodeBatteryStatus::updateOrCreate(
                ['room_id' => $roomId],
                [
                    'valid'       => false,
                    'millivolts'  => null,
                    'percent'     => null,
                    'received_at' => $receivedAt,
                ]
            );

            // Simpan riwayat baterai unavailable
            NodeBatteryLog::create([
                'room_id'    => $roomId,
                'valid'      => false,
                'millivolts' => null,
                'percent'    => null,
                'created_at' => $receivedAt,
            ]);

            // Saat valid=false diterima, status low lama harus langsung dibersihkan
            $lastAcLog = AcLog::where('room_id', $roomId)->latest('created_at')->first();
            if ($lastAcLog && $lastAcLog->node_battery_low) {
                $lastAcLog->update(['node_battery_low' => false]);
            }

            if ($this->output) { $this->info("Saved unavailable node battery for room {$roomId}"); }
            Log::info("[NODE BATTERY] Saved unavailable node battery for room {$roomId}");
            return true;
        }
    }

    public function handleNodeTelemetry(string $topic, string $message): bool
    {
        $roomId = $this->extractRoomId($topic);
        if (!$roomId) {
            Log::warning("[NODE TELEMETRY] Tidak bisa parse room_id dari topic: {$topic}");
            return false;
        }

        $room = Room::find($roomId);
        if (!$room) {
            Log::warning("[NODE TELEMETRY] Room {$roomId} tidak ditemukan.");
            return false;
        }

        $data = json_decode($message, true);
        if (!is_array($data)) {
            Log::warning("[NODE TELEMETRY] Payload bukan JSON object untuk room {$roomId}");
            return false;
        }

        $receivedAt = Carbon::now('UTC');

        $suhu = $data['suhu'] ?? null;
        $kelembaban = $data['kelembaban'] ?? null;

        $isValid = ($data['valid'] ?? false) === true
            && is_numeric($suhu) && is_finite($suhu) && $suhu >= -40 && $suhu <= 125
            && is_numeric($kelembaban) && is_finite($kelembaban) && $kelembaban >= 0 && $kelembaban <= 100;

        $temp = $isValid ? round((float) $suhu, 2) : null;
        $hum  = $isValid ? round((float) $kelembaban, 2) : null;

        NodeTelemetryStatus::updateOrCreate(
            ['room_id' => $roomId],
            [
                'valid'       => $isValid,
                'temperature' => $temp,
                'humidity'    => $hum,
                'received_at' => $receivedAt,
            ]
        );

        // Setiap payload dicatat ke node_telemetry_logs (baik valid maupun invalid)
        NodeTelemetryLog::create([
            'room_id'     => $roomId,
            'temperature' => $temp,
            'humidity'    => $hum,
            'valid'       => $isValid,
            'received_at' => $receivedAt,
            'created_at'  => $receivedAt,
        ]);

        if ($this->output) {
            $statusStr = $isValid ? "Valid ({$temp}C, {$hum}%)" : "Invalid/Null";
            $this->info("Saved node telemetry for room {$roomId}: {$statusStr}");
        }
        Log::info("[NODE TELEMETRY] Saved node telemetry for room {$roomId}: " . ($isValid ? "Temp {$temp}" : "Invalid/Null"));

        return true;
    }

    public function handleAcStatus(string $topic, string $message): void
    {
        $roomId = $this->extractRoomId($topic);
        $room   = Room::find($roomId);
        if (!$roomId || !$room) return;

        $data = json_decode($message, true);
        if (!$data) return;

        // Heartbeat RemoteDeviceStatus: selalu perbarui last_seen_at dengan waktu server UTC
        RemoteDeviceStatus::updateOrCreate(
            ['room_id' => $roomId],
            ['last_seen_at' => Carbon::now('UTC')]
        );

        Device::where('room_id', $roomId)
            ->where('device_type', 'ESP32')
            ->where('is_paired', true)
            ->update([
                'last_ping' => now(),
                'status'    => 'ONLINE',
            ]);

        // Konversi power boolean ke ON/OFF
        $power = isset($data['power'])
            ? (($data['power'] === true || $data['power'] === 'ON') ? 'ON' : 'OFF')
            : 'OFF';

        // Baca mode dari payload firmware dulu, fallback ke log terakhir
        $lastLog  = AcLog::where('room_id', $roomId)->where('ac_brand', $room->ac_brand)->latest()->first();
        $mode     = $data['mode']      ?? ($lastLog?->mode      ?? 'MANUAL');
        $ac_mode  = $data['ac_mode']   ?? ($lastLog?->ac_mode   ?? 'COOL');
        $fan      = $data['fan_speed'] ?? ($lastLog?->fan_speed ?? 'MEDIUM');
        $swing    = $data['swing']     ?? ($lastLog?->swing     ?? 'AUTO');

        // Parse 4 status fields: canonical USER_NODE / REMOTE. Nilai legacy NODE dinormalisasi ke USER_NODE.
        $rawControl = isset($data['control_source']) ? strtoupper(trim((string)$data['control_source'])) : null;
        if ($rawControl === 'USER_NODE' || $rawControl === 'NODE') {
            $controlSource = 'USER_NODE';
        } elseif ($rawControl === 'REMOTE') {
            $controlSource = 'REMOTE';
        } else {
            $lastControl = $lastLog?->control_source;
            $controlSource = ($lastControl === 'NODE' || $lastControl === 'USER_NODE') ? 'USER_NODE' : 'REMOTE';
        }

        $nodePaired = isset($data['node_paired'])
            ? (bool) $data['node_paired']
            : ($lastLog?->node_paired ?? false);

        $nodeConnected = isset($data['node_connected'])
            ? (bool) $data['node_connected']
            : ($lastLog?->node_connected ?? false);

        // Jangan menyalin node_battery_low=true jika pembacaan baterai terkini invalid atau stale
        $batteryStatus = NodeBatteryStatus::where('room_id', $roomId)->first();
        $timeout = config('mqtt.node_battery_timeout', Room::NODE_BATTERY_TIMEOUT_SECONDS);
        $isFresh = $batteryStatus && $batteryStatus->received_at && (now()->getTimestamp() - $batteryStatus->received_at->getTimestamp() <= $timeout);
        $isBatteryLowCondition = $isFresh && ($batteryStatus->valid === true) && ($batteryStatus->millivolts !== null) && ($batteryStatus->millivolts <= 3300);

        if (!$isBatteryLowCondition) {
            $nodeBatteryLow = false;
        } else {
            $nodeBatteryLow = isset($data['node_battery_low'])
                ? (bool) $data['node_battery_low']
                : ($lastLog?->node_battery_low ?? false);
        }

        AcLog::create([
            'room_id'          => $roomId,
            'ac_brand'         => $room->ac_brand,
            'power'            => $power,
            'setpoint'         => $data['suhu'] ?? 24,
            'mode'             => $mode,
            'ac_mode'          => $ac_mode,
            'fan_speed'        => $fan,
            'swing'            => $swing,
            'control_source'   => $controlSource,
            'node_paired'      => $nodePaired,
            'node_connected'   => $nodeConnected,
            'node_battery_low' => $nodeBatteryLow,
        ]);
        if ($this->output) {
            $this->info("Saved AC status for room $roomId (mode: $mode, power: $power, control_source: $controlSource)");
        }
    }

    protected function handleOccupancy(string $topic, string $message): void
    {
        $roomId = $this->extractRoomId($topic);
        if (!$roomId || !Room::find($roomId)) return;
        $count = (int) $message;
        if ($this->output) {
            $this->info("Menerima occupancy untuk room $roomId: $count people (disimpan oleh Python)");
        }
    }

    protected function handlePairingCandidate(string $topic, string $message): void
    {
        $roomId = $this->extractRoomId($topic);
        if (!$roomId) return;

        $payload = json_decode($message, true);
        $mac     = $payload['mac'] ?? null;
        if (!$mac) return;

        $cacheKey   = "pairing_candidates_room_{$roomId}";
        $candidates = Cache::get($cacheKey, []);
        $nowIso     = Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z');
        $macUpper   = strtoupper($mac);

        $found = false;
        foreach ($candidates as &$item) {
            if (strcasecmp($item['mac'], $macUpper) === 0) {
                $item['discovered_at'] = $nowIso;
                break;
            }
        }
        if (!$found) {
            $candidates[] = ['mac' => $macUpper, 'discovered_at' => $nowIso];
        }

        Cache::put($cacheKey, $candidates, 60);
        if ($this->output) {
            $this->info("Kandidat pairing room {$roomId} tertangkap: {$macUpper}");
        }
    }

    public function handleNotification(string $topic, string $message): bool
    {
        $roomId = $this->extractRoomId($topic);
        if (!$roomId) {
            Log::warning("[NOTIFICATION] Tidak bisa parse room_id dari topic: {$topic}");
            return false;
        }

        $room = Room::find($roomId);
        if (!$room) {
            Log::warning("[NOTIFICATION] Room {$roomId} tidak ditemukan.");
            return false;
        }

        $data = json_decode($message, true);
        if (!is_array($data)) {
            Log::warning("[NOTIFICATION] Payload invalid (bukan JSON) dari topic {$topic}");
            return false;
        }

        $type = $data['type'] ?? null;

        if (!in_array($type, ['ROOM_EMPTY', 'DEVICE_FACTORY_RESET', 'NODE_OFFLINE', 'NODE_BATTERY_LOW'], true)) {
            Log::warning("[NOTIFICATION] Type tidak dikenal: {$type}");
            return false;
        }

        // Jangan memproses event room lain berdasarkan room_id dari body jika berbeda dari topic
        if (isset($data['room_id']) && (string) $data['room_id'] !== (string) $roomId) {
            Log::warning("[NOTIFICATION] Mismatch room_id body ({$data['room_id']}) != topic ({$roomId}), event ditolak.");
            return false;
        }

        // ==========================================
        // HANDLER 1: DEVICE_FACTORY_RESET
        // ==========================================
        if ($type === 'DEVICE_FACTORY_RESET') {
            $windowTs = intdiv(time(), 30) * 30;
            $dedupKey = hash('sha256', "room_{$roomId}_DEVICE_FACTORY_RESET_{$windowTs}");

            $alreadyProcessed = Notification::where('room_id', $roomId)
                ->where('type', 'DEVICE_FACTORY_RESET')
                ->where('mqtt_dedup_key', $dedupKey)
                ->exists();

            if ($alreadyProcessed) {
                Log::info("[NOTIFICATION] Duplicate DEVICE_FACTORY_RESET ignored for room {$roomId}.");
                return true;
            }

            $receivedAt = Carbon::now();
            $savedNotifications = [];

            try {
                DB::transaction(function () use ($room, $roomId, $type, $data, $receivedAt, $dedupKey, &$savedNotifications) {
                    // Update semua remote ESP32 milik room menjadi is_paired=false dan status='OFFLINE'
                    Device::where('room_id', $roomId)
                        ->where(function ($q) {
                            $q->where('device_type', 'ESP32')
                              ->orWhereNull('device_type');
                        })
                        ->update([
                            'is_paired' => false,
                            'status'    => 'OFFLINE',
                        ]);

                    // Ambil user pemilik room
                    $users = collect();
                    if ($room->user_id) {
                        $users = \App\Models\User::where('id', $room->user_id)->get();
                    }

                    $title = 'Remote AC di-reset';
                    $body  = $data['message'] ?? 'Perangkat dilepas otomatis. Silakan pairing kembali.';

                    foreach ($users as $user) {
                        $notification = Notification::create([
                            'user_id'          => $user->id,
                            'room_id'          => $roomId,
                            'type'             => $type,
                            'title'            => $title,
                            'message'          => $body,
                            'people_count'     => 0,
                            'duration_seconds' => 0,
                            'mqtt_received_at' => $receivedAt,
                            'mqtt_dedup_key'   => $dedupKey,
                        ]);

                        $savedNotifications[] = [
                            'user_id'         => $user->id,
                            'notification_id' => $notification->id,
                            'title'           => $title,
                            'body'            => $body,
                        ];
                    }
                });

                Log::info("[NOTIFICATION] DEVICE_FACTORY_RESET berhasil diproses untuk room {$roomId}.");

                // Dispatch FCM push via queue job (di luar transaction)
                foreach ($savedNotifications as $saved) {
                    SendFcmNotificationJob::dispatch(
                        $saved['user_id'],
                        $saved['title'],
                        $saved['body'],
                        [
                            'type'            => 'DEVICE_FACTORY_RESET',
                            'room_id'         => (string) $roomId,
                            'title'           => $saved['title'],
                            'body'            => $saved['body'],
                            'notification_id' => (string) $saved['notification_id'],
                        ]
                    );

                    Log::info("[FCM] Push job dispatched for DEVICE_FACTORY_RESET.", [
                        'user_id'         => $saved['user_id'],
                        'notification_id' => $saved['notification_id'],
                    ]);
                }

                return true;
            } catch (\Throwable $e) {
                Log::error("[NOTIFICATION] Gagal memproses DEVICE_FACTORY_RESET: {$e->getMessage()}");
                return false;
            }
        }

        // ==========================================
        // HANDLER 2: ROOM_EMPTY
        // ==========================================
        if ($type === 'ROOM_EMPTY') {
            $peopleCount     = isset($data['people_count']) ? (int) $data['people_count'] : null;
            $durationSeconds = isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null;
            $msg             = $data['message'] ?? null;

            if ($peopleCount !== 0) {
                Log::warning("[NOTIFICATION] people_count harus 0, dapat: {$peopleCount}");
                return false;
            }
            if (!is_int($durationSeconds) || $durationSeconds <= 0) {
                Log::warning("[NOTIFICATION] duration_seconds tidak valid: {$durationSeconds}");
                return false;
            }
            if (!is_string($msg) || strlen($msg) > 500) {
                Log::warning("[NOTIFICATION] message tidak valid.");
                return false;
            }

            // Dedup key: hash berdasarkan room_id + type + window 30 detik
            $windowTs  = intdiv(time(), 30) * 30;
            $dedupKey  = hash('sha256', "room_{$roomId}_ROOM_EMPTY_{$windowTs}");

            $receivedAt = Carbon::now();

            $users = collect();
            if ($room->user_id) {
                $users = \App\Models\User::where('id', $room->user_id)->get();
            }

            if ($users->isEmpty()) {
                if ($this->output) { $this->info("[NOTIFICATION] Room {$roomId} tidak punya user, skip."); }
                return true;
            }

            // Backend defense: Cek status AC terakhir untuk room ini
            $latestAc = AcLog::where('room_id', $roomId)->latest('created_at')->first();
            $acPowerIsOn = $latestAc && $latestAc->power === 'ON';

            $savedNotifications = [];

            try {
                DB::transaction(function () use ($users, $roomId, $type, $peopleCount, $durationSeconds, $receivedAt, $dedupKey, &$savedNotifications) {
                    foreach ($users as $user) {
                        $alreadyExists = Notification::where('user_id', $user->id)
                            ->where('mqtt_dedup_key', $dedupKey)
                            ->exists();

                        if ($alreadyExists) {
                            Log::info("[NOTIFICATION] Duplikat terdeteksi untuk user {$user->id}, skip.");
                            continue;
                        }

                        $notification = Notification::create([
                            'user_id'          => $user->id,
                            'room_id'          => $roomId,
                            'type'             => $type,
                            'title'            => 'Ruangan Kosong',
                            'message'          => 'Ruangan tidak mendeteksi pengguna selama 5 menit.',
                            'people_count'     => $peopleCount,
                            'duration_seconds' => $durationSeconds,
                            'mqtt_received_at' => $receivedAt,
                            'mqtt_dedup_key'   => $dedupKey,
                        ]);

                        $savedNotifications[] = [
                            'user_id'         => $user->id,
                            'notification_id' => $notification->id,
                        ];
                    }
                });

                if ($this->output) { $this->info("[NOTIFICATION] ROOM_EMPTY tersimpan untuk room {$roomId}."); }

                if (!$acPowerIsOn) {
                    Log::info("[NOTIFICATION] Push notification ROOM_EMPTY disupresi untuk room {$roomId} karena power AC tidak bernilai ON (" . ($latestAc?->power ?? 'NO_LOG') . ").");
                } else {
                    foreach ($savedNotifications as $saved) {
                        SendFcmNotificationJob::dispatch(
                            $saved['user_id'],
                            'Ruangan Kosong',
                            'Ruangan tidak mendeteksi pengguna selama 5 menit.',
                            [
                                'type'            => 'ROOM_EMPTY',
                                'room_id'         => (string) $roomId,
                                'notification_id' => (string) $saved['notification_id'],
                            ]
                        );

                        Log::info("[FCM] Push job dispatched for ROOM_EMPTY.", [
                            'user_id'         => $saved['user_id'],
                            'notification_id' => $saved['notification_id'],
                        ]);
                    }
                }

                return true;
            } catch (\Throwable $e) {
                Log::error("[NOTIFICATION] Gagal menyimpan notifikasi ROOM_EMPTY: {$e->getMessage()}");
                return false;
            }
        }

        // ==========================================
        // HANDLER 3: NODE_OFFLINE
        // ==========================================
        if ($type === 'NODE_OFFLINE') {
            $confirmSeconds = max(
                60,
                (int) config('aacs.node_offline_confirm_seconds', 60)
            );
            $minOfflineMs   = $confirmSeconds * 1000;

            // 1. Validasi offline_for_ms jika tersedia (firmware terbaru)
            if (array_key_exists('offline_for_ms', $data)) {
                $offlineForMs = $data['offline_for_ms'];
                if (!is_int($offlineForMs) || $offlineForMs < $minOfflineMs) {
                    Log::info("[NOTIFICATION] Event NODE_OFFLINE dini atau tidak valid diabaikan untuk room {$roomId}. offline_for_ms=" . json_encode($offlineForMs) . " (minimal {$minOfflineMs} ms).");
                    return true;
                }
            }

            // 2. Periksa bukti kontak Node terbaru (kompatibilitas firmware lama & perlindungan event tertunda)
            $isRecentContact = function ($timestamp) use ($confirmSeconds): bool {
                if (!$timestamp) {
                    return false;
                }
                $carbon = $timestamp instanceof Carbon ? $timestamp : Carbon::parse($timestamp);
                $diff = now()->getTimestamp() - $carbon->getTimestamp();
                return $diff >= -5 && $diff < $confirmSeconds;
            };

            // Sumber kontak 1: NodeTelemetryStatus.received_at
            $telemetryStatus = NodeTelemetryStatus::where('room_id', $roomId)->first();
            if ($telemetryStatus && $isRecentContact($telemetryStatus->received_at)) {
                Log::info("[NOTIFICATION] Event NODE_OFFLINE diabaikan untuk room {$roomId}: terdapat bukti kontak NodeTelemetryStatus terbaru ({$telemetryStatus->received_at}).");
                return true;
            }

            // Sumber kontak 2: NodeBatteryStatus.received_at
            $batteryStatus = NodeBatteryStatus::where('room_id', $roomId)->first();
            if ($batteryStatus && $isRecentContact($batteryStatus->received_at)) {
                Log::info("[NOTIFICATION] Event NODE_OFFLINE diabaikan untuk room {$roomId}: terdapat bukti kontak NodeBatteryStatus terbaru ({$batteryStatus->received_at}).");
                return true;
            }

            // Sumber kontak 3: NodeLinkSample.received_at (sampel gagal pun membuktikan Node masih aktif)
            $latestLinkSample = NodeLinkSample::where('room_id', $roomId)->latest('received_at')->first();
            if ($latestLinkSample && $isRecentContact($latestLinkSample->received_at)) {
                Log::info("[NOTIFICATION] Event NODE_OFFLINE diabaikan untuk room {$roomId}: terdapat bukti kontak NodeLinkSample terbaru ({$latestLinkSample->received_at}).");
                return true;
            }

            // Sumber kontak 4: AcLog terbaru secara keseluruhan (hanya dibatalkan jika status TERBARU bernilai node_connected=true)
            $latestAcLog = AcLog::where('room_id', $roomId)
                ->latest('created_at')
                ->first();
            if (
                $latestAcLog
                && $latestAcLog->node_connected === true
                && $isRecentContact($latestAcLog->created_at)
            ) {
                Log::info("[NOTIFICATION] Event NODE_OFFLINE diabaikan untuk room {$roomId}: AcLog terbaru masih berstatus node_connected=true ({$latestAcLog->created_at}).");
                return true;
            }

            // 3. Deduplikasi notifikasi
            $windowTs = intdiv(time(), 30) * 30;
            $dedupKey = hash('sha256', "room_{$roomId}_NODE_OFFLINE_{$windowTs}");

            $alreadyProcessed = Notification::where('room_id', $roomId)
                ->where('type', 'NODE_OFFLINE')
                ->where('mqtt_dedup_key', $dedupKey)
                ->exists();

            if ($alreadyProcessed) {
                Log::info("[NOTIFICATION] Duplicate NODE_OFFLINE ignored for room {$roomId}.");
                return true;
            }

            $receivedAt = Carbon::now();
            $title = 'Node Sensor Terputus';
            $body  = $data['message'] ?? 'Node sensor terputus, AC beralih menggunakan sensor remote.';
            $savedNotifications = [];

            try {
                DB::transaction(function () use ($room, $roomId, $type, $title, $body, $receivedAt, $dedupKey, &$savedNotifications) {
                    // Update status koneksi node untuk ruangan: node_connected=false, control_source='REMOTE'
                    // JANGAN ubah node_paired, JANGAN ubah is_paired remote ESP32
                    $lastLog = AcLog::where('room_id', $roomId)->where('ac_brand', $room->ac_brand)->latest()->first();
                    if ($lastLog) {
                        $lastLog->update([
                            'node_connected'   => false,
                            'node_battery_low' => false,
                            'control_source'   => 'REMOTE',
                            'node_paired'      => true,
                        ]);
                    } else {
                        AcLog::create([
                            'room_id'          => $roomId,
                            'ac_brand'         => $room->ac_brand,
                            'power'            => 'OFF',
                            'setpoint'         => 24,
                            'mode'             => 'MANUAL',
                            'ac_mode'          => 'COOL',
                            'fan_speed'        => 'MEDIUM',
                            'swing'            => 'AUTO',
                            'control_source'   => 'REMOTE',
                            'node_paired'      => true,
                            'node_connected'   => false,
                            'node_battery_low' => false,
                        ]);
                    }

                    $users = collect();
                    if ($room->user_id) {
                        $users = \App\Models\User::where('id', $room->user_id)->get();
                    }

                    foreach ($users as $user) {
                        $notification = Notification::create([
                            'user_id'          => $user->id,
                            'room_id'          => $roomId,
                            'type'             => $type,
                            'title'            => $title,
                            'message'          => $body,
                            'people_count'     => 0,
                            'duration_seconds' => 0,
                            'mqtt_received_at' => $receivedAt,
                            'mqtt_dedup_key'   => $dedupKey,
                        ]);

                        $savedNotifications[] = [
                            'user_id'         => $user->id,
                            'notification_id' => $notification->id,
                            'title'           => $title,
                            'body'            => $body,
                        ];
                    }
                });

                Log::info("[NOTIFICATION] NODE_OFFLINE berhasil diproses untuk room {$roomId}.");

                foreach ($savedNotifications as $saved) {
                    SendFcmNotificationJob::dispatch(
                        $saved['user_id'],
                        $saved['title'],
                        $saved['body'],
                        [
                            'type'            => 'NODE_OFFLINE',
                            'room_id'         => (string) $roomId,
                            'title'           => $saved['title'],
                            'body'            => $saved['body'],
                            'notification_id' => (string) $saved['notification_id'],
                        ]
                    );

                    Log::info("[FCM] Push job dispatched for NODE_OFFLINE.", [
                        'user_id'         => $saved['user_id'],
                        'notification_id' => $saved['notification_id'],
                    ]);
                }

                return true;
            } catch (\Throwable $e) {
                Log::error("[NOTIFICATION] Gagal memproses NODE_OFFLINE: {$e->getMessage()}");
                return false;
            }
        }

        // ==========================================
        // HANDLER 4: NODE_BATTERY_LOW
        // ==========================================
        if ($type === 'NODE_BATTERY_LOW') {
            // Validasi wajib:
            // 1. millivolts dan percent wajib numerik
            // 2. 2500 <= millivolts <= 3300
            // 3. 0 <= percent <= 20
            // 4. Node tidak boleh sedang offline
            // 5. valid tidak boleh false
            if (!isset($data['millivolts']) || !is_numeric($data['millivolts']) ||
                !isset($data['percent']) || !is_numeric($data['percent'])) {
                Log::warning("[NOTIFICATION] NODE_BATTERY_LOW ditolak: millivolts atau percent missing/bukan numerik untuk room {$roomId}");
                return false;
            }

            if (isset($data['valid']) && $data['valid'] !== true) {
                Log::warning("[NOTIFICATION] NODE_BATTERY_LOW ditolak: valid bernilai false untuk room {$roomId}");
                return false;
            }

            $mv = (int) $data['millivolts'];
            $pct = (int) $data['percent'];

            if ($mv < 2500 || $mv > 3300 || $pct < 0 || $pct > 20) {
                Log::warning("[NOTIFICATION] NODE_BATTERY_LOW ditolak: nilai di luar ambang low (mv: {$mv}, pct: {$pct}) untuk room {$roomId}");
                return false;
            }

            $lastAcLog = AcLog::where('room_id', $roomId)->latest('created_at')->first();
            if ($lastAcLog && $lastAcLog->node_connected === false) {
                Log::warning("[NOTIFICATION] NODE_BATTERY_LOW ditolak: Node sedang offline untuk room {$roomId}");
                return false;
            }

            $windowTs = intdiv(time(), 30) * 30;
            $dedupKey = hash('sha256', "room_{$roomId}_NODE_BATTERY_LOW_{$windowTs}");

            $alreadyProcessed = Notification::where('room_id', $roomId)
                ->where('type', 'NODE_BATTERY_LOW')
                ->where('mqtt_dedup_key', $dedupKey)
                ->exists();

            if ($alreadyProcessed) {
                Log::info("[NOTIFICATION] Duplicate NODE_BATTERY_LOW ignored for room {$roomId}.");
                return true;
            }

            $receivedAt = Carbon::now('UTC');
            $title = 'Baterai Node Rendah';
            $body  = $data['message'] ?? 'Baterai node sensor lemah. Segera isi ulang agar kontrol otomatis tetap akurat.';
            $savedNotifications = [];

            try {
                DB::transaction(function () use ($room, $roomId, $type, $title, $body, $receivedAt, $dedupKey, $mv, $pct, &$savedNotifications) {
                    // Update state baterai node di AcLog
                    $lastLog = AcLog::where('room_id', $roomId)->where('ac_brand', $room->ac_brand)->latest()->first();
                    if ($lastLog) {
                        $lastLog->update([
                            'node_battery_low' => true,
                            'control_source'   => 'REMOTE',
                        ]);
                    } else {
                        AcLog::create([
                            'room_id'          => $roomId,
                            'ac_brand'         => $room->ac_brand,
                            'power'            => 'OFF',
                            'setpoint'         => 24,
                            'mode'             => 'MANUAL',
                            'ac_mode'          => 'COOL',
                            'fan_speed'        => 'MEDIUM',
                            'swing'            => 'AUTO',
                            'control_source'   => 'REMOTE',
                            'node_paired'      => true,
                            'node_connected'   => true,
                            'node_battery_low' => true,
                        ]);
                    }

                    // Sinkronisasi NodeBatteryStatus
                    NodeBatteryStatus::updateOrCreate(
                        ['room_id' => $roomId],
                        [
                            'valid'       => true,
                            'millivolts'  => $mv,
                            'percent'     => $pct,
                            'received_at' => $receivedAt,
                        ]
                    );

                    $users = collect();
                    if ($room->user_id) {
                        $users = \App\Models\User::where('id', $room->user_id)->get();
                    }

                    foreach ($users as $user) {
                        $notification = Notification::create([
                            'user_id'          => $user->id,
                            'room_id'          => $roomId,
                            'type'             => $type,
                            'title'            => $title,
                            'message'          => $body,
                            'people_count'     => 0,
                            'duration_seconds' => 0,
                            'mqtt_received_at' => $receivedAt,
                            'mqtt_dedup_key'   => $dedupKey,
                        ]);

                        $savedNotifications[] = [
                            'user_id'         => $user->id,
                            'notification_id' => $notification->id,
                            'title'           => $title,
                            'body'            => $body,
                        ];
                    }
                });

                Log::info("[NOTIFICATION] NODE_BATTERY_LOW berhasil diproses untuk room {$roomId}.");

                foreach ($savedNotifications as $saved) {
                    SendFcmNotificationJob::dispatch(
                        $saved['user_id'],
                        $saved['title'],
                        $saved['body'],
                        [
                            'type'            => 'NODE_BATTERY_LOW',
                            'room_id'         => (string) $roomId,
                            'title'           => $saved['title'],
                            'body'            => $saved['body'],
                            'notification_id' => (string) $saved['notification_id'],
                        ]
                    );

                    Log::info("[FCM] Push job dispatched for NODE_BATTERY_LOW.", [
                        'user_id'         => $saved['user_id'],
                        'notification_id' => $saved['notification_id'],
                    ]);
                }

                return true;
            } catch (\Throwable $e) {
                Log::error("[NOTIFICATION] Gagal memproses NODE_BATTERY_LOW: {$e->getMessage()}");
                return false;
            }
        }

        return false;
    }

    /**
     * Handle link metrics payload from node sensor.
     * Topic: room/{room_id}/node/link-metrics
     */
    public function handleNodeLinkMetrics(string $topic, string $message): bool
    {
        $roomId = $this->extractRoomId($topic);
        if (!$roomId) {
            Log::warning("[NODE LINK METRICS] Gagal parse room_id dari topic: {$topic}");
            return false;
        }

        $room = Room::find($roomId);
        if (!$room) {
            Log::warning("[NODE LINK METRICS] Room {$roomId} tidak ditemukan.");
            return false;
        }

        $data = json_decode($message, true);
        if (!is_array($data)) {
            Log::warning("[NODE LINK METRICS] Payload bukan JSON valid untuk room {$roomId}");
            return false;
        }

        // 1. Version check
        if (($data['version'] ?? null) !== 1) {
            Log::warning("[NODE LINK METRICS] Versi tidak didukung untuk room {$roomId}: " . ($data['version'] ?? 'null'));
            return false;
        }

        // 2. Counters check (harus integer non-negatif)
        $requiredCounters = [
            'session_id',
            'telemetry_seq',
            'telemetry_packet_seq',
            'sent_total',
            'acked_total',
            'failed_total',
            'retries_total',
        ];

        foreach ($requiredCounters as $field) {
            if (!isset($data[$field]) || !is_int($data[$field]) || $data[$field] < 0) {
                Log::warning("[NODE LINK METRICS] Counter {$field} invalid untuk room {$roomId}");
                return false;
            }
        }

        // 3. telemetry_seq > 0 dan sent_total > 0
        if ($data['telemetry_seq'] <= 0 || $data['sent_total'] <= 0) {
            Log::warning("[NODE LINK METRICS] telemetry_seq atau sent_total <= 0 untuk room {$roomId}");
            return false;
        }

        // 4. Konsistensi counter: acked <= sent, failed <= sent, acked + failed == sent
        if ($data['acked_total'] > $data['sent_total']) {
            Log::warning("[NODE LINK METRICS] acked_total > sent_total untuk room {$roomId}");
            return false;
        }
        if ($data['failed_total'] > $data['sent_total']) {
            Log::warning("[NODE LINK METRICS] failed_total > sent_total untuk room {$roomId}");
            return false;
        }
        if (($data['acked_total'] + $data['failed_total']) !== $data['sent_total']) {
            Log::warning("[NODE LINK METRICS] acked_total + failed_total != sent_total untuk room {$roomId}");
            return false;
        }

        // 5. Booleans check (harus boolean murni, bukan string)
        if (!is_bool($data['telemetry_acked'] ?? null)) {
            Log::warning("[NODE LINK METRICS] telemetry_acked bukan boolean untuk room {$roomId}");
            return false;
        }
        if (!is_bool($data['telemetry_received'] ?? null)) {
            Log::warning("[NODE LINK METRICS] telemetry_received bukan boolean untuk room {$roomId}");
            return false;
        }
        if (!is_bool($data['battery_valid'] ?? null)) {
            Log::warning("[NODE LINK METRICS] battery_valid bukan boolean untuk room {$roomId}");
            return false;
        }

        $testComplete = isset($data['test_complete']) ? $data['test_complete'] : false;
        if (!is_bool($testComplete)) {
            Log::warning("[NODE LINK METRICS] test_complete bukan boolean untuk room {$roomId}");
            return false;
        }

        // 6. Validasi baterai
        $batteryMv = null;
        $batteryPercent = null;
        if ($data['battery_valid'] === true) {
            $mv  = $data['battery_mv'] ?? null;
            $pct = $data['battery_percent'] ?? null;

            if (!is_int($mv) || !is_int($pct) || $mv < 2500 || $mv > 4350 || $pct < 0 || $pct > 100) {
                Log::warning("[NODE LINK METRICS] Nilai baterai valid di luar batas untuk room {$roomId}");
                return false;
            }
            $batteryMv = $mv;
            $batteryPercent = $pct;
        }

        // Jika baterai valid <= 20%, otomatis test_complete
        if ($data['battery_valid'] && $batteryPercent !== null && $batteryPercent <= 20) {
            $testComplete = true;
        }

        // 7. Validasi RSSI
        $rssiDbm = null;
        if ($data['telemetry_received'] === true) {
            $rssi = $data['rssi_dbm'] ?? null;
            if (!is_int($rssi) || $rssi < -127 || $rssi > 0) {
                Log::warning("[NODE LINK METRICS] RSSI di luar rentang [-127, 0] untuk room {$roomId}");
                return false;
            }
            $rssiDbm = $rssi;
        } else {
            // Jika telemetry_received=false, rssi_dbm wajib null
            if (isset($data['rssi_dbm']) && $data['rssi_dbm'] !== null) {
                Log::warning("[NODE LINK METRICS] RSSI harus null jika telemetry_received=false untuk room {$roomId}");
                return false;
            }
            $rssiDbm = null;
        }

        // 7.1 Validasi sensor link metrics (backward-compatible)
        $sensorValid = false;
        $tempC = null;
        $humPct = null;

        if (isset($data['sensor_valid']) && is_bool($data['sensor_valid']) && $data['sensor_valid'] === true) {
            $telemetryReceived = (bool)($data['telemetry_received'] ?? false);
            $tc = $data['temperature_c'] ?? null;
            $hp = $data['humidity_percent'] ?? null;

            if ($telemetryReceived
                && is_numeric($tc) && is_finite($tc) && $tc >= -40 && $tc <= 125
                && is_numeric($hp) && is_finite($hp) && $hp >= 0 && $hp <= 100) {
                $sensorValid = true;
                $tempC = round((float) $tc, 2);
                $humPct = round((float) $hp, 2);
            }
        }

        // 8. Hitung ulang PDR di backend
        $pdr = round(100.0 * ($data['acked_total'] / $data['sent_total']), 3);

        // 9. Simpan menggunakan updateOrCreate berdasarkan (room_id, session_id, telemetry_seq)
        NodeLinkSample::updateOrCreate(
            [
                'room_id'       => $roomId,
                'session_id'    => $data['session_id'],
                'telemetry_seq' => $data['telemetry_seq'],
            ],
            [
                'telemetry_packet_seq' => $data['telemetry_packet_seq'],
                'sent_total'           => $data['sent_total'],
                'acked_total'          => $data['acked_total'],
                'failed_total'         => $data['failed_total'],
                'retries_total'        => $data['retries_total'],
                'pdr'                  => $pdr,
                'telemetry_acked'      => (bool) $data['telemetry_acked'],
                'telemetry_received'   => (bool) $data['telemetry_received'],
                'sensor_valid'         => $sensorValid,
                'temperature_c'        => $tempC,
                'humidity_percent'     => $humPct,
                'battery_valid'        => (bool) $data['battery_valid'],
                'battery_mv'           => $batteryMv,
                'battery_percent'      => $batteryPercent,
                'rssi_dbm'             => $rssiDbm,
                'test_complete'        => (bool) $testComplete,
                'received_at'          => Carbon::now('UTC'),
            ]
        );

        if ($this->output) {
            $this->info("Saved node link sample for room $roomId (session: {$data['session_id']}, seq: {$data['telemetry_seq']}, PDR: {$pdr}%)");
        }

        return true;
    }

}
