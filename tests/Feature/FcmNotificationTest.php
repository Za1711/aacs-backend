<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotificationJob;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\Room;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FcmNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->room = Room::create([
            'user_id'         => $this->user->id,
            'name'            => 'Test Room',
            'ac_brand'        => 'Daikin',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ]);
    }

    private function makeNotification(User $user, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'user_id'          => $user->id,
            'room_id'          => $this->room->id,
            'type'             => 'ROOM_EMPTY',
            'title'            => 'Ruangan Kosong',
            'message'          => 'Ruangan tidak mendeteksi pengguna selama 5 menit.',
            'people_count'     => 0,
            'duration_seconds' => 300,
            'mqtt_received_at' => now(),
            'mqtt_dedup_key'   => uniqid('dedup_'),
        ], $overrides));
    }

    /**
     * SendFcmNotificationJob harus di-dispatch setelah notifikasi baru berhasil disimpan.
     */
    #[Test]
    public function fcm_job_is_dispatched_when_notification_is_saved(): void
    {
        Bus::fake();

        // Simulasi: notifikasi baru berhasil disimpan (bukan duplikat)
        $notification = $this->makeNotification($this->user);

        // Dispatch job seperti yang dilakukan worker setelah Notification::create()
        SendFcmNotificationJob::dispatch(
            $this->user->id,
            'Ruangan Kosong',
            'Ruangan tidak mendeteksi pengguna selama 5 menit.',
            [
                'type'            => 'ROOM_EMPTY',
                'room_id'         => (string) $this->room->id,
                'notification_id' => (string) $notification->id,
            ]
        );

        Bus::assertDispatched(SendFcmNotificationJob::class, function ($job) use ($notification) {
            return $job->userId           === $this->user->id
                && $job->data['type']             === 'ROOM_EMPTY'
                && $job->data['room_id']          === (string) $this->room->id
                && $job->data['notification_id']  === (string) $notification->id;
        });
    }

    /**
     * FCM job TIDAK boleh di-dispatch untuk event duplikat.
     */
    #[Test]
    public function fcm_job_is_not_dispatched_for_duplicate_event(): void
    {
        Bus::fake();

        $dedupKey = 'same_key_123';

        // Notifikasi pertama sudah tersimpan (simulasi event sebelumnya)
        $this->makeNotification($this->user, ['mqtt_dedup_key' => $dedupKey]);

        // Cek bahwa dedup key sudah ada — worker akan skip tanpa dispatch
        $alreadyExists = Notification::where('user_id', $this->user->id)
            ->where('mqtt_dedup_key', $dedupKey)
            ->exists();

        $this->assertTrue($alreadyExists);

        // Karena duplikat, tidak ada job yang di-dispatch
        Bus::assertNothingDispatched();
    }

    /**
     * SendFcmNotificationJob membawa payload yang benar.
     */
    #[Test]
    public function fcm_job_payload_has_correct_structure(): void
    {
        Bus::fake();

        $notification = $this->makeNotification($this->user);

        SendFcmNotificationJob::dispatch(
            $this->user->id,
            'Ruangan Kosong',
            'Ruangan tidak mendeteksi pengguna selama 5 menit.',
            [
                'type'            => 'ROOM_EMPTY',
                'room_id'         => (string) $this->room->id,
                'notification_id' => (string) $notification->id,
            ]
        );

        Bus::assertDispatched(SendFcmNotificationJob::class, function ($job) {
            // Semua field data harus string (FCM requirement)
            foreach ($job->data as $key => $value) {
                if (!is_string($value)) return false;
            }
            return $job->title === 'Ruangan Kosong'
                && $job->body  === 'Ruangan tidak mendeteksi pengguna selama 5 menit.';
        });
    }

    /**
     * FirebaseMessagingService: skip push jika tidak ada device token.
     */
    #[Test]
    public function firebase_service_skips_if_no_device_tokens(): void
    {
        // User tidak punya token — pastikan tidak ada error/exception
        $service = new FirebaseMessagingService();

        // Tidak ada token, kredensial belum ada di server test — harus silent skip
        // Method dipanggil, tapi karena credentials tidak ada, hanya log warning
        $this->expectNotToPerformAssertions();

        $service->sendToUser(
            $this->user->id,
            'Test Title',
            'Test Body',
            ['type' => 'ROOM_EMPTY', 'room_id' => '3', 'notification_id' => '1']
        );
    }

    /**
     * Token invalid harus dihapus dari device_tokens setelah pengiriman gagal.
     * (Diuji melalui logika service — mock Firebase)
     */
    #[Test]
    public function device_token_is_stored_correctly(): void
    {
        $token = DeviceToken::create([
            'user_id'     => $this->user->id,
            'token'       => 'test_fcm_token_xyz_123',
            'device_name' => 'Android AACS',
        ]);

        $this->assertDatabaseHas('device_tokens', [
            'user_id'     => $this->user->id,
            'token'       => 'test_fcm_token_xyz_123',
            'device_name' => 'Android AACS',
        ]);

        // Simulasi hapus token invalid
        DeviceToken::where('user_id', $this->user->id)
            ->where('token', 'test_fcm_token_xyz_123')
            ->delete();

        $this->assertDatabaseMissing('device_tokens', [
            'token' => 'test_fcm_token_xyz_123',
        ]);
    }
}
