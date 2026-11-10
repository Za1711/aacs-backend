<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * Register FCM device token untuk user yang login.
     * Jika token pernah dimiliki akun lain, kepemilikan dialihkan ke user saat ini.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'       => 'required|string|max:512',
            'device_name' => 'nullable|string|max:100',
        ]);

        $token = DeviceToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id'      => auth()->id(),
                'device_name'  => $validated['device_name'] ?? null,
                'last_used_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Token perangkat berhasil didaftarkan.',
            'data'    => $token,
        ], 201);
    }

    /**
     * Hapus / unregister FCM device token milik user yang sedang login.
     */
    public function destroy(string $token): JsonResponse
    {
        $decoded = urldecode($token);

        $deleted = DeviceToken::where('user_id', auth()->id())
            ->where(function ($q) use ($token, $decoded) {
                $q->where('token', $token)->orWhere('token', $decoded);
            })
            ->delete();

        return response()->json(['message' => 'Token perangkat berhasil dihapus.'], 200);
    }
}
