<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\Room;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Console\Command;

class TestFcmPushCommand extends Command
{
    protected $signature = 'fcm:test-push {--user=1} {--token=}';
    protected $description = 'Kirim test push notification FCM nyata ke perangkat user dan laporkan message ID';

    public function handle(FirebaseMessagingService $firebase): int
    {
        $this->info('====================================================');
        $this->info('         AACS FCM DIAGNOSTIC & TEST PUSH            ');
        $this->info('====================================================');

        // 1. Cek User dan Device Tokens
        $userId = (int) $this->option('user');
        $user = User::find($userId);

        if (!$user) {
            $this->error("[ERROR] User ID {$userId} tidak ditemukan di database!");
            return 1;
        }

        $rooms = Room::where('user_id', $userId)->pluck('name', 'id')->toArray();
        $this->line("1. Informasi User & Room:");
        $this->line("   - User ID       : {$user->id} ({$user->name} <{$user->email}>)");
        $this->line("   - Room Terkait  : " . (!empty($rooms) ? json_encode($rooms) : 'Tidak ada'));

        $specificToken = $this->option('token');
        if ($specificToken) {
            $tokens = DeviceToken::where('user_id', $userId)->where('token', $specificToken)->get();
        } else {
            $tokens = DeviceToken::where('user_id', $userId)->orderByDesc('id')->get();
        }

        $this->line("   - Total Token   : " . $tokens->count());

        if ($tokens->isEmpty()) {
            $this->warn("   [WARNING] User ID {$userId} tidak memiliki token FCM di tabel device_tokens.");
        } else {
            foreach ($tokens as $idx => $t) {
                $masked = substr($t->token, 0, 14) . '...' . substr($t->token, -8);
                $this->line("   - Token #{$t->id} [{$t->device_name}]: {$masked}");
                $this->line("     Created: {$t->created_at} | Updated: {$t->updated_at}");
            }
        }

        // 2. Cek File Credentials Service Account
        $credPath = config('firebase.credentials');
        $expectedProject = config('firebase.project_id', 'aacs-fc1a6');
        $this->line("\n2. File Credentials Service Account:");
        $this->line("   - Path Target   : {$credPath}");
        $this->line("   - Target Project: {$expectedProject}");

        if (!file_exists($credPath)) {
            $this->error("   [GAGAL] File credentials TIDAK DITEMUKAN di disk!");
            $this->warn("   -> ROOT CAUSE: FCM push tidak terkirim karena credentials file belum diunggah.");
            $this->warn("   -> Solusi: Unduh private key JSON dari Firebase Console (Project Settings -> Service accounts) untuk project '{$expectedProject}', lalu simpan di:");
            $this->line("      {$credPath}\n");
            return 1;
        }

        $this->info("   [OK] File credentials ditemukan (" . filesize($credPath) . " bytes).");

        try {
            $credData = json_decode(file_get_contents($credPath), true);
            $actualProject = $credData['project_id'] ?? 'UNKNOWN';
            $this->line("   - Project ID JSON : {$actualProject}");
            $this->line("   - Client Email    : " . ($credData['client_email'] ?? 'N/A'));

            if ($actualProject !== $expectedProject) {
                $this->error("   [CRITICAL] SENDER/PROJECT MISMATCH! JSON project_id '{$actualProject}' != target '{$expectedProject}'!");
                return 1;
            }
        } catch (\Throwable $e) {
            $this->error("   [GAGAL] JSON parse error: " . $e->getMessage());
            return 1;
        }

        // 3. Kirim Test Push Nyata
        $this->info("\n3. Mengirim Real FCM Push ke Google FCM Server...");
        $targetRoomId = !empty($rooms) ? (string) array_key_first($rooms) : '30';
        $title = "Test Push AACS";
        $body  = "Uji coba FCM nyata pada " . date('Y-m-d H:i:s');
        $data  = [
            'type'            => 'ROOM_EMPTY',
            'room_id'         => (string) $targetRoomId,
            'notification_id' => '999',
            'timestamp'       => (string) time(),
        ];

        try {
            $result = $firebase->sendToUser($userId, $title, $body, $data);

            $this->line("\n4. Hasil Pengiriman FCM:");
            $this->info("   - Sukses : {$result['sent']}");
            $this->line("   - Gagal  : {$result['failed']}");

            if (!empty($result['message_ids'])) {
                $this->info("   - Message IDs:");
                foreach ($result['message_ids'] as $mid) {
                    $this->line("     -> {$mid}");
                }
            }

            if (!empty($result['errors'])) {
                $this->warn("   - Errors:");
                foreach ($result['errors'] as $err) {
                    $this->error("     -> {$err}");
                }
            }

            return $result['sent'] > 0 ? 0 : 1;

        } catch (\Throwable $e) {
            $this->error("   [EXCEPTION] Gagal mengirim push: " . get_class($e) . " - " . $e->getMessage());
            return 1;
        }
    }
}
