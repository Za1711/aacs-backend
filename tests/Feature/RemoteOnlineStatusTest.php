<?php

namespace Tests\Feature;

use App\Console\Commands\MqttSubscribeCommand;
use App\Models\AcLog;
use App\Models\Device;
use App\Models\NodeTelemetryStatus;
use App\Models\RemoteDeviceStatus;
use App\Models\Room;
use App\Models\SensorLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RemoteOnlineStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Room $room;
    private Device $device;
    private MqttSubscribeCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->user = User::factory()->create();
        $this->room = Room::create([
            'user_id'         => $this->user->id,
            'name'            => 'Lab IoT Uji',
            'ac_brand'        => 'Daikin',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ]);

        $this->device = Device::create([
            'room_id'       => $this->room->id,
            'user_id'       => $this->user->id,
            'device_type'   => 'ESP32',
            'serial_number' => 'ESP32-REMOTE-001',
            'status'        => 'ONLINE',
            'is_paired'     => true,
            'last_ping'     => now(),
        ]);

        $this->command = app(MqttSubscribeCommand::class);
    }

    /**
     * 1. ac/status fresh dan telemetri sensor valid -> device_online true
     */
    public function test_ac_status_fresh_dan_telemetri_sensor_valid_device_online_true(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));

        SensorLog::create([
            'room_id'     => $this->room->id,
            'temperature' => 24.5,
            'humidity'    => 60.0,
        ]);

        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", json_encode([
            'power'   => true,
            'suhu'    => 24,
            'mode'    => 'MANUAL',
            'ac_mode' => 'COOL',
        ]));

        // Dipanggil 5 detik kemudian (< 20s timeout)
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:05'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);
        $response->assertJsonPath('data.device_online', true);
        $response->assertJsonPath('data.sensor.is_stale', false);
        $this->assertEquals(24.5, $response->json('data.sensor.temperature'));
        $this->assertEquals(60.0, $response->json('data.sensor.humidity'));
    }

    /**
     * 2. ac/status fresh tetapi belum ada SensorLog -> device_online true
     */
    public function test_ac_status_fresh_tetapi_belum_ada_sensor_log_device_online_true(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));

        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", json_encode([
            'power' => false,
            'suhu'  => 25,
            'mode'  => 'MANUAL',
        ]));

        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:05'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);
        $response->assertJsonPath('data.device_online', true);
        $response->assertJsonPath('data.sensor.temperature', null);
        $response->assertJsonPath('data.sensor.humidity', null);
        $response->assertJsonPath('data.sensor.is_stale', true);
    }

    /**
     * 3. ac/status fresh tetapi SensorLog stale -> device_online true
     */
    public function test_ac_status_fresh_tetapi_sensor_log_stale_device_online_true(): void
    {
        // SensorLog dibuat 60 detik lalu (> 20s timeout -> stale)
        Carbon::setTestNow(Carbon::parse('2026-11-08 09:59:00'));
        SensorLog::create([
            'room_id'     => $this->room->id,
            'temperature' => 28.0,
            'humidity'    => 75.0,
        ]);

        // ac/status baru diterima pada 10:00:00
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));
        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", json_encode([
            'power' => true,
            'suhu'  => 24,
            'mode'  => 'MANUAL',
        ]));

        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:05'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);
        $response->assertJsonPath('data.device_online', true);
        $response->assertJsonPath('data.sensor.is_stale', true);
        $this->assertEquals(28.0, $response->json('data.sensor.temperature'));
    }

    /**
     * 4. ac/status fresh tetapi suhu/humidity null atau SHT invalid -> device_online true
     */
    public function test_ac_status_fresh_tetapi_suhu_humidity_null_device_online_true(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));

        // SHT31 dicabut/invalid: tidak ada SensorLog valid terkirim
        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", json_encode([
            'power' => true,
            'suhu'  => 23,
            'mode'  => 'MANUAL',
        ]));

        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:04'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);
        $response->assertJsonPath('data.device_online', true);
        $response->assertJsonPath('data.sensor.temperature', null);
        $response->assertJsonPath('data.sensor.humidity', null);
        $response->assertJsonPath('data.sensor.is_stale', true);
    }

    /**
     * 5. payload ac/status yang identik tetap memperbarui last_seen_at
     */
    public function test_payload_ac_status_identik_tetap_memperbarui_last_seen_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));
        $identicalPayload = json_encode([
            'power'     => false,
            'suhu'      => 24,
            'mode'      => 'MANUAL',
            'ac_mode'   => 'COOL',
            'fan_speed' => 'MEDIUM',
            'swing'     => 'AUTO',
        ]);

        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", $identicalPayload);

        $status1 = RemoteDeviceStatus::where('room_id', $this->room->id)->first();
        $this->assertNotNull($status1);
        $this->assertEquals('2026-11-08 10:00:00', $status1->last_seen_at->toDateTimeString());

        // Kirim payload yang PERSIS sama 8 detik kemudian
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:08'));
        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", $identicalPayload);

        $status2 = RemoteDeviceStatus::where('room_id', $this->room->id)->first();
        $this->assertEquals('2026-11-08 10:00:08', $status2->last_seen_at->toDateTimeString());
    }

    /**
     * 6. ac/status lebih lama dari timeout -> device_online false
     */
    public function test_ac_status_lebih_lama_dari_timeout_device_online_false(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));

        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", json_encode([
            'power' => true,
            'suhu'  => 24,
            'mode'  => 'MANUAL',
        ]));

        // Lewat 21 detik (> 20s timeout)
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:21'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);
        $response->assertJsonPath('data.device_online', false);
        $response->assertJsonPath('data.device_last_seen_at', '2026-11-08T10:00:00Z');
    }

    /**
     * 7. SensorLog masih fresh tetapi ac/status sudah stale -> device_online false
     */
    public function test_sensor_log_masih_fresh_tetapi_ac_status_sudah_stale_device_online_false(): void
    {
        // ac/status diterima pada 10:00:00
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));
        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", json_encode([
            'power' => true,
            'suhu'  => 24,
            'mode'  => 'MANUAL',
        ]));

        // SensorLog baru diterima pada 10:00:20 (baru 2 detik yang lalu)
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:20'));
        SensorLog::create([
            'room_id'     => $this->room->id,
            'temperature' => 26.0,
            'humidity'    => 65.0,
        ]);

        // Pada 10:00:22 (ac/status berumur 22s > 20s, sensor berumur 2s < 20s)
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:22'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);
        // device_online WAJIB false karena remote heartbeat stale
        $response->assertJsonPath('data.device_online', false);
        // sensor tetap segar (is_stale false)
        $response->assertJsonPath('data.sensor.is_stale', false);
        $this->assertEquals(26.0, $response->json('data.sensor.temperature'));
    }

    /**
     * 8. belum pernah menerima ac/status -> device_online false
     */
    public function test_belum_pernah_menerima_ac_status_device_online_false(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);
        $response->assertJsonPath('data.device_online', false);
        $response->assertJsonPath('data.device_last_seen_at', null);
    }

    /**
     * 9. unpair room mereset heartbeat -> device_online false
     */
    public function test_unpair_room_mereset_heartbeat_device_online_false(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));

        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", json_encode([
            'power' => true,
            'suhu'  => 24,
            'mode'  => 'MANUAL',
        ]));

        $this->assertTrue(RemoteDeviceStatus::where('room_id', $this->room->id)->exists());

        // Lakukan unpair
        $unpairResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/rooms/{$this->room->id}/unpair");
        $unpairResponse->assertStatus(200);

        // Pastikan RemoteDeviceStatus terhapus
        $this->assertFalse(RemoteDeviceStatus::where('room_id', $this->room->id)->exists());

        // Endpoint status harus mengembalikan device_online false
        $statusResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $statusResponse->assertStatus(200);
        $statusResponse->assertJsonPath('data.device_online', false);
        $statusResponse->assertJsonPath('data.device_last_seen_at', null);
    }

    /**
     * 10. status node_connected tidak memengaruhi device_online Remote
     */
    public function test_status_node_connected_tidak_memengaruhi_device_online_remote(): void
    {
        // Kondisi A: node_connected true, tetapi ac/status Remote STALE (> 20s) -> device_online: false
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));
        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", json_encode([
            'power'          => true,
            'suhu'           => 24,
            'mode'           => 'MANUAL',
            'node_connected' => true,
        ]));

        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:30')); // 30s > 20s
        $resA = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $resA->assertStatus(200);
        $resA->assertJsonPath('data.node_connected', true);
        $resA->assertJsonPath('data.device_online', false);

        // Kondisi B: node_connected false, tetapi ac/status Remote FRESH (< 20s) -> device_online: true
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:01:00'));
        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", json_encode([
            'power'          => true,
            'suhu'           => 24,
            'mode'           => 'MANUAL',
            'node_connected' => false,
        ]));

        Carbon::setTestNow(Carbon::parse('2026-11-08 10:01:05')); // 5s < 20s
        $resB = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $resB->assertStatus(200);
        $resB->assertJsonPath('data.node_connected', false);
        $resB->assertJsonPath('data.device_online', true);
    }

    /**
     * 11. topic telemetri lama dan node/telemetry tetap tidak berubah
     */
    public function test_topic_telemetri_lama_dan_node_telemetry_tetap_tidak_berubah(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));

        // Topic lama: room/{id}/telemetry
        $this->command->handleTelemetry("room/{$this->room->id}/telemetry", json_encode([
            'suhu'       => 27.8,
            'kelembaban' => 64.2,
        ]));

        $sensor = SensorLog::where('room_id', $this->room->id)->latest('created_at')->first();
        $this->assertNotNull($sensor);
        $this->assertEquals(27.8, $sensor->temperature);
        $this->assertEquals(64.2, $sensor->humidity);

        // Topic baru node: room/{id}/node/telemetry
        $this->command->handleNodeTelemetry("room/{$this->room->id}/node/telemetry", json_encode([
            'version'    => 1,
            'source'     => 'USER_NODE',
            'valid'      => true,
            'suhu'       => 23.4,
            'kelembaban' => 58.9,
        ]));

        $nodeStatus = NodeTelemetryStatus::where('room_id', $this->room->id)->first();
        $this->assertNotNull($nodeStatus);
        $this->assertTrue($nodeStatus->valid);
        $this->assertEquals(23.4, $nodeStatus->temperature);
        $this->assertEquals(58.9, $nodeStatus->humidity);
    }
}
