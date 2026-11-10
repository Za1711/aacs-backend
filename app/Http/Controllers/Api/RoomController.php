<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoomController extends Controller
{
    /**
     * List all rooms with latest status data.
     * Hanya menghitung dan menyertakan remote ESP32 yang is_paired=true.
     */
    public function index(): JsonResponse
    {
        $rooms = Room::where('user_id', auth()->id())
            ->with(['devices' => fn ($query) => $query->where('is_paired', true)])
            ->withCount(['devices' => fn ($query) => $query->where('is_paired', true)])
            ->get()
            ->map(function (Room $room) {
                return $this->appendLatestStatus($room);
            });

        return response()->json(['data' => $rooms]);
    }

    /**
     * Show a single room with detailed relationships.
     * Hanya menyertakan remote ESP32 yang is_paired=true.
     */
    public function show(int $id): JsonResponse
    {
        $room = Room::where('user_id', auth()->id())
            ->with(['devices' => fn ($query) => $query->where('is_paired', true)])
            ->withCount(['devices' => fn ($query) => $query->where('is_paired', true)])
            ->findOrFail($id);

        return response()->json([
            'data' => $this->appendLatestStatus($room),
        ]);
    }

    /**
     * Create a new room.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'ac_brand'       => 'nullable|string|max:100',
            'target_temp_min' => 'nullable|integer|min:16|max:30',
            'target_temp_max' => 'nullable|integer|min:16|max:30',
        ]);

        $validated['user_id'] = auth()->id();
        $room = Room::create($validated);

        return response()->json([
            'message' => 'Ruangan berhasil ditambahkan.',
            'data'    => $room,
        ], 201);
    }

    /**
     * Update an existing room.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $room = Room::where('user_id', auth()->id())->findOrFail($id);

        $validated = $request->validate([
            'name'           => 'sometimes|required|string|max:255',
            'ac_brand'       => 'nullable|string|max:100',
            'target_temp_min' => 'nullable|integer|min:16|max:30',
            'target_temp_max' => 'nullable|integer|min:16|max:30',
        ]);
        
        $oldBrand = $room->ac_brand;

        $room->update($validated);
        
        // Jika brand AC berubah, beritahu ESP32 via MQTT dan sinkronisasi ke memori
        if (isset($validated['ac_brand']) && $validated['ac_brand'] !== $oldBrand) {
            $mqtt = app(\App\Services\MqttService::class);
            $newBrand = $validated['ac_brand'];
            
            $mqtt->publishAcCommand($room->id, 'SET_BRAND', $newBrand);

            // Ambil memori terakhir dari merk yang baru dipilih
            $lastLog = \App\Models\AcLog::where('room_id', $room->id)
                ->where('ac_brand', $newBrand)
                ->latest()->first();

            $power = $lastLog ? $lastLog->power : 'OFF';
            $setpoint = $lastLog ? $lastLog->setpoint : 24;
            $mode = $lastLog ? $lastLog->mode : 'MANUAL';
            $ac_mode = $lastLog ? $lastLog->ac_mode : 'COOL';
            $fan_speed = $lastLog ? $lastLog->fan_speed : 'AUTO';
            $swing = $lastLog ? $lastLog->swing : 'AUTO';

            $syncPayload = json_encode([
                'power' => $power,
                'setpoint' => $setpoint,
                'ac_mode' => $ac_mode,
                'fan_speed' => $fan_speed,
                'swing' => $swing
            ]);
            $mqtt->publishAcCommand($room->id, 'SYNC_STATE', $syncPayload);

            \App\Models\AcLog::create([
                'room_id' => $room->id,
                'ac_brand' => $newBrand,
                'power' => $power,
                'setpoint' => $setpoint,
                'mode' => $mode,
                'ac_mode' => $ac_mode,
                'fan_speed' => $fan_speed,
                'swing' => $swing,
            ]);
        }

        return response()->json([
            'message' => 'Ruangan berhasil diperbarui.',
            'data'    => $room->fresh(),
        ]);
    }

    /**
     * Delete a room safely.
     */
    public function destroy(int $id): JsonResponse
    {
        $room = Room::where('user_id', auth()->id())->findOrFail($id);

        DB::transaction(function () use ($room, $id) {
            $device = $room->devices()->first();

            if ($device) {
                try {
                    $mqtt = app(\App\Services\MqttService::class);
                    $mqtt->publishAcCommand($id, 'UNPAIR', true);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("[ROOM DELETE] Gagal kirim UNPAIR MQTT: {$e->getMessage()}");
                }

                $room->devices()->update(['room_id' => null, 'is_paired' => false, 'status' => 'OFFLINE']);
            }

            \Illuminate\Support\Facades\Cache::forget("pairing_candidates_room_{$id}");

            $room->delete();
        });

        return response()->json([
            'message' => 'Ruangan berhasil dihapus.',
        ]);
    }

    /**
     * Append latest sensor, occupancy, and AC status to a room.
     */
    protected function appendLatestStatus(Room $room): array
    {
        $data = $room->toArray();

        $data['latest_sensor'] = $room->sensorLogs()
            ->latest('created_at')
            ->first();

        $data['latest_occupancy'] = $room->occupancyLogs()
            ->latest('created_at')
            ->first();

        $data['latest_ac_status'] = $room->acLogs()
            ->latest('created_at')
            ->first();

        return $data;
    }

    /**
     * Unpair all devices from a room without deleting the room.
     */
    public function unpair(Request $request, int $id): JsonResponse
    {
        DB::beginTransaction();
        try {
            $room = Room::where('user_id', auth()->id())->lockForUpdate()->findOrFail($id);
            $devicesCount = $room->devices()->count();

            if ($devicesCount > 0) {
                try {
                    $mqtt = app(\App\Services\MqttService::class);
                    $mqtt->publishAcCommand($id, 'UNPAIR', true);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("[ROOM UNPAIR] Gagal kirim UNPAIR MQTT: {$e->getMessage()}");
                }

                // Unlink devices and mark them unpaired and OFFLINE
                $room->devices()->update([
                    'room_id'   => null,
                    'is_paired' => false,
                    'status'    => 'OFFLINE',
                ]);
            }

            // Cleanup transient data
            \App\Models\SensorLog::where('room_id', $id)->delete();
            \App\Models\AcLog::where('room_id', $id)->delete();
            \App\Models\OccupancyLog::where('room_id', $id)->delete();

            // Reset node battery & telemetry transient status
            \App\Models\RemoteDeviceStatus::where('room_id', $id)->delete();
            \App\Models\NodeBatteryStatus::where('room_id', $id)->delete();
            \App\Models\NodeTelemetryStatus::where('room_id', $id)->delete();
            \Illuminate\Support\Facades\Cache::forget("pairing_candidates_room_{$id}");

            DB::commit();

            return response()->json([
                'message' => 'Perangkat berhasil dilepas. Ruangan tetap tersedia.',
                'data' => [
                    'room_id' => (int) $id,
                    'devices_count' => 0,
                ]
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
