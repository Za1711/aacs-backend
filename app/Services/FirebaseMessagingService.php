<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use RuntimeException;

class FirebaseMessagingService
{
    public function __construct(
        protected ?Messaging $messaging = null
    ) {}

    public function getMessaging(): ?Messaging
    {
        if ($this->messaging) {
            return $this->messaging;
        }

        $credPath = config('firebase.credentials');

        if (!$credPath || !file_exists($credPath)) {
            Log::error('[FCM] File credential service-account tidak ditemukan.', [
                'configured_path' => $credPath,
            ]);
            return null;
        }

        // Validasi metadata JSON credential
        try {
            $jsonContent = file_get_contents($credPath);
            $credData = json_decode($jsonContent, true);
            $projectId = $credData['project_id'] ?? null;
            $expectedProject = config('firebase.project_id', 'aacs-fc1a6');

            if ($projectId !== $expectedProject) {
                Log::warning("[FCM CRITICAL] Credential project_id mismatch! Expected: [{$expectedProject}], Found: [{$projectId}] in [{$credPath}].");
            }
        } catch (\Throwable $e) {
            Log::warning("[FCM] Gagal membaca metadata JSON credential: {$e->getMessage()}");
        }

        try {
            $factory = (new Factory())->withServiceAccount($credPath);
            if ($projectId = config('firebase.project_id')) {
                $factory = $factory->withProjectId($projectId);
            }
            return $factory->createMessaging();
        } catch (\Throwable $e) {
            Log::error('[FCM] Gagal inisialisasi Firebase Factory.', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Kirim push notification ke seluruh perangkat milik user tertentu.
     *
     * @param  int    $userId
     * @param  string $title
     * @param  string $body
     * @param  array  $data   Semua value dikonversi ke string
     * @return array  Hasil pengiriman ['sent' => int, 'failed' => int, 'message_ids' => array, 'errors' => array]
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): array
    {
        $roomId = $data['room_id'] ?? 'unknown';
        $type   = $data['type'] ?? 'unknown';

        $tokens = DeviceToken::where('user_id', $userId)
            ->pluck('token')
            ->toArray();

        $tokenCount = count($tokens);

        if ($tokenCount === 0) {
            Log::warning("[FCM] User {$userId} memiliki 0 device token terdaftar. Push notification [{$type}] untuk room {$roomId} TIDAK dikirim.");
            return [
                'sent'        => 0,
                'failed'      => 0,
                'message_ids' => [],
                'errors'      => ['User has 0 registered device tokens.'],
            ];
        }

        $messaging = $this->getMessaging();

        if (! $messaging) {
            Log::error("[FCM] Firebase Messaging tidak tersedia (credential missing atau invalid). Push [{$type}] untuk user {$userId} gagal diproses.");
            throw new RuntimeException("Firebase credentials missing or invalid. Check path: " . config('firebase.credentials'));
        }

        Log::info("[FCM] Memanggil Firebase Messaging untuk user {$userId} (room: {$roomId}, type: {$type}) dengan {$tokenCount} device token.");

        $notification = Notification::create($title, $body);

        $androidConfig = AndroidConfig::fromArray([
            'priority' => 'high',
            'notification' => [
                'channel_id' => 'aacs_alerts',
                'sound'      => 'default',
            ],
        ]);

        // Seluruh value pada data payload wajib string
        $stringData = array_map('strval', $data);

        $results = [
            'sent'        => 0,
            'failed'      => 0,
            'message_ids' => [],
            'errors'      => [],
        ];

        $invalidTokens = [];

        foreach ($tokens as $token) {
            $tokenPrefix = substr($token, 0, 10) . '...' . substr($token, -6);

            try {
                if (method_exists(CloudMessage::class, 'withTarget')) {
                    $message = CloudMessage::withTarget('token', $token);
                } else {
                    $message = CloudMessage::new()->withToken($token);
                }

                $message = $message
                    ->withNotification($notification)
                    ->withData($stringData)
                    ->withAndroidConfig($androidConfig);

                $sendResult = $messaging->send($message);

                $messageId = is_array($sendResult) ? ($sendResult['name'] ?? json_encode($sendResult)) : (string) $sendResult;

                Log::info("[FCM] Push FCM BERHASIL dikirim.", [
                    'user_id'      => $userId,
                    'room_id'      => $roomId,
                    'type'         => $type,
                    'token_prefix' => $tokenPrefix,
                    'message_id'   => $messageId,
                ]);

                $results['sent']++;
                $results['message_ids'][] = $messageId;

            } catch (NotFound $e) {
                Log::warning("[FCM] Token perangkat sudah tidak terdaftar (NotFound). Ditandai untuk dihapus.", [
                    'user_id'      => $userId,
                    'token_prefix' => $tokenPrefix,
                ]);
                $invalidTokens[] = $token;
                $results['failed']++;
                $results['errors'][] = "Token {$tokenPrefix} not found: {$e->getMessage()}";

            } catch (InvalidArgument $e) {
                Log::warning("[FCM] Format token perangkat tidak valid (InvalidArgument). Ditandai untuk dihapus.", [
                    'user_id'      => $userId,
                    'token_prefix' => $tokenPrefix,
                    'error'        => $e->getMessage(),
                ]);
                $invalidTokens[] = $token;
                $results['failed']++;
                $results['errors'][] = "Token {$tokenPrefix} invalid: {$e->getMessage()}";

            } catch (\Throwable $e) {
                $errClass = get_class($e);
                $errMsg   = $e->getMessage();

                Log::error("[FCM] Gagal mengirim push FCM ke perangkat.", [
                    'user_id'         => $userId,
                    'room_id'         => $roomId,
                    'type'            => $type,
                    'token_prefix'    => $tokenPrefix,
                    'exception_class' => $errClass,
                    'error_message'   => $errMsg,
                ]);

                if (str_contains($errMsg, 'SENDER_ID_MISMATCH') || str_contains($errMsg, 'senderId') || str_contains($errMsg, 'SenderIdMismatch')) {
                    Log::critical("[FCM CRITICAL] SENDER_ID_MISMATCH terdeteksi! Service account credentials di backend tidak cocok dengan Firebase Project APK (aacs-fc1a6).");
                }

                $results['failed']++;
                $results['errors'][] = "{$errClass}: {$errMsg}";
            }
        }

        // Cleanup token invalid
        if (!empty($invalidTokens)) {
            DeviceToken::where('user_id', $userId)
                ->whereIn('token', $invalidTokens)
                ->delete();

            Log::info("[FCM] Token invalid dihapus dari database.", [
                'user_id' => $userId,
                'count'   => count($invalidTokens),
            ]);
        }

        return $results;
    }
}
