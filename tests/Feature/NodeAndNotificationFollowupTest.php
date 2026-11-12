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
use App\Models\NodeLinkSample;
use App\Models\NodeTelemetryStatus;
use App\Models\NodeBatteryStatus;
use Carbon\Carbon;
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

        $baselineTime = Carbon::now();
        Carbon::setTestNow($baselineTime->copy()->subSeconds(70));

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

        Carbon::setTestNow($baselineTime);

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

        Carbon::setTestNow();
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

    // ── 10. Satu sampel gagal dengan NodeLinkSample terbaru tidak menghasilkan push ─
    #[Test]
    public function single_failed_sample_with_recent_node_link_sample_does_not_produce_push(): void
    {
        Bus::fake();

        // AcLog awal dengan USER_NODE dan node_connected=true
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

        // Satu paket gagal pada link metrics (telemetry_acked=false, telemetry_received=false, rssi_dbm=null)
        $linkPayload = [
            'version'              => 1,
            'session_id'           => 12345,
            'telemetry_seq'        => 1,
            'telemetry_packet_seq' => 1,
            'sent_total'           => 1,
            'acked_total'          => 0,
            'failed_total'         => 1,
            'retries_total'        => 3,
            'telemetry_acked'      => false,
            'telemetry_received'   => false,
            'battery_valid'        => true,
            'battery_mv'           => 3800,
            'battery_percent'      => 85,
            'rssi_dbm'             => null,
            'test_complete'        => false,
        ];

        $linkRes = $this->mqttCommand->handleNodeLinkMetrics("room/{$this->room->id}/node/link-metrics", json_encode($linkPayload));
        $this->assertTrue($linkRes);

        // Verifikasi sampel tersimpan dan membuktikan perangkat aktif (failed_total naik, pdr=0%, rssi null)
        $sample = NodeLinkSample::where('room_id', $this->room->id)->first();
        $this->assertNotNull($sample);
        $this->assertEquals(1, $sample->failed_total);
        $this->assertEquals(0.0, $sample->pdr);
        $this->assertNull($sample->rssi_dbm);

        // Event NODE_OFFLINE lama/tertunda datang
        $offlinePayload = json_encode([
            'type'               => 'NODE_OFFLINE',
            'room_id'            => (string) $this->room->id,
            'message'            => 'Node sensor terputus; sumber dipindahkan ke Remote',
            'offline_for_ms'     => 60000,
            'offline_timeout_ms' => 60000,
        ]);

        $res = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $offlinePayload);
        $this->assertTrue($res);

        // Tidak boleh membuat Notification NODE_OFFLINE
        $this->assertDatabaseMissing('notifications', [
            'room_id' => $this->room->id,
            'type'    => 'NODE_OFFLINE',
        ]);

        // Tidak boleh dispatch push FCM
        Bus::assertNotDispatched(SendFcmNotificationJob::class);

        // State control_source dan node_connected tetap tidak berubah
        $latestLog = AcLog::where('room_id', $this->room->id)->latest('created_at')->first();
        $this->assertEquals('USER_NODE', $latestLog->control_source);
        $this->assertTrue($latestLog->node_connected);
    }

    // ── 11. Event dengan offline_for_ms = 5000 diabaikan ──────────────────────
    #[Test]
    public function premature_node_offline_event_with_short_offline_for_ms_is_ignored(): void
    {
        Bus::fake();

        $baselineTime = Carbon::now();
        Carbon::setTestNow($baselineTime->copy()->subSeconds(75));

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

        Carbon::setTestNow($baselineTime);

        $payload = json_encode([
            'type'               => 'NODE_OFFLINE',
            'room_id'            => (string) $this->room->id,
            'message'            => 'Node sensor terputus dini',
            'offline_for_ms'     => 5000,
            'offline_timeout_ms' => 60000,
        ]);

        $res = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);
        $this->assertTrue($res);

        // Tidak membuat notification
        $this->assertDatabaseMissing('notifications', [
            'room_id' => $this->room->id,
            'type'    => 'NODE_OFFLINE',
        ]);

        Bus::assertNotDispatched(SendFcmNotificationJob::class);

        // AcLog tidak berubah
        $latestLog = AcLog::where('room_id', $this->room->id)->latest('created_at')->first();
        $this->assertEquals('USER_NODE', $latestLog->control_source);
        $this->assertTrue($latestLog->node_connected);

        Carbon::setTestNow();
    }

    // ── 12. Event lama setelah Node pulih diabaikan ───────────────────────────
    #[Test]
    public function stale_node_offline_event_after_node_recovered_is_ignored(): void
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

        // Node sudah pulih dan mengirim telemetri status 5 detik yang lalu
        NodeTelemetryStatus::updateOrCreate(
            ['room_id' => $this->room->id],
            [
                'valid'       => true,
                'temperature' => 26.5,
                'humidity'    => 60.0,
                'received_at' => Carbon::now()->subSeconds(5),
            ]
        );

        $payload = json_encode([
            'type'               => 'NODE_OFFLINE',
            'room_id'            => (string) $this->room->id,
            'message'            => 'Node sensor terputus lama',
            'offline_for_ms'     => 60000,
            'offline_timeout_ms' => 60000,
        ]);

        $res = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);
        $this->assertTrue($res);

        $this->assertDatabaseMissing('notifications', [
            'room_id' => $this->room->id,
            'type'    => 'NODE_OFFLINE',
        ]);

        Bus::assertNotDispatched(SendFcmNotificationJob::class);

        $latestLog = AcLog::where('room_id', $this->room->id)->latest('created_at')->first();
        $this->assertEquals('USER_NODE', $latestLog->control_source);
        $this->assertTrue($latestLog->node_connected);
    }

    // ── 13. Event dengan offline_for_ms >= 60000 dan tanpa bukti kontak baru diproses ─
    #[Test]
    public function confirmed_node_offline_event_with_valid_duration_and_no_recent_contact_is_processed(): void
    {
        Bus::fake();

        $baselineTime = Carbon::now();
        Carbon::setTestNow($baselineTime->copy()->subSeconds(75));

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

        Carbon::setTestNow($baselineTime);

        $payload = json_encode([
            'type'               => 'NODE_OFFLINE',
            'room_id'            => (string) $this->room->id,
            'message'            => 'Node sensor terputus; sumber dipindahkan ke Remote',
            'offline_for_ms'     => 60000,
            'offline_timeout_ms' => 60000,
        ]);

        $res = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);
        $this->assertTrue($res);

        $this->assertDatabaseHas('notifications', [
            'room_id' => $this->room->id,
            'type'    => 'NODE_OFFLINE',
            'title'   => 'Node Sensor Terputus',
        ]);

        Bus::assertDispatched(SendFcmNotificationJob::class);

        $latestLog = AcLog::where('room_id', $this->room->id)->latest('created_at')->first();
        $this->assertEquals('REMOTE', $latestLog->control_source);
        $this->assertFalse($latestLog->node_connected);
        $this->assertTrue($latestLog->node_paired);
        $this->assertFalse($latestLog->node_battery_low);

        Carbon::setTestNow();
    }

    // ── 14. Offline sah mengubah sumber menjadi REMOTE, node_paired tetap true, test_complete tidak berubah ─
    #[Test]
    public function confirmed_offline_sets_remote_keeps_node_paired_and_preserves_test_complete(): void
    {
        Bus::fake();

        $baselineTime = Carbon::now();
        Carbon::setTestNow($baselineTime->copy()->subSeconds(80));

        // Sample link metrics lama
        $sample = NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 554433,
            'telemetry_seq'        => 3,
            'telemetry_packet_seq' => 3,
            'sent_total'           => 3,
            'acked_total'          => 3,
            'failed_total'         => 0,
            'retries_total'        => 0,
            'pdr'                  => 100.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3700,
            'battery_percent'      => 80,
            'rssi_dbm'             => -70,
            'test_complete'        => false,
            'received_at'          => Carbon::now(),
        ]);

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

        Carbon::setTestNow($baselineTime);

        $payload = json_encode([
            'type'               => 'NODE_OFFLINE',
            'room_id'            => (string) $this->room->id,
            'message'            => 'Node sensor terputus',
            'offline_for_ms'     => 65000,
            'offline_timeout_ms' => 60000,
        ]);

        $res = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);
        $this->assertTrue($res);

        $latestLog = AcLog::where('room_id', $this->room->id)->latest('created_at')->first();
        $this->assertEquals('REMOTE', $latestLog->control_source);
        $this->assertFalse($latestLog->node_connected);
        $this->assertTrue($latestLog->node_paired);
        $this->assertFalse($latestLog->node_battery_low);

        // test_complete pada NodeLinkSample tidak boleh diubah
        $this->assertFalse($sample->fresh()->test_complete);

        Carbon::setTestNow();
    }

    // ── 15. Handler telemetri, baterai, dan link metrics lama tidak rusak ─────
    #[Test]
    public function legacy_telemetry_battery_and_link_metrics_handlers_remain_functional(): void
    {
        // 1. Telemetri
        $telemetryMsg = json_encode([
            'suhu'       => 27.8,
            'kelembaban' => 65.2,
            'valid'      => true,
        ]);
        $this->assertTrue($this->mqttCommand->handleNodeTelemetry("room/{$this->room->id}/node/telemetry", $telemetryMsg));
        $this->assertDatabaseHas('node_telemetry_statuses', [
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 27.8,
        ]);

        // 2. Baterai
        $batteryMsg = json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3850,
            'percent'    => 90,
        ]);
        $this->assertTrue($this->mqttCommand->handleNodeBattery("room/{$this->room->id}/node/battery", $batteryMsg));
        $this->assertDatabaseHas('node_battery_statuses', [
            'room_id'    => $this->room->id,
            'valid'      => true,
            'millivolts' => 3850,
            'percent'    => 90,
        ]);

        // 3. Link metrics
        $linkMsg = json_encode([
            'version'              => 1,
            'session_id'           => 8877,
            'telemetry_seq'        => 1,
            'telemetry_packet_seq' => 1,
            'sent_total'           => 1,
            'acked_total'          => 1,
            'failed_total'         => 0,
            'retries_total'        => 0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3850,
            'battery_percent'      => 90,
            'rssi_dbm'             => -68,
            'test_complete'        => false,
        ]);
        $this->assertTrue($this->mqttCommand->handleNodeLinkMetrics("room/{$this->room->id}/node/link-metrics", $linkMsg));
        $this->assertDatabaseHas('node_link_samples', [
            'room_id'       => $this->room->id,
            'session_id'    => 8877,
            'telemetry_seq' => 1,
            'pdr'           => 100.0,
        ]);
    }

    // ── 16. Offline valid tidak boleh dibatalkan oleh riwayat true lama ───────
    #[Test]
    public function valid_offline_is_not_cancelled_by_older_true_ac_log(): void
    {
        Bus::fake();

        $baselineTime = Carbon::now();

        // 1. AcLog node_connected=true pada now()-5 detik
        Carbon::setTestNow($baselineTime->copy()->subSeconds(5));
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

        // 2. AcLog yang lebih baru dengan node_connected=false pada now()
        Carbon::setTestNow($baselineTime);
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
            'node_connected'   => false,
            'node_battery_low' => false,
        ]);

        // 3. Proses NODE_OFFLINE dengan offline_for_ms=60000
        $payload = json_encode([
            'type'               => 'NODE_OFFLINE',
            'room_id'            => (string) $this->room->id,
            'message'            => 'Node sensor terputus; sumber dipindahkan ke Remote',
            'offline_for_ms'     => 60000,
            'offline_timeout_ms' => 60000,
        ]);

        $res = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);
        $this->assertTrue($res);

        // 4. Pastikan notifikasi tersimpan dan FCM dikirim
        $this->assertDatabaseHas('notifications', [
            'room_id' => $this->room->id,
            'type'    => 'NODE_OFFLINE',
            'title'   => 'Node Sensor Terputus',
        ]);

        Bus::assertDispatched(SendFcmNotificationJob::class);

        $latestLog = AcLog::where('room_id', $this->room->id)->latest('created_at')->first();
        $this->assertEquals('REMOTE', $latestLog->control_source);
        $this->assertFalse($latestLog->node_connected);

        Carbon::setTestNow();
    }

    // ── 17. Event stale harus dibatalkan setelah Node pulih ───────────────────
    #[Test]
    public function stale_offline_event_is_cancelled_after_node_recovers(): void
    {
        Bus::fake();

        $baselineTime = Carbon::now();

        // 1. AcLog node_connected=false terlebih dahulu pada now()-10 detik
        Carbon::setTestNow($baselineTime->copy()->subSeconds(10));
        AcLog::create([
            'room_id'          => $this->room->id,
            'ac_brand'         => $this->room->ac_brand,
            'power'            => 'ON',
            'setpoint'         => 24,
            'mode'             => 'MANUAL',
            'ac_mode'          => 'COOL',
            'fan_speed'        => 'AUTO',
            'swing'            => 'AUTO',
            'control_source'   => 'REMOTE',
            'node_paired'      => true,
            'node_connected'   => false,
            'node_battery_low' => false,
        ]);

        // 2. AcLog terbaru dengan node_connected=true pada now()-2 detik (Node pulih)
        Carbon::setTestNow($baselineTime->copy()->subSeconds(2));
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

        Carbon::setTestNow($baselineTime);

        // 3. Proses NODE_OFFLINE lama (tertunda dari sebelum pulih)
        $payload = json_encode([
            'type'               => 'NODE_OFFLINE',
            'room_id'            => (string) $this->room->id,
            'message'            => 'Node sensor terputus lama',
            'offline_for_ms'     => 60000,
            'offline_timeout_ms' => 60000,
        ]);

        $res = $this->mqttCommand->handleNotification("room/{$this->room->id}/notification", $payload);
        $this->assertTrue($res);

        // 4. Pastikan tidak ada notifikasi dan FCM tidak dikirim
        $this->assertDatabaseMissing('notifications', [
            'room_id' => $this->room->id,
            'type'    => 'NODE_OFFLINE',
        ]);

        Bus::assertNotDispatched(SendFcmNotificationJob::class);

        $latestLog = AcLog::where('room_id', $this->room->id)->latest('created_at')->first();
        $this->assertEquals('USER_NODE', $latestLog->control_source);
        $this->assertTrue($latestLog->node_connected);

        Carbon::setTestNow();
    }
}
