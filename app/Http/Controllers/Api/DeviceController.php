<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DeviceController extends Controller
{
    /**
     * List all devices, optionally filtered by room_id.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Device::where('user_id', auth()->id())->with('room');

        if ($request->has('room_id')) {
            $query->where('room_id', $request->input('room_id'));
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Show a single device.
     */
    public function show(int $id): JsonResponse
    {
        $device = Device::where('user_id', auth()->id())->with('room')->findOrFail($id);

        return response()->json(['data' => $device]);
    }

    /**
     * Register a new device (Admin only/Manual).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id'     => 'required|exists:rooms,id',
            'device_type' => 'required|in:ESP32,RASPBERRY_PI',
            'mac_address' => 'nullable|string|unique:devices,mac_address',
            'serial_number'=> 'nullable|string|unique:devices,serial_number',
            'ip_address'  => 'nullable|string',
        ]);

        // Pastikan room milik user
        $room = \App\Models\Room::where('user_id', auth()->id())->findOrFail($validated['room_id']);

        $validated['user_id'] = auth()->id();
        $device = Device::create($validated);

        return response()->json([
            'message' => 'Device berhasil didaftarkan.',
            'data'    => $device->load('room'),
        ], 201);
    }

    /**
     * Update a device.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $device = Device::where('user_id', auth()->id())->findOrFail($id);

        $validated = $request->validate([
            'room_id'     => 'sometimes|required|exists:rooms,id',
            'device_type' => 'sometimes|required|in:ESP32,RASPBERRY_PI',
            'mac_address' => 'nullable|string|unique:devices,mac_address,' . $id,
            'ip_address'  => 'nullable|string',
            'status'      => 'sometimes|in:ONLINE,OFFLINE',
        ]);

        if (isset($validated['room_id'])) {
            \App\Models\Room::where('user_id', auth()->id())->findOrFail($validated['room_id']);
        }

        $device->update($validated);

        return response()->json([
            'message' => 'Device berhasil diperbarui.',
            'data'    => $device->fresh()->load('room'),
        ]);
    }

    /**
     * Delete a device.
     */
    public function destroy(int $id): JsonResponse
    {
        $device = Device::where('user_id', auth()->id())->findOrFail($id);
        $device->delete();

        return response()->json(['message' => 'Device berhasil dihapus.']);
    }

    /**
     * Pair a device using serial number (from Bluetooth connection).
     */
    public function pair(Request $request): JsonResponse
    {
        $request->validate([
            'serial_number' => 'required|string',
            'room_id'       => 'required|exists:rooms,id',
        ]);

        // Pastikan ruangan milik user
        \App\Models\Room::where('user_id', auth()->id())->findOrFail($request->room_id);

        // Cari device berdasarkan serial number
        // Jika belum ada, kita buatkan otomatis dan langsung assign ke room_id
        $device = Device::firstOrCreate(
            ['serial_number' => $request->serial_number],
            [
                'device_type' => 'ESP32', 
                'room_id' => $request->room_id,
                'user_id' => auth()->id()
            ] 
        );

        if ($device->is_paired && $device->user_id !== auth()->id()) {
            return response()->json(['message' => 'Device ini sudah dipasangkan dengan akun lain.'], 403);
        }

        $device->update([
            'user_id'   => auth()->id(),
            'room_id'   => $request->room_id,
            'is_paired' => true,
        ]);

        // --- AUTO-FOLLOW LOGIC ---
        \App\Models\Device::where('user_id', auth()->id())
            ->where('device_type', 'RASPBERRY_PI')
            ->update(['room_id' => $request->room_id]);


        return response()->json([
            'message' => 'Berhasil menghubungkan perangkat!',
            'data'    => $device->fresh()->load('room'),
        ]);
    }

    /**
     * Device heartbeat — update last_ping and set status ONLINE.
     */
    public function heartbeat(int $id): JsonResponse
    {
        $device = Device::findOrFail($id);

        $device->update([
            'status'    => 'ONLINE',
            'last_ping' => now(),
        ]);

        return response()->json([
            'message' => 'Heartbeat diterima.',
            'data'    => $device->fresh(),
        ]);
    }
     /**
     * Get active pairing candidates for a room (cached for 60 seconds).
     */
    public function getPairingCandidates(int $roomId)
    {
        $cacheKey = "pairing_candidates_room_{$roomId}";
        $candidates = Cache::get($cacheKey, []);

        return response()->json([
            'status' => 'success',
            'data' => [
                'candidates' => array_values($candidates),
            ],
        ], 200);
    }
}
