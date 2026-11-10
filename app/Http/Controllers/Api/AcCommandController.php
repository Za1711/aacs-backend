<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcLog;
use App\Models\Room;
use App\Services\MqttService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AcCommandController extends Controller
{
    public function __construct(
        protected MqttService $mqtt,
    ) {}

    /**
     * Guard: kembalikan 409 jika room tidak memiliki device.
     * Semua method yang mengirim perintah ke ESP32 wajib memanggil ini terlebih dahulu.
     */
    private function requireDevice(Room $room): ?JsonResponse
    {
        if ($room->devices()->doesntExist()) {
            return response()->json([
                'message' => 'Ruangan belum memiliki perangkat. Pasangkan remote ESP32 terlebih dahulu.',
            ], 409);
        }
        return null;
    }

    /**
     * Send a generic AC command via MQTT.
     */
    public function sendCommand(Request $request, int $roomId): JsonResponse
    {
        $room = Room::findOrFail($roomId);

        // Pastikan room milik user yang login
        if ($room->user_id !== auth()->id()) {
            return response()->json(['message' => 'Ruangan tidak ditemukan.'], 404);
        }

        // Guard: room harus punya device
        if ($guard = $this->requireDevice($room)) {
            return $guard;
        }

        // Baca value: prioritaskan 'value', fallback ke 'mode' untuk kompatibilitas lama
        $value   = $request->input('value', $request->input('mode'));
        $command = $request->input('command');

        // Validasi dasar
        $request->validate([
            'command' => 'required|string|max:50',
        ]);

        // Validasi khusus SET_MODE
        if ($command === 'SET_MODE') {
            validator(
                ['value' => $value],
                ['value' => ['required', Rule::in(['FUZZY', 'MANUAL'])]],
                [
                    'value.required' => 'Mode harus diisi (FUZZY atau MANUAL).',
                    'value.in'       => 'Mode hanya boleh FUZZY atau MANUAL.',
                ]
            )->validate();

            // Simpan mode ke device database
            $device = $room->devices()->first();
            if ($device) {
                $device->update(['mode' => $value]);
            }
        }

        // Publish ke MQTT — satu topic canonical
        $success = $this->mqtt->publishAcCommand($roomId, $command, $value ?? '');

        if (!$success) {
            return response()->json(['message' => 'Gagal mengirim perintah ke MQTT broker.'], 503);
        }

        // Perintah kalibrasi sensor & kontrol sumber — tidak perlu log AC
        if (in_array($command, ['SET_OFFSET_TEMP', 'SET_OFFSET_HUM', 'SET_SENSOR_SOURCE', 'SET_CONTROL_SOURCE', 'UNPAIR', 'SYNC_STATE', 'SET_BRAND'])) {
            return response()->json(['message' => "Perintah {$command} berhasil dikirim."]);
        }

        // Ambil log terakhir untuk mengisi nilai default
        $lastLog  = AcLog::where('room_id', $roomId)->where('ac_brand', $room->ac_brand)->latest()->first();
        $power    = $lastLog ? $lastLog->power     : 'OFF';
        $temp     = $lastLog ? $lastLog->setpoint  : 24;
        $mode     = $lastLog ? $lastLog->mode      : 'MANUAL';
        $ac_mode  = $lastLog ? $lastLog->ac_mode   : 'COOL';
        $fan      = $lastLog ? $lastLog->fan_speed : 'AUTO';
        $swing    = $lastLog ? $lastLog->swing     : 'AUTO';
        $rawControl = $lastLog?->control_source ?? 'REMOTE';
        $controlSource  = ($rawControl === 'NODE' || $rawControl === 'USER_NODE') ? 'USER_NODE' : 'REMOTE';
        $nodePaired     = $lastLog?->node_paired ?? false;
        $nodeConnected  = $lastLog?->node_connected ?? false;
        $nodeBatteryLow = $lastLog?->node_battery_low ?? false;

        match ($command) {
            'SET_POWER'                      => $power   = strtoupper($value),
            'SET_TEMP', 'SET_TEMPERATURE'    => [$temp   = (int) $value, $power = 'ON'],
            'SET_MODE'                       => $mode    = $value,
            'SET_AC_MODE'                    => [$ac_mode = $value, $power = 'ON'],
            'SET_FAN_SPEED'                  => [$fan    = $value, $power = 'ON'],
            'SET_SWING'                      => [$swing  = $value, $power = 'ON'],
            default                          => null,
        };

        AcLog::create([
            'room_id'          => $roomId,
            'ac_brand'         => $room->ac_brand,
            'power'            => $power,
            'setpoint'         => $temp,
            'mode'             => $mode,
            'ac_mode'          => $ac_mode,
            'fan_speed'        => $fan,
            'swing'            => $swing,
            'control_source'   => $controlSource,
            'node_paired'      => $nodePaired,
            'node_connected'   => $nodeConnected,
            'node_battery_low' => $nodeBatteryLow,
        ]);

        return response()->json([
            'message' => "Perintah {$command} berhasil dikirim.",
            'data'    => [
                'room_id' => $roomId,
                'command' => $command,
                'value'   => $value,
            ],
        ]);
    }

    /**
     * Shortcut: set AC temperature.
     */
    public function setTemperature(Request $request, int $roomId): JsonResponse
    {
        $room = Room::findOrFail($roomId);

        if ($room->user_id !== auth()->id()) {
            return response()->json(['message' => 'Ruangan tidak ditemukan.'], 404);
        }

        if ($guard = $this->requireDevice($room)) {
            return $guard;
        }

        $validated = $request->validate([
            'temperature' => 'required|integer|min:16|max:30',
        ]);

        $success = $this->mqtt->publishAcCommand($roomId, 'SET_TEMPERATURE', $validated['temperature']);

        if (!$success) {
            return response()->json(['message' => 'Gagal mengirim perintah suhu ke MQTT broker.'], 503);
        }

        $lastLog = AcLog::where('room_id', $roomId)->where('ac_brand', $room->ac_brand)->latest()->first();
        $rawControl = $lastLog?->control_source ?? 'REMOTE';
        $controlSource  = ($rawControl === 'NODE' || $rawControl === 'USER_NODE') ? 'USER_NODE' : 'REMOTE';

        AcLog::create([
            'room_id'          => $roomId,
            'ac_brand'         => $room->ac_brand,
            'power'            => $lastLog ? $lastLog->power     : 'ON',
            'setpoint'         => $validated['temperature'],
            'mode'             => 'MANUAL',
            'ac_mode'          => $lastLog ? $lastLog->ac_mode   : 'COOL',
            'fan_speed'        => $lastLog ? $lastLog->fan_speed : 'MEDIUM',
            'swing'            => $lastLog ? $lastLog->swing     : 'AUTO',
            'control_source'   => $controlSource,
            'node_paired'      => $lastLog?->node_paired ?? false,
            'node_connected'   => $lastLog?->node_connected ?? false,
            'node_battery_low' => $lastLog?->node_battery_low ?? false,
        ]);

        return response()->json([
            'message' => "Suhu AC diatur ke {$validated['temperature']}.",
            'data'    => ['room_id' => $roomId, 'temperature' => $validated['temperature']],
        ]);
    }

    /**
     * Shortcut: turn AC ON or OFF.
     */
    public function setPower(Request $request, int $roomId): JsonResponse
    {
        $room = Room::findOrFail($roomId);

        if ($room->user_id !== auth()->id()) {
            return response()->json(['message' => 'Ruangan tidak ditemukan.'], 404);
        }

        if ($guard = $this->requireDevice($room)) {
            return $guard;
        }

        $validated = $request->validate([
            'power' => 'required|string|in:ON,OFF',
        ]);

        $success = $this->mqtt->publishPowerCommand($roomId, $validated['power']);

        if (!$success) {
            return response()->json(['message' => 'Gagal mengirim perintah power ke MQTT broker.'], 503);
        }

        $lastLog = AcLog::where('room_id', $roomId)->where('ac_brand', $room->ac_brand)->latest()->first();
        $rawControl = $lastLog?->control_source ?? 'REMOTE';
        $controlSource  = ($rawControl === 'NODE' || $rawControl === 'USER_NODE') ? 'USER_NODE' : 'REMOTE';

        AcLog::create([
            'room_id'          => $roomId,
            'ac_brand'         => $room->ac_brand,
            'power'            => $validated['power'],
            'setpoint'         => $lastLog ? $lastLog->setpoint  : 24,
            'mode'             => $lastLog ? $lastLog->mode      : 'MANUAL',
            'ac_mode'          => $lastLog ? $lastLog->ac_mode   : 'COOL',
            'fan_speed'        => $lastLog ? $lastLog->fan_speed : 'AUTO',
            'swing'            => $lastLog ? $lastLog->swing     : 'AUTO',
            'control_source'   => $controlSource,
            'node_paired'      => $lastLog?->node_paired ?? false,
            'node_connected'   => $lastLog?->node_connected ?? false,
            'node_battery_low' => $lastLog?->node_battery_low ?? false,
        ]);

        return response()->json([
            'message' => "AC berhasil di-{$validated['power']}.",
            'data'    => ['room_id' => $roomId, 'power' => $validated['power']],
        ]);
    }
}
