<?php

namespace App\Jobs;

use App\Services\FirebaseMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendFcmNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $backoff = 5;

    public function __construct(
        public readonly int    $userId,
        public readonly string $title,
        public readonly string $body,
        public readonly array  $data = [],
    ) {}

    public function handle(FirebaseMessagingService $firebase): void
    {
        $roomId = $this->data['room_id'] ?? 'unknown';
        $type   = $this->data['type'] ?? 'unknown';

        Log::info('[FCM Job] Memulai eksekusi job push notification.', [
            'room_id' => $roomId,
            'user_id' => $this->userId,
            'type'    => $type,
            'title'   => $this->title,
        ]);

        $firebase->sendToUser(
            $this->userId,
            $this->title,
            $this->body,
            $this->data,
        );
    }

    /**
     * Kegagalan job dicatat dengan aman tanpa mencetak credential/token.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('[FCM Job] Job gagal setelah semua retry.', [
            'user_id' => $this->userId,
            'type'    => $this->data['type'] ?? 'unknown',
            'room_id' => $this->data['room_id'] ?? 'unknown',
            'error'   => $e->getMessage(),
        ]);
    }
}
