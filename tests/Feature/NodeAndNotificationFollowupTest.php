<?php

namespace Tests\Feature;

use App\Console\Commands\MqttSubscribeCommand;
use App\Jobs\SendFcmNotificationJob;
use App\Models\AcLog;
use App\Models\Device;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\Room;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NodeAndNotificationFollowupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Room $room;
    private Device $device;
    private MqttSubscribeCommand $mqttCommand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->room = Room::create([
            'user_id'         => $this->user->id,
            'name'            => 'Followup Test Room',
            'ac_brand'        => 'Daikin',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ]);

        $this->device = Device::create([
            'room_id'       => $this->room->id,
            'user_id'       => $this->user->id,
            'device_type'   => 'ESP32',
            'serial_number' => 'ESP32-FOLLOWUP-001',
            'status'        => 'ONLINE',
            'is_paired'     => true,
        ]);

        $this->mqttCommand = new MqttSubscribeCommand();
    }

    // ── 1. Queue Worker memproses FCM Job dengan Dual Payload & Channel ID ────

    #[Test]
    public function queue_worker_processes_fcm_job_and_calls_firebase_with_dual_payload_and_channel_id(): void
    {
        DeviceToken::create([
            'user_id'     => $this->user->id,
            'token'       => 'test_fcm_device_token_xyz',
            'device_name' => 'Android Phone',
        ]);

        $mockMessaging = Mockery::mock(Messaging::class);
        $mockMessaging->shouldReceive('send')
            ->once()
            ->with(Mockery::on(function (CloudMessage $msg) {
                $payload = $msg->jsonSerialize();
                return isset($payload['notification']['title'])
                    && $payload['notification']['title'] === 'Test Notification'
                    && isset($payload['notification']['body'])
                    && $payload['notification']['body'] === 'This is a test notification.'
                    && isset($payload['data']['type'])
                    && $payload['data']['type'] === 'ROOM_EMPTY'
                    && isset($payload['data']['room_id'])
                    && $payload['data']['room_id'] === (string) $this->room->id
                    && isset($payload['android']['priority'])
                    && $payload['android']['priority'] === 'high'
                    && isset($payload['android']['notification']['channel_id'])
                    && $payload['android']['notification']['channel_id'] === 'aacs_alerts';
            }))
            ->andReturn(['name' => 'projects/aacs-fc1a6/messages/msg_123']);

        $service = new FirebaseMessagingService($mockMessaging);
        $job = new SendFcmNotificationJob(
            $this->user->id,
            'Test Notification',
            'This is a test notification.',
            [
                'type'    => 'ROOM_EMPTY',
                'room_id' => (string) $this->room->id,
            ]
        );

        $job->handle($service);
    }

    // ── 2. ROOM_EMPTY di-suppress bila AcLog power='OFF' ──────────────────────

    #[Test]
    public function room_empty_event_is_suppressed_when_latest_ac_power_is_off(): void
    {
        Bus::fake();

        AcLog::create([
            'room_id'        => $this->room->id,
            'ac_brand'       => $this->room->ac_brand,
            'power'          => 'OFF',
            'setpoint'       => 24,
            'mode'           => 'MANUAL',
            'ac_mode'        => 'COOL',
            'fan_speed'      => 'AUTO',
            'swing'          => 'AUTO',
            'control_source' => 'REMOTE',
        ]);

        $payload = json_encode([
            'type'             => 'ROOM_EMPTY',
            'people_count'     => 0,
            'duration_seconds' => 300,
            'message'          => 'Ruangan kosong',
        ]);

        $res = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);
        $this->assertTrue($res);

        $this->assertDatabaseHas('notifications', [
            'room_id' => $this->room->id,
            'type'    => 'ROOM_EMPTY',
        ]);

        Bus::assertNotDispatched(SendFcmNotificationJob::class);
    }

    // ── 3. ROOM_EMPTY TETAP dispatch FCM bila AcLog power='ON' ────────────────

    #[Test]
    public function room_empty_event_dispatches_fcm_when_latest_ac_power_is_on(): void
    {
        Bus::fake();

        AcLog::create([
            'room_id'        => $this->room->id,
            'ac_brand'       => $this->room->ac_brand,
            'power'          => 'ON',
            'setpoint'       => 24,
            'mode'           => 'MANUAL',
            'ac_mode'        => 'COOL',
            'fan_speed'      => 'AUTO',
            'swing'          => 'AUTO',
            'control_source' => 'REMOTE',
        ]);

        $payload = json_encode([
            'type'             => 'ROOM_EMPTY',
            'people_count'     => 0,
            'duration_seconds' => 300,
            'message'          => 'Ruangan tidak mendeteksi pengguna selama 5 menit.',
        ]);

        $res = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);
        $this->assertTrue($res);

        $this->assertDatabaseHas('notifications', [
            'room_id' => $this->room->id,
            'type'    => 'ROOM_EMPTY',
        ]);

        Bus::assertDispatched(SendFcmNotificationJob::class, function ($job) {
            return $job->userId === $this->user->id
                && $job->title === 'Ruangan Kosong'
                && $job->data['type'] === 'ROOM_EMPTY'
                && $job->data['room_id'] === (string) $this->room->id;
        });
    }

    // ── 4. NODE_OFFLINE: state update ke REMOTE & push 'Node Sensor Terputus' ─

    #[Test]
    public function node_offline_event_updates_state_saves_notification_and_dispatches_fcm(): void
    {
        Bus::fake();

        AcLog::create([
            'room_id'          => $this->room->id,
            'ac_brand'         => $this->room->ac_brand,
            'power'            => 'ON',
            'setpoint'         => 24,
            'mode'             => 'FUZZY',
            'ac_mode'          => 'COOL',
            'fan_speed'        => 'AUTO',
            'swing'            => 'AUTO',
            'control_source'   => 'USER_NODE',
            'node_paired'      => true,
            'node_connected'   => true,
            'node_battery_low' => false,
        ]);

        $payload = json_encode([
            'type'    => 'NODE_OFFLINE',
            'room_id' => (string) $this->room->id,
            'message' => 'Node sensor terputus, fallback ke sensor remote',
        ]);

        $res = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);
        $this->assertTrue($res);

        $latestLog = AcLog::where('room_id', $this->room->id)->latest('created_at')->first();
        $this->assertFalse($latestLog->node_connected);
        $this->assertEquals('REMOTE', $latestLog->control_source);
        $this->assertTrue($latestLog->node_paired);

        // Remote device tetap paired
        $this->assertTrue($this->device->fresh()->is_paired);

        $this->assertDatabaseHas('notifications', [
            'room_id' => $this->room->id,
            'type'    => 'NODE_OFFLINE',
            'title'   => 'Node Sensor Terputus',
        ]);

        Bus::assertDispatched(SendFcmNotificationJob::class, function ($job) {
            return $job->userId === $this->user->id
                && $job->title === 'Node Sensor Terputus'
                && $job->data['type'] === 'NODE_OFFLINE'
                && $job->data['room_id'] === (string) $this->room->id;
        });
    }

    // ── 5. NODE_BATTERY_LOW: state update ke REMOTE & push 'Baterai Node Rendah'

    #[Test]
    public function node_battery_low_event_updates_state_saves_notification_and_dispatches_fcm(): void
    {
        Bus::fake();

        AcLog::create([
            'room_id'          => $this->room->id,
            'ac_brand'         => $this->room->ac_brand,
            'power'            => 'ON',
            'setpoint'         => 24,
            'mode'             => 'FUZZY',
            'ac_mode'          => 'COOL',
            'fan_speed'        => 'AUTO',
            'swing'            => 'AUTO',
            'control_source'   => 'USER_NODE',
            'node_paired'      => true,
            'node_connected'   => true,
            'node_battery_low' => false,
        ]);

        $payload = json_encode([
            'type'       => 'NODE_BATTERY_LOW',
            'room_id'    => (string) $this->room->id,
            'millivolts' => 3100,
            'percent'    => 15,
            'message'    => 'Baterai node lemah, segera ganti atau isi ulang',
        ]);

        $res = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);
        $this->assertTrue($res);

        $latestLog = AcLog::where('room_id', $this->room->id)->latest('created_at')->first();
        $this->assertTrue($latestLog->node_battery_low);
        $this->assertEquals('REMOTE', $latestLog->control_source);
        $this->assertTrue($latestLog->node_paired);

        // Remote device tetap paired
        $this->assertTrue($this->device->fresh()->is_paired);

        $this->assertDatabaseHas('notifications', [
            'room_id' => $this->room->id,
            'type'    => 'NODE_BATTERY_LOW',
            'title'   => 'Baterai Node Rendah',
        ]);

        Bus::assertDispatched(SendFcmNotificationJob::class, function ($job) {
            return $job->userId === $this->user->id
                && $job->title === 'Baterai Node Rendah'
                && $job->data['type'] === 'NODE_BATTERY_LOW'
                && $job->data['room_id'] === (string) $this->room->id;
        });
    }

    // ── 6. Payload USER_NODE disimpan dan dikembalikan sebagai USER_NODE ──────

    #[Test]
    public function ac_status_handler_saves_and_returns_user_node(): void
    {
        $payload = json_encode([
            'power'            => true,
            'suhu'             => 24,
            'mode'             => 'FUZZY',
            'ac_mode'          => 'COOL',
            'fan_speed'        => 'AUTO',
            'swing'            => 'AUTO',
            'control_source'   => 'USER_NODE',
            'node_paired'      => true,
            'node_connected'   => true,
            'node_battery_low' => false,
        ]);

        $this->mqttCommand->handleAcStatus("room/{$this->room->id}/ac/status", $payload);

        $latestLog = AcLog::where('room_id', $this->room->id)->latest('created_at')->first();
        $this->assertNotNull($latestLog);
        $this->assertEquals('USER_NODE', $latestLog->control_source);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.control_source', 'USER_NODE')
            ->assertJsonPath('data.ac_status.control_source', 'USER_NODE');
    }

    // ── 7. Payload legacy NODE dinormalisasi menjadi USER_NODE ────────────────

    #[Test]
    public function ac_status_handler_normalizes_legacy_node_to_user_node(): void
    {
        $payload = json_encode([
            'power'            => true,
            'suhu'             => 25,
            'mode'             => 'MANUAL',
            'ac_mode'          => 'COOL',
            'fan_speed'        => 'AUTO',
            'swing'            => 'AUTO',
            'control_source'   => 'NODE',
            'node_paired'      => true,
            'node_connected'   => true,
            'node_battery_low' => false,
        ]);

        $this->mqttCommand->handleAcStatus("room/{$this->room->id}/ac/status", $payload);

        $latestLog = AcLog::where('room_id', $this->room->id)->latest('created_at')->first();
        $this->assertNotNull($latestLog);
        $this->assertEquals('USER_NODE', $latestLog->control_source);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.control_source', 'USER_NODE')
            ->assertJsonPath('data.ac_status.control_source', 'USER_NODE');
    }

    // ── 8. latest-status tidak pernah mengembalikan NODE bahkan bila DB berisi NODE

    #[Test]
    public function latest_status_never_returns_node_even_for_legacy_row(): void
    {
        // Masukkan data mentah langsung ke database melewati model
        DB::table('ac_logs')->insert([
            'room_id'          => $this->room->id,
            'ac_brand'         => $this->room->ac_brand,
            'power'            => 'ON',
            'setpoint'         => 23,
            'mode'             => 'FUZZY',
            'ac_mode'          => 'COOL',
            'fan_speed'        => 'AUTO',
            'swing'            => 'AUTO',
            'control_source'   => 'NODE',
            'node_paired'      => 1,
            'node_connected'   => 1,
            'node_battery_low' => 0,
            'created_at'       => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);

        // Pastikan tidak pernah 'NODE'
        $this->assertNotEquals('NODE', $response->json('data.control_source'));
        $this->assertNotEquals('NODE', $response->json('data.ac_status.control_source'));

        // Harus persis 'USER_NODE'
        $response->assertJsonPath('data.control_source', 'USER_NODE')
                 ->assertJsonPath('data.ac_status.control_source', 'USER_NODE');
    }

    // ── 9. latest-status mengembalikan REMOTE ketika remote kontrol ───────────

    #[Test]
    public function latest_status_returns_remote_when_control_source_is_remote(): void
    {
        AcLog::create([
            'room_id'          => $this->room->id,
            'ac_brand'         => $this->room->ac_brand,
            'power'            => 'ON',
            'setpoint'         => 24,
            'mode'             => 'MANUAL',
            'ac_mode'          => 'COOL',
            'fan_speed'        => 'MEDIUM',
            'swing'            => 'AUTO',
            'control_source'   => 'REMOTE',
            'node_paired'      => true,
            'node_connected'   => false,
            'node_battery_low' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.control_source', 'REMOTE')
            ->assertJsonPath('data.ac_status.control_source', 'REMOTE');
    }
}
