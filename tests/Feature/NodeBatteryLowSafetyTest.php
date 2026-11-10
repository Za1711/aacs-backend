<?php

namespace Tests\Feature;

use App\Console\Commands\MqttSubscribeCommand;
use App\Jobs\SendFcmNotificationJob;
use App\Models\AcLog;
use App\Models\Device;
use App\Models\NodeBatteryStatus;
use App\Models\NodeLinkSample;
use App\Models\NodeTelemetryStatus;
use App\Models\Notification;
use App\Models\RemoteDeviceStatus;
use App\Models\Room;
use App\Models\SensorLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NodeBatteryLowSafetyTest extends TestCase
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
            'name'            => 'Lab IoT Safety Battery',
            'ac_brand'        => 'Daikin',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ]);

        $this->device = Device::create([
            'room_id'       => $this->room->id,
            'user_id'       => $this->user->id,
            'device_type'   => 'ESP32',
            'serial_number' => 'ESP32-SAFE-001',
            'status'        => 'ONLINE',
            'is_paired'     => true,
            'last_ping'     => now(),
        ]);

        $this->command = new MqttSubscribeCommand();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setNodeConnected(bool $connected = true): void
    {
        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", json_encode([
            'power'            => true,
            'suhu'             => 24,
            'mode'             => 'MANUAL',
            'ac_mode'          => 'COOL',
            'node_paired'      => true,
            'node_connected'   => $connected,
            'node_battery_low' => false,
        ]));
    }

    // 1. Baterai valid 3300 mV/20% menghasilkan low=true
    public function test_baterai_valid_3300_mv_20_persen_menghasilkan_low_true(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        $this->setNodeConnected(true);

        $payload = json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3300,
            'percent'    => 20,
        ]);

        $res = $this->command->handleNodeBattery("room/{$this->room->id}/node/battery", $payload);
        $this->assertTrue($res);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.node_battery.valid', true)
            ->assertJsonPath('data.node_battery.is_stale', false)
            ->assertJsonPath('data.node_battery.millivolts', 3300)
            ->assertJsonPath('data.node_battery.percent', 20)
            ->assertJsonPath('data.node_connected', true)
            ->assertJsonPath('data.node_battery_low', true);
    }

    // 2. Baterai valid 3290 mV/19% menghasilkan low=true
    public function test_baterai_valid_3290_mv_19_persen_menghasilkan_low_true(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        $this->setNodeConnected(true);

        $payload = json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3290,
            'percent'    => 19,
        ]);

        $res = $this->command->handleNodeBattery("room/{$this->room->id}/node/battery", $payload);
        $this->assertTrue($res);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.node_battery.valid', true)
            ->assertJsonPath('data.node_battery.is_stale', false)
            ->assertJsonPath('data.node_battery.millivolts', 3290)
            ->assertJsonPath('data.node_battery.percent', 19)
            ->assertJsonPath('data.node_connected', true)
            ->assertJsonPath('data.node_battery_low', true);
    }

    // 3. Baterai valid di atas ambang menghasilkan low=false
    public function test_baterai_valid_di_atas_ambang_menghasilkan_low_false(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        $this->setNodeConnected(true);

        $payload = json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3800,
            'percent'    => 65,
        ]);

        $res = $this->command->handleNodeBattery("room/{$this->room->id}/node/battery", $payload);
        $this->assertTrue($res);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.node_battery.valid', true)
            ->assertJsonPath('data.node_battery.is_stale', false)
            ->assertJsonPath('data.node_battery.millivolts', 3800)
            ->assertJsonPath('data.node_battery.percent', 65)
            ->assertJsonPath('data.node_connected', true)
            ->assertJsonPath('data.node_battery_low', false);
    }

    // 4. Payload valid=false dan nilai null menghasilkan low=false tanpa notifikasi
    public function test_payload_valid_false_dan_nilai_null_menghasilkan_low_false_tanpa_notifikasi(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        $this->setNodeConnected(true);

        $payload = json_encode([
            'version'    => 1,
            'valid'      => false,
            'millivolts' => null,
            'percent'    => null,
        ]);

        $res = $this->command->handleNodeBattery("room/{$this->room->id}/node/battery", $payload);
        $this->assertTrue($res);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.node_battery.valid', false)
            ->assertJsonPath('data.node_battery.is_stale', false)
            ->assertJsonPath('data.node_battery.millivolts', null)
            ->assertJsonPath('data.node_battery.voltage', null)
            ->assertJsonPath('data.node_battery.percent', null)
            ->assertJsonPath('data.node_connected', true)
            ->assertJsonPath('data.node_battery_low', false);

        // Tidak ada notifikasi baterai low
        $this->assertDatabaseMissing('notifications', [
            'room_id' => $this->room->id,
            'type'    => 'NODE_BATTERY_LOW',
        ]);
        Bus::assertNotDispatched(SendFcmNotificationJob::class);
    }

    // 5. Belum pernah menerima data baterai menghasilkan low=false
    public function test_belum_pernah_menerima_data_baterai_menghasilkan_low_false(): void
    {
        $this->setNodeConnected(true);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.node_battery.valid', false)
            ->assertJsonPath('data.node_battery.is_stale', true)
            ->assertJsonPath('data.node_battery.millivolts', null)
            ->assertJsonPath('data.node_battery.percent', null)
            ->assertJsonPath('data.node_battery_low', false);
    }

    // 6. Data valid tetapi stale menghasilkan low=false
    public function test_data_valid_tetapi_stale_menghasilkan_low_false(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        $this->setNodeConnected(true);

        $payload = json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3100,
            'percent'    => 10,
        ]);
        $this->command->handleNodeBattery("room/{$this->room->id}/node/battery", $payload);

        // Awalnya low=true
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status")
            ->assertJsonPath('data.node_battery_low', true);

        // Waktu maju 60 detik (timeout baterai 30 detik -> menjadi stale)
        Carbon::setTestNow('2026-11-08 10:01:00');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.node_battery.is_stale', true)
            ->assertJsonPath('data.node_battery.valid', false)
            ->assertJsonPath('data.node_battery.millivolts', null)
            ->assertJsonPath('data.node_battery_low', false);
    }

    // 7. Urutan low kemudian invalid membersihkan low menjadi false
    public function test_urutan_low_kemudian_invalid_membersihkan_low_menjadi_false(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        $this->setNodeConnected(true);

        // 1. Kirim status low
        $this->command->handleNodeBattery("room/{$this->room->id}/node/battery", json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3200,
            'percent'    => 15,
        ]));

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status")
            ->assertJsonPath('data.node_battery_low', true);

        // 2. Kirim status invalid (misal baterai dilepas tapi node masih on via USB)
        $this->command->handleNodeBattery("room/{$this->room->id}/node/battery", json_encode([
            'version'    => 1,
            'valid'      => false,
            'millivolts' => null,
            'percent'    => null,
        ]));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.node_battery.valid', false)
            ->assertJsonPath('data.node_battery.millivolts', null)
            ->assertJsonPath('data.node_connected', true)
            ->assertJsonPath('data.node_battery_low', false);
    }

    // 8. ac/status lama low=true tetapi baterai terkini invalid: endpoint tetap false
    public function test_ac_status_lama_low_true_tetapi_baterai_terkini_invalid_endpoint_tetap_false(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');

        // ac_status di DB sengaja diset node_battery_low = true
        AcLog::create([
            'room_id'          => $this->room->id,
            'ac_brand'         => $this->room->ac_brand,
            'power'            => 'ON',
            'setpoint'         => 24,
            'mode'             => 'MANUAL',
            'ac_mode'          => 'COOL',
            'fan_speed'        => 'MEDIUM',
            'swing'            => 'AUTO',
            'control_source'   => 'USER_NODE',
            'node_paired'      => true,
            'node_connected'   => true,
            'node_battery_low' => true,
        ]);

        // Namun status baterai terkini invalid
        NodeBatteryStatus::create([
            'room_id'     => $this->room->id,
            'valid'       => false,
            'millivolts'  => null,
            'percent'     => null,
            'received_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.node_battery_low', false)
            ->assertJsonPath('data.ac_status.node_battery_low', false);
    }

    // 9. Node offline tidak mengubah test_complete menjadi true
    public function test_node_offline_tidak_mengubah_test_complete_menjadi_true(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');

        $sample = NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 99887766,
            'telemetry_seq'        => 5,
            'telemetry_packet_seq' => 5,
            'sent_total'           => 5,
            'acked_total'          => 5,
            'failed_total'         => 0,
            'retries_total'        => 0,
            'pdr'                  => 100.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3800,
            'battery_percent'      => 60,
            'rssi_dbm'             => -70,
            'test_complete'        => false,
            'received_at'          => now(),
        ]);

        // Kirim event NODE_OFFLINE
        $this->command->handleNotification("room/{$this->room->id}/notification", json_encode([
            'type'    => 'NODE_OFFLINE',
            'room_id' => (string) $this->room->id,
            'message' => 'Node offline',
        ]));

        // Sample link metrics tidak boleh berubah menjadi test_complete=true
        $this->assertFalse($sample->fresh()->test_complete);

        // Endpoint session tetap test_complete=false
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/node-link-sessions/99887766");

        $response->assertStatus(200)
            ->assertJsonPath('data.test_complete', false);
    }

    // 10. Event low tanpa millivolts/percent valid ditolak tanpa merusak worker
    public function test_event_low_tanpa_millivolts_percent_valid_ditolak_tanpa_merusak_worker(): void
    {
        // 1. Tanpa millivolts dan percent
        $res1 = $this->command->handleNotification("room/{$this->room->id}/notification", json_encode([
            'type'    => 'NODE_BATTERY_LOW',
            'room_id' => (string) $this->room->id,
        ]));
        $this->assertFalse($res1);

        // 2. Dengan millivolts = null
        $res2 = $this->command->handleNotification("room/{$this->room->id}/notification", json_encode([
            'type'       => 'NODE_BATTERY_LOW',
            'room_id'    => (string) $this->room->id,
            'millivolts' => null,
            'percent'    => null,
        ]));
        $this->assertFalse($res2);

        // 3. Nilai di luar batas (misal percent = 50 bukan <= 20)
        $res3 = $this->command->handleNotification("room/{$this->room->id}/notification", json_encode([
            'type'       => 'NODE_BATTERY_LOW',
            'room_id'    => (string) $this->room->id,
            'millivolts' => 3800,
            'percent'    => 50,
        ]));
        $this->assertFalse($res3);

        // Pastikan tidak ada notifikasi palsu tersimpan dan tidak ada job fcm didispatch
        $this->assertDatabaseMissing('notifications', [
            'room_id' => $this->room->id,
            'type'    => 'NODE_BATTERY_LOW',
        ]);
        Bus::assertNotDispatched(SendFcmNotificationJob::class);
    }

    // 11. Topic telemetry, node telemetry, link metrics, dan ac/status lama tetap aman
    public function test_topic_telemetry_node_telemetry_link_metrics_dan_ac_status_lama_tetap_aman(): void
    {
        // 1. Telemetri ruangan lama
        $this->command->handleTelemetry("room/{$this->room->id}/telemetry", json_encode([
            'suhu'       => 26.5,
            'kelembaban' => 60.0,
        ]));
        $sensor = SensorLog::where('room_id', $this->room->id)->first();
        $this->assertNotNull($sensor);
        $this->assertEquals(26.5, $sensor->temperature);

        // 2. Node telemetri lama
        $this->command->handleNodeTelemetry("room/{$this->room->id}/node/telemetry", json_encode([
            'version'    => 1,
            'source'     => 'USER_NODE',
            'valid'      => true,
            'suhu'       => 25.8,
            'kelembaban' => 58.4,
        ]));
        $nodeTel = NodeTelemetryStatus::where('room_id', $this->room->id)->first();
        $this->assertNotNull($nodeTel);
        $this->assertEquals(25.8, $nodeTel->temperature);

        // 3. Link metrics
        $this->command->handleNodeLinkMetrics("room/{$this->room->id}/node/link-metrics", json_encode([
            'version'              => 1,
            'session_id'           => 12345678,
            'telemetry_seq'        => 1,
            'telemetry_packet_seq' => 1,
            'sent_total'           => 1,
            'acked_total'          => 1,
            'failed_total'         => 0,
            'retries_total'        => 0,
            'pdr'                  => 100.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3900,
            'battery_percent'      => 80,
            'rssi_dbm'             => -65,
            'test_complete'        => false,
        ]));
        $sample = NodeLinkSample::where('room_id', $this->room->id)->first();
        $this->assertNotNull($sample);
        $this->assertEquals(100.0, $sample->pdr);

        // 4. AC status
        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", json_encode([
            'power'            => true,
            'suhu'             => 24,
            'mode'             => 'MANUAL',
            'ac_mode'          => 'COOL',
            'node_paired'      => true,
            'node_connected'   => true,
            'node_battery_low' => false,
        ]));
        $ac = AcLog::where('room_id', $this->room->id)->first();
        $this->assertNotNull($ac);
        $this->assertEquals('ON', $ac->power);

        $remoteStatus = RemoteDeviceStatus::where('room_id', $this->room->id)->first();
        $this->assertNotNull($remoteStatus);
    }
}
