<?php

namespace Tests\Feature;

use App\Console\Commands\MqttSubscribeCommand;
use App\Jobs\SendFcmNotificationJob;
use App\Models\Device;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\Room;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeviceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Room $room;
    private Device $device;
    private MqttSubscribeCommand $mqttCommand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->room = Room::create([
            'user_id'         => $this->user->id,
            'name'            => 'Lab Utama',
            'ac_brand'        => 'Daikin',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ]);

        $this->device = Device::create([
            'room_id'       => $this->room->id,
            'user_id'       => $this->user->id,
            'device_type'   => 'ESP32',
            'serial_number' => 'ESP32-LIFECYCLE-001',
            'mac_address'   => '11:22:33:44:55:66',
            'status'        => 'ONLINE',
            'is_paired'     => true,
        ]);

        $this->mqttCommand = new MqttSubscribeCommand();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    // ── 1. User dapat register dan delete token miliknya + transfer ownership ───

    #[Test]
    public function user_can_register_and_delete_device_token_and_ownership_is_transferred(): void
    {
        // User pertama mendaftarkan token
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/device-tokens', [
                'token'       => 'fcm_token_sample_123',
                'device_name' => 'Pixel 8 Pro',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.token', 'fcm_token_sample_123')
            ->assertJsonPath('data.user_id', $this->user->id);

        $this->assertDatabaseHas('device_tokens', [
            'token'   => 'fcm_token_sample_123',
            'user_id' => $this->user->id,
        ]);

        // User kedua mendaftarkan token yang sama -> kepemilikan dialihkan
        $this->actingAs($this->otherUser, 'sanctum')
            ->postJson('/api/v1/device-tokens', [
                'token'       => 'fcm_token_sample_123',
                'device_name' => 'Galaxy S24',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('device_tokens', [
            'token'   => 'fcm_token_sample_123',
            'user_id' => $this->otherUser->id,
        ]);

        // User kedua menghapus token
        $this->actingAs($this->otherUser, 'sanctum')
            ->deleteJson('/api/v1/device-tokens/fcm_token_sample_123')
            ->assertStatus(200);

        $this->assertDatabaseMissing('device_tokens', [
            'token' => 'fcm_token_sample_123',
        ]);
    }

    // ── 2. Token tidak dapat dihapus user lain ───────────────────────────────

    #[Test]
    public function token_cannot_be_deleted_by_other_user(): void
    {
        DeviceToken::create([
            'user_id'     => $this->user->id,
            'token'       => 'user_private_token',
            'device_name' => 'User Device',
        ]);

        // User lain mencoba menghapus token milik User 1
        $this->actingAs($this->otherUser, 'sanctum')
            ->deleteJson('/api/v1/device-tokens/user_private_token')
            ->assertStatus(200);

        // Token milik User 1 harus tetap ada
        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $this->user->id,
            'token'   => 'user_private_token',
        ]);
    }

    // ── 3. Event reset mengubah device menjadi unpaired/OFFLINE dan room tidak terhapus ─

    #[Test]
    public function factory_reset_event_marks_device_unpaired_and_offline_and_keeps_room(): void
    {
        Bus::fake();

        $payload = json_encode([
            'type'    => 'DEVICE_FACTORY_RESET',
            'room_id' => (string) $this->room->id,
            'message' => 'Remote AC di-reset dan dilepas otomatis',
        ]);

        $result = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);

        $this->assertTrue($result);

        // Device harus menjadi is_paired=false dan status='OFFLINE'
        $freshDevice = $this->device->fresh();
        $this->assertFalse($freshDevice->is_paired);
        $this->assertEquals('OFFLINE', $freshDevice->status);

        // Room TIDAK boleh dihapus
        $this->assertDatabaseHas('rooms', ['id' => $this->room->id]);

        // Notification tersimpan di DB
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->user->id,
            'room_id' => $this->room->id,
            'type'    => 'DEVICE_FACTORY_RESET',
        ]);

        // FCM job di-dispatch
        Bus::assertDispatched(SendFcmNotificationJob::class, function ($job) {
            return $job->userId === $this->user->id
                && $job->data['type'] === 'DEVICE_FACTORY_RESET'
                && $job->data['room_id'] === (string) $this->room->id;
        });
    }

    // ── 4. Event reset idempotent ────────────────────────────────────────────

    #[Test]
    public function factory_reset_event_is_idempotent(): void
    {
        Bus::fake();

        Carbon::setTestNow(Carbon::parse('2026-10-17 10:00:00'));

        $payload = json_encode([
            'type'    => 'DEVICE_FACTORY_RESET',
            'room_id' => (string) $this->room->id,
            'message' => 'Remote AC di-reset',
        ]);

        // Kirim event pertama
        $res1 = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);
        $this->assertTrue($res1);

        // Kirim event kedua dengan payload identik dalam jendela waktu dedup
        $res2 = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);
        $this->assertTrue($res2);

        // Notifikasi hanya dibuat 1 kali
        $count = Notification::where('room_id', $this->room->id)
            ->where('type', 'DEVICE_FACTORY_RESET')
            ->count();
        $this->assertEquals(1, $count);

        // Job FCM hanya di-dispatch 1 kali
        Bus::assertDispatchedTimes(SendFcmNotificationJob::class, 1);
    }

    // ── 5. Topic/payload MQTT malformed diabaikan ────────────────────────────

    #[Test]
    public function malformed_mqtt_notification_topic_and_payload_are_ignored(): void
    {
        Bus::fake();

        // 1. Topic invalid (bukan angka room_id)
        $res1 = $this->mqttCommand->handleNotification('room/invalid_id/notification', '{"type":"DEVICE_FACTORY_RESET"}');
        $this->assertFalse($res1);

        // 2. Payload bukan JSON
        $res2 = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", 'invalid-json-string');
        $this->assertFalse($res2);

        // 3. Type tidak dikenal
        $res3 = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", json_encode(['type' => 'UNKNOWN_TYPE']));
        $this->assertFalse($res3);

        // Device tetap paired & ONLINE
        $freshDevice = $this->device->fresh();
        $this->assertTrue($freshDevice->is_paired);
        $this->assertEquals('ONLINE', $freshDevice->status);

        Bus::assertNothingDispatched();
    }

    // ── 6. Room ID body yang berbeda tidak boleh mengubah room lain ───────────

    #[Test]
    public function factory_reset_mismatched_body_room_id_is_rejected(): void
    {
        Bus::fake();

        $otherRoom = Room::create([
            'user_id'         => $this->otherUser->id,
            'name'            => 'Other Room',
            'ac_brand'        => 'Panasonic',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ]);

        $otherDevice = Device::create([
            'room_id'       => $otherRoom->id,
            'user_id'       => $this->otherUser->id,
            'device_type'   => 'ESP32',
            'serial_number' => 'ESP32-OTHER-002',
            'status'        => 'ONLINE',
            'is_paired'     => true,
        ]);

        // Topic menargetkan $this->room, tetapi body berisi $otherRoom->id
        $payload = json_encode([
            'type'    => 'DEVICE_FACTORY_RESET',
            'room_id' => (string) $otherRoom->id,
            'message' => 'Reset attempt with mismatch',
        ]);

        $result = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);
        $this->assertFalse($result);

        // Kedua device tidak boleh terpengaruh
        $this->assertTrue($this->device->fresh()->is_paired);
        $this->assertTrue($otherDevice->fresh()->is_paired);

        Bus::assertNothingDispatched();
    }

    // ── 7. latest-status mengembalikan device_paired=false dan tolak user lain ──

    #[Test]
    public function latest_status_returns_device_paired_boolean_and_rejects_other_user(): void
    {
        // Kondisi 1: Device sedang paired
        $resPaired = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $resPaired->assertStatus(200)
            ->assertJsonPath('data.device_paired', true);

        // Kondisi 2: Setelah device di-unpair / factory reset
        $this->device->update(['is_paired' => false, 'status' => 'OFFLINE']);

        $resUnpaired = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $resUnpaired->assertStatus(200)
            ->assertJsonPath('data.device_paired', false);

        // Kondisi 3: User lain mengakses -> 404
        $this->actingAs($this->otherUser, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status")
            ->assertStatus(404);
    }

    // ── 8. Room list/count tidak menghitung device unpaired ──────────────────

    #[Test]
    public function rooms_index_and_show_only_count_and_include_paired_devices(): void
    {
        // Device dibuat unpaired
        $this->device->update(['is_paired' => false, 'status' => 'OFFLINE']);

        // GET /api/v1/rooms
        $resIndex = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/rooms');

        $resIndex->assertStatus(200);
        $roomData = collect($resIndex->json('data'))->firstWhere('id', $this->room->id);
        $this->assertEquals(0, $roomData['devices_count']);
        $this->assertEmpty($roomData['devices']);

        // GET /api/v1/rooms/{id}
        $resShow = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}");

        $resShow->assertStatus(200)
            ->assertJsonPath('data.devices_count', 0);
        $this->assertEmpty($resShow->json('data.devices'));
    }

    // ── 9. Endpoint unpair menolak room milik user lain ──────────────────────

    #[Test]
    public function unpair_endpoint_rejects_other_users_room_with_404(): void
    {
        $this->actingAs($this->otherUser, 'sanctum')
            ->postJson("/api/v1/rooms/{$this->room->id}/unpair")
            ->assertStatus(404);

        // Device tetap paired
        $this->assertTrue($this->device->fresh()->is_paired);
    }

    // ── 10. Pengiriman FCM di-mock, termasuk cleanup token unregistered ───────

    #[Test]
    public function fcm_push_job_dispatched_and_unregistered_token_cleaned_up(): void
    {
        // Daftarkan token valid dan token unregistered
        DeviceToken::create([
            'user_id'     => $this->user->id,
            'token'       => 'token_unregistered_test',
            'device_name' => 'Old Phone',
        ]);
        DeviceToken::create([
            'user_id'     => $this->user->id,
            'token'       => 'token_valid_test',
            'device_name' => 'Current Phone',
        ]);

        // Mock Messaging instance
        $mockMessaging = Mockery::mock(Messaging::class);

        // Simulasi: token pertama throw NotFound (unregistered), token kedua sukses
        $mockMessaging->shouldReceive('send')
            ->andReturnUsing(function ($message) {
                $target = $message->jsonSerialize()['token'] ?? '';
                if ($target === 'token_unregistered_test') {
                    throw new NotFound('Requested entity was not found.');
                }
                return ['name' => 'projects/aacs-fc1a6/messages/msg_123'];
            });

        $service = new FirebaseMessagingService($mockMessaging);
        $service->sendToUser(
            $this->user->id,
            'Remote AC di-reset',
            'Perangkat dilepas otomatis.',
            ['type' => 'DEVICE_FACTORY_RESET', 'room_id' => (string) $this->room->id]
        );

        // Token unregistered harus dihapus dari database
        $this->assertDatabaseMissing('device_tokens', [
            'token' => 'token_unregistered_test',
        ]);

        // Token valid tetap dipertahankan
        $this->assertDatabaseHas('device_tokens', [
            'token' => 'token_valid_test',
        ]);
    }
}
