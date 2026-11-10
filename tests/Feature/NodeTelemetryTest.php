<?php

namespace Tests\Feature;

use App\Console\Commands\MqttSubscribeCommand;
use App\Models\AcLog;
use App\Models\Device;
use App\Models\NodeTelemetryStatus;
use App\Models\Room;
use App\Models\SensorLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NodeTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->user = User::factory()->create();
        $this->room = Room::create([
            'user_id'  => $this->user->id,
            'name'     => 'Ruangan Uji',
            'ac_brand' => 'Panasonic',
        ]);

        Device::create([
            'room_id'     => $this->room->id,
            'name'        => 'ESP32 Remote',
            'device_type' => 'ESP32',
            'mac_address' => 'AA:BB:CC:DD:EE:01',
            'is_paired'   => true,
            'status'      => 'ONLINE',
            'last_ping'   => now(),
        ]);
    }

    public function test_valid_node_telemetry_payload_is_saved_and_appears_in_latest_status(): void
    {
        $command = app(MqttSubscribeCommand::class);
        $topic = "room/{$this->room->id}/node/telemetry";
        $payload = json_encode([
            'version'    => 1,
            'source'     => 'USER_NODE',
            'valid'      => true,
            'suhu'       => 24.45,
            'kelembaban' => 69.10,
        ]);

        $handled = $command->handleNodeTelemetry($topic, $payload);
        $this->assertTrue($handled);

        $status = NodeTelemetryStatus::where('room_id', $this->room->id)->first();
        $this->assertNotNull($status);
        $this->assertTrue($status->valid);
        $this->assertEquals(24.45, $status->temperature);
        $this->assertEquals(69.10, $status->humidity);
        $this->assertNotNull($status->received_at);

        $response = $this->actingAs($this->user)->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $response->assertStatus(200);

        $response->assertJsonPath('data.node_sensor.temperature', 24.45);
        $response->assertJsonPath('data.node_sensor.humidity', 69.10);
        $response->assertJsonPath('data.node_sensor.valid', true);
        $response->assertJsonPath('data.node_sensor.is_stale', false);
        $this->assertNotNull($response->json('data.node_sensor.updated_at'));
    }

    public function test_invalid_node_telemetry_payload_saves_null_and_never_zero(): void
    {
        $command = app(MqttSubscribeCommand::class);
        $topic = "room/{$this->room->id}/node/telemetry";
        $payload = json_encode([
            'version'    => 1,
            'source'     => 'USER_NODE',
            'valid'      => false,
            'suhu'       => null,
            'kelembaban' => null,
        ]);

        $handled = $command->handleNodeTelemetry($topic, $payload);
        $this->assertTrue($handled);

        $status = NodeTelemetryStatus::where('room_id', $this->room->id)->first();
        $this->assertNotNull($status);
        $this->assertFalse($status->valid);
        $this->assertNull($status->temperature);
        $this->assertNull($status->humidity);
        $this->assertNotSame(0, $status->temperature);
        $this->assertNotSame(0.0, $status->temperature);

        $response = $this->actingAs($this->user)->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $response->assertStatus(200);

        $response->assertJsonPath('data.node_sensor.temperature', null);
        $response->assertJsonPath('data.node_sensor.humidity', null);
        $response->assertJsonPath('data.node_sensor.valid', false);
        $response->assertJsonPath('data.node_sensor.is_stale', true);
    }

    public function test_node_telemetry_becomes_stale_after_60_seconds(): void
    {
        // Kondisi 1: Diterima 50 detik yang lalu (< 60 detik) -> TIDAK STALE
        NodeTelemetryStatus::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 25.5,
            'humidity'    => 60.5,
            'received_at' => Carbon::now('UTC')->subSeconds(50),
        ]);

        $res1 = $this->actingAs($this->user)->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $res1->assertStatus(200);
        $res1->assertJsonPath('data.node_sensor.is_stale', false);
        $res1->assertJsonPath('data.node_sensor.temperature', 25.5);

        // Kondisi 2: Diterima 65 detik yang lalu (> 60 detik) -> STALE
        NodeTelemetryStatus::where('room_id', $this->room->id)->update([
            'received_at' => Carbon::now('UTC')->subSeconds(65),
        ]);

        $res2 = $this->actingAs($this->user)->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.node_sensor.is_stale', true);
        // Nilai terakhir tetap dikembalikan (tidak diubah menjadi null atau 0)
        $res2->assertJsonPath('data.node_sensor.temperature', 25.5);
    }

    public function test_when_no_node_telemetry_received_defaults_to_null_and_stale(): void
    {
        $response = $this->actingAs($this->user)->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $response->assertStatus(200);

        $response->assertJsonPath('data.node_sensor.temperature', null);
        $response->assertJsonPath('data.node_sensor.humidity', null);
        $response->assertJsonPath('data.node_sensor.updated_at', null);
        $response->assertJsonPath('data.node_sensor.valid', false);
        $response->assertJsonPath('data.node_sensor.is_stale', true);
    }

    public function test_set_control_source_command_bypasses_ac_logging(): void
    {
        $initialLogsCount = AcLog::count();

        $response = $this->actingAs($this->user)->postJson("/api/v1/rooms/{$this->room->id}/ac-command", [
            'command' => 'SET_CONTROL_SOURCE',
            'value'   => 'USER_NODE',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'Perintah SET_CONTROL_SOURCE berhasil dikirim.',
        ]);

        // Verifikasi tidak ada penambahan log AC redundan
        $this->assertEquals($initialLogsCount, AcLog::count());
    }

    public function test_old_telemetry_topic_is_completely_unaffected(): void
    {
        $command = app(MqttSubscribeCommand::class);
        $topic = "room/{$this->room->id}/telemetry";
        $payload = json_encode([
            'suhu'       => 28.5,
            'kelembaban' => 65.0,
        ]);

        $command->handleTelemetry($topic, $payload);

        $sensorLog = SensorLog::where('room_id', $this->room->id)->latest('created_at')->first();
        $this->assertNotNull($sensorLog);
        $this->assertEquals(28.5, $sensorLog->temperature);
        $this->assertEquals(65.0, $sensorLog->humidity);

        // Node sensor tetap null / tidak terpengaruh
        $this->assertNull(NodeTelemetryStatus::where('room_id', $this->room->id)->first());
    }
}
