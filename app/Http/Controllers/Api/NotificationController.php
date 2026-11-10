<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * List notifications milik user yang login.
     * Query param: ?unread_only=1
     */
    public function index(Request $request): JsonResponse
    {
        $query = Notification::where('user_id', auth()->id())
            ->with('room:id,name')
            ->orderByDesc('created_at');

        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        $notifications = $query->paginate(20)->through(function ($n) {
            return [
                'id'               => $n->id,
                'room_id'          => $n->room_id,
                'room_name'        => $n->room?->name,
                'type'             => $n->type,
                'title'            => $n->title,
                'message'          => $n->message,
                'people_count'     => $n->people_count,
                'duration_seconds' => $n->duration_seconds,
                'read_at'          => $n->read_at,
                'created_at'       => $n->created_at,
            ];
        });

        return response()->json($notifications);
    }

    /**
     * Tandai satu notification sebagai sudah dibaca.
     */
    public function markRead(int $id): JsonResponse
    {
        $notification = Notification::where('user_id', auth()->id())->findOrFail($id);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['message' => 'Notifikasi ditandai sudah dibaca.']);
    }

    /**
     * Tandai semua notification user sebagai sudah dibaca.
     */
    public function markAllRead(): JsonResponse
    {
        $count = Notification::where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => "{$count} notifikasi ditandai sudah dibaca."]);
    }
}
