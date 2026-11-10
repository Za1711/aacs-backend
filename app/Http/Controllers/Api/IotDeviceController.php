<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Device;

class IotDeviceController extends Controller
{
    public function myRoom(Request $request)
    {
        $device = $request->user();

        if (!$device instanceof Device) {
            return response()->json(['message' => 'Token tidak valid untuk endpoint IoT.'], 401);
        }

        if (!$device->tokenCan('iot:room-read')) {
            return response()->json(['message' => 'Token tidak memiliki izin iot:room-read.'], 403);
        }

        if ($device->device_type !== 'RASPBERRY_PI') {
            return response()->json(['message' => 'Hanya perangkat tipe RASPBERRY_PI yang diizinkan.'], 403);
        }

        // Refresh database state to ensure we get the latest room_id
        $device->refresh();

        if (!$device->room_id) {
            return response()->json(['message' => 'Perangkat belum dipasangkan ke ruangan manapun.'], 409);
        }

        $room = $device->room;

        $device->update([
            'last_ping' => now(),
            'status' => 'ONLINE'
        ]);

        return response()->json([
            'data' => [
                'device_id' => $device->id,
                'device_type' => $device->device_type,
                'room_id' => $room->id,
                'room_name' => $room->name,
                'occupancy_topic' => "room/{$room->id}/occupancy"
            ]
        ]);
    }
}
