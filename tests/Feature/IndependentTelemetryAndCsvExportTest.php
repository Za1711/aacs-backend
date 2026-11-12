<?php

namespace Tests\Feature;

use App\Console\Commands\MqttSubscribeCommand;
use App\Models\AcLog;
use App\Models\Device;
use App\Models\NodeLinkSample;
use App\Models\NodeTelemetryLog;
use App\Models\NodeTelemetryStatus;
use App\Models\RemoteDeviceStatus;
use App\Models\RemoteTelemetryLog;
use App\Models\RemoteTelemetryStatus;
use App\Models\Room;
use App\Models\SensorLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class IndependentTelemetryAndCsvExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Room $room;
    private MqttSubscribeCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->user = User::factory()->create();
        $this->room = Room::create([
            'user_id'  => $this->user->id,
            'name'     => 'Lab IoT 119',
            'ac_brand' => 'Panasonic',
        ]);

        Device::create([
            'room_id'     => $this->room->id,
            'user_id'     => $this->user->id,
            'name'        => 'ESP32 Remote',
            'device_type' => 'ESP32',
            'mac_address' => 'AA:BB:CC:DD:EE:01',
            'is_paired'   => true,
            'status'      => 'ONLINE',
            'last_ping'   => now(),
        ]);

        $this->command = app(MqttSubscribeCommand::class);
    }

    /**
     * 1. Remote telemetry valid disimpan ke status & log.
     */
    public function test_01_valid_remote_telemetry_saved_to_status_and_log(): void
    {
        $topic = "room/{$this->room->id}/remote/telemetry";
        $payload = json_encode([
            'version'    => 1,
            'source'     => 'REMOTE',
            'valid'      => true,
            'suhu'       => 25.12,
            'kelembaban' => 63.40,
        ]);

        $handled = $this->command->handleRemoteTelemetry($topic, $payload);
        $this->assertTrue($handled);

        $status = RemoteTelemetryStatus::where('room_id', $this->room->id)->first();
        $this->assertNotNull($status);
        $this->assertTrue($status->valid);
        $this->assertEquals(25.12, (float) $status->temperature);
        $this->assertEquals(63.40, (float) $status->humidity);
        $this->assertNotNull($status->received_at);

        $log = RemoteTelemetryLog::where('room_id', $this->room->id)->first();
        $this->assertNotNull($log);
        $this->assertTrue($log->valid);
        $this->assertEquals(25.12, (float) $log->temperature);
        $this->assertEquals(63.40, (float) $log->humidity);
        $this->assertNotNull($log->received_at);
    }

    /**
     * 2. Remote telemetry invalid menyimpan null (bukan 0).
     */
    public function test_02_invalid_remote_telemetry_saves_null_and_never_zero(): void
    {
        $topic = "room/{$this->room->id}/remote/telemetry";
        $payload = json_encode([
            'version'    => 1,
            'source'     => 'REMOTE',
            'valid'      => false,
            'suhu'       => null,
            'kelembaban' => null,
        ]);

        $handled = $this->command->handleRemoteTelemetry($topic, $payload);
        $this->assertTrue($handled);

        $status = RemoteTelemetryStatus::where('room_id', $this->room->id)->first();
        $this->assertNotNull($status);
        $this->assertFalse($status->valid);
        $this->assertNull($status->temperature);
        $this->assertNull($status->humidity);

        $log = RemoteTelemetryLog::where('room_id', $this->room->id)->first();
        $this->assertNotNull($log);
        $this->assertFalse($log->valid);
        $this->assertNull($log->temperature);
        $this->assertNull($log->humidity);
    }

    /**
     * 3. Remote telemetry tetap disimpan saat control_source aktif adalah USER_NODE.
     */
    public function test_03_remote_telemetry_saved_when_control_source_is_user_node(): void
    {
        AcLog::create([
            'room_id'        => $this->room->id,
            'control_source' => 'USER_NODE',
            'node_paired'    => true,
            'node_connected' => true,
            'power'          => 'ON',
            'setpoint'       => 24,
            'mode'           => 'MANUAL',
            'ac_mode'        => 'COOL',
            'fan_speed'      => 'AUTO',
            'swing'          => 'AUTO',
        ]);

        $topic = "room/{$this->room->id}/remote/telemetry";
        $payload = json_encode([
            'version'    => 1,
            'source'     => 'REMOTE',
            'valid'      => true,
            'suhu'       => 26.50,
            'kelembaban' => 58.20,
        ]);

        $handled = $this->command->handleRemoteTelemetry($topic, $payload);
        $this->assertTrue($handled);

        $status = RemoteTelemetryStatus::where('room_id', $this->room->id)->first();
        $this->assertNotNull($status);
        $this->assertEquals(26.50, (float) $status->temperature);
    }

    /**
     * 4. Node dan Remote dapat memiliki data pada timestamp yang sama secara independen.
     */
    public function test_04_node_and_remote_have_independent_data(): void
    {
        $now = Carbon::now('UTC');

        // Kirim Node Telemetry
        $this->command->handleNodeTelemetry(
            "room/{$this->room->id}/node/telemetry",
            json_encode([
                'version'    => 1,
                'source'     => 'USER_NODE',
                'valid'      => true,
                'suhu'       => 23.80,
                'kelembaban' => 70.10,
            ])
        );

        // Kirim Remote Telemetry
        $this->command->handleRemoteTelemetry(
            "room/{$this->room->id}/remote/telemetry",
            json_encode([
                'version'    => 1,
                'source'     => 'REMOTE',
                'valid'      => true,
                'suhu'       => 25.40,
                'kelembaban' => 62.30,
            ])
        );

        $nodeStatus = NodeTelemetryStatus::where('room_id', $this->room->id)->first();
        $remoteStatus = RemoteTelemetryStatus::where('room_id', $this->room->id)->first();

        $this->assertNotNull($nodeStatus);
        $this->assertNotNull($remoteStatus);
        $this->assertEquals(23.80, (float) $nodeStatus->temperature);
        $this->assertEquals(25.40, (float) $remoteStatus->temperature);
    }

    /**
     * 5. Active sensor menyimpan source yang sesuai ('USER_NODE' / 'REMOTE').
     */
    public function test_05_active_sensor_stores_source(): void
    {
        // USER_NODE
        $this->command->handleTelemetry(
            "room/{$this->room->id}/telemetry",
            json_encode([
                'version'    => 1,
                'source'     => 'USER_NODE',
                'valid'      => true,
                'suhu'       => 24.10,
                'kelembaban' => 65.20,
            ])
        );

        $log1 = SensorLog::where('room_id', $this->room->id)->latest('id')->first();
        $this->assertEquals('USER_NODE', $log1->source);
        $this->assertTrue((bool) $log1->valid);

        // REMOTE
        $this->command->handleTelemetry(
            "room/{$this->room->id}/telemetry",
            json_encode([
                'version'    => 1,
                'source'     => 'REMOTE',
                'valid'      => true,
                'suhu'       => 25.90,
                'kelembaban' => 61.00,
            ])
        );

        $log2 = SensorLog::where('room_id', $this->room->id)->latest('id')->first();
        $this->assertEquals('REMOTE', $log2->source);
        $this->assertTrue((bool) $log2->valid);
    }

    /**
     * 6. Legacy payload sensor tanpa field source tetap diterima dengan fallback 'UNKNOWN'.
     */
    public function test_06_legacy_telemetry_payload_fallback_unknown(): void
    {
        $this->command->handleTelemetry(
            "room/{$this->room->id}/telemetry",
            json_encode([
                'version'    => 1,
                'suhu'       => 24.00,
                'kelembaban' => 60.00,
            ])
        );

        $log = SensorLog::where('room_id', $this->room->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals('UNKNOWN', $log->source);
        $this->assertTrue((bool) $log->valid);
    }

    /**
     * 7. Endpoint latest-status tetap mempertahankan struktur lama 'sensor'.
     */
    public function test_07_latest_status_retains_sensor_structure(): void
    {
        SensorLog::create([
            'room_id'     => $this->room->id,
            'temperature' => 24.50,
            'humidity'    => 62.00,
            'source'      => 'USER_NODE',
            'valid'       => true,
            'created_at'  => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                'sensor' => [
                    'temperature',
                    'humidity',
                    'updated_at',
                    'is_stale',
                    'source',
                ],
            ],
        ]);
        $response->assertJsonPath('data.sensor.temperature', 24.5);
        $this->assertEquals(62.0, (float) $response->json('data.sensor.humidity'));
        $response->assertJsonPath('data.sensor.source', 'USER_NODE');
    }

    /**
     * 8. Endpoint latest-status menambahkan active_sensor, remote_sensor, dan node_sensor.
     */
    public function test_08_latest_status_adds_active_remote_and_node_sensor(): void
    {
        RemoteTelemetryStatus::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 25.80,
            'humidity'    => 60.50,
            'received_at' => now(),
        ]);

        NodeTelemetryStatus::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 24.20,
            'humidity'    => 68.30,
            'received_at' => now(),
        ]);

        SensorLog::create([
            'room_id'     => $this->room->id,
            'temperature' => 24.20,
            'humidity'    => 68.30,
            'source'      => 'USER_NODE',
            'valid'       => true,
            'created_at'  => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                'active_sensor' => ['temperature', 'humidity', 'updated_at', 'is_stale', 'source'],
                'active_source',
                'remote_sensor' => ['temperature', 'humidity', 'updated_at', 'is_stale', 'valid'],
                'node_sensor'   => ['temperature', 'humidity', 'updated_at', 'is_stale', 'valid'],
            ],
        ]);

        $response->assertJsonPath('data.active_source', 'USER_NODE');
        $response->assertJsonPath('data.remote_sensor.temperature', 25.8);
        $response->assertJsonPath('data.node_sensor.temperature', 24.2);
    }

    /**
     * 9. Remote sensor timeout stale adalah 20 detik.
     */
    public function test_09_remote_sensor_timeout_20_seconds(): void
    {
        // 10 detik lalu -> fresh
        RemoteTelemetryStatus::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 25.00,
            'humidity'    => 60.00,
            'received_at' => Carbon::now('UTC')->subSeconds(10),
        ]);

        $res1 = $this->actingAs($this->user)->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $res1->assertJsonPath('data.remote_sensor.is_stale', false);

        // 25 detik lalu -> stale
        RemoteTelemetryStatus::where('room_id', $this->room->id)->update([
            'received_at' => Carbon::now('UTC')->subSeconds(25),
        ]);

        $res2 = $this->actingAs($this->user)->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $res2->assertJsonPath('data.remote_sensor.is_stale', true);
    }

    /**
     * 10. Node sensor timeout stale adalah 60 detik.
     */
    public function test_10_node_sensor_timeout_60_seconds(): void
    {
        // 45 detik lalu -> fresh
        NodeTelemetryStatus::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 24.00,
            'humidity'    => 65.00,
            'received_at' => Carbon::now('UTC')->subSeconds(45),
        ]);

        $res1 = $this->actingAs($this->user)->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $res1->assertJsonPath('data.node_sensor.is_stale', false);

        // 65 detik lalu -> stale
        NodeTelemetryStatus::where('room_id', $this->room->id)->update([
            'received_at' => Carbon::now('UTC')->subSeconds(65),
        ]);

        $res2 = $this->actingAs($this->user)->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $res2->assertJsonPath('data.node_sensor.is_stale', true);
    }

    /**
     * 11. CSV export Remote berisi semua baris mentah.
     */
    public function test_11_remote_csv_export_contains_all_rows(): void
    {
        RemoteTelemetryLog::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 25.10,
            'humidity'    => 60.10,
            'received_at' => Carbon::now('UTC')->subMinutes(5),
        ]);

        RemoteTelemetryLog::create([
            'room_id'     => $this->room->id,
            'valid'       => false,
            'temperature' => null,
            'humidity'    => null,
            'received_at' => Carbon::now('UTC')->subMinutes(2),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/remote-telemetry/export.csv");
        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString('ID,Room_ID,Source,Valid,Timestamp_UTC,Timestamp_WIB,Temperature_C,Humidity_Percent', $content);
        $this->assertStringContainsString('REMOTE', $content);
        $this->assertStringContainsString('25.10', $content);
        $this->assertStringContainsString('TRUE', $content);
        $this->assertStringContainsString('FALSE', $content);
    }

    /**
     * 12. CSV export Node berisi semua baris mentah.
     */
    public function test_12_node_csv_export_contains_all_rows(): void
    {
        NodeTelemetryLog::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 24.15,
            'humidity'    => 68.20,
            'received_at' => Carbon::now('UTC')->subMinutes(4),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-telemetry/export.csv");
        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString('ID,Room_ID,Source,Valid,Timestamp_UTC,Timestamp_WIB,Temperature_C,Humidity_Percent', $content);
        $this->assertStringContainsString('USER_NODE', $content);
        $this->assertStringContainsString('24.15', $content);
        $this->assertStringContainsString('68.20', $content);
    }

    /**
     * 13. Combined CSV export menggabungkan data Remote dan Node dengan label source.
     */
    public function test_13_combined_csv_export_merges_sources(): void
    {
        RemoteTelemetryLog::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 25.50,
            'humidity'    => 61.00,
            'received_at' => Carbon::now('UTC')->subMinutes(3),
        ]);

        NodeTelemetryLog::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 24.30,
            'humidity'    => 67.50,
            'received_at' => Carbon::now('UTC')->subMinutes(2),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/sensor-sources/export.csv");
        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString('Record_Type,ID,Room_ID,Source,Valid,Timestamp_UTC,Timestamp_WIB,Temperature_C,Humidity_Percent', $content);
        $this->assertStringContainsString('SAMPLE', $content);
        $this->assertStringContainsString('REMOTE', $content);
        $this->assertStringContainsString('USER_NODE', $content);
        $this->assertStringContainsString('25.50', $content);
        $this->assertStringContainsString('24.30', $content);
    }

    /**
     * 14. Combined CSV export terurut berdasarkan timestamp ASC.
     */
    public function test_14_combined_csv_export_ordered_by_timestamp_asc(): void
    {
        // 10 menit lalu
        RemoteTelemetryLog::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 21.00,
            'humidity'    => 50.00,
            'received_at' => Carbon::now('UTC')->subMinutes(10),
        ]);

        // 5 menit lalu
        NodeTelemetryLog::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 22.00,
            'humidity'    => 52.00,
            'received_at' => Carbon::now('UTC')->subMinutes(5),
        ]);

        // 1 menit lalu
        RemoteTelemetryLog::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 23.00,
            'humidity'    => 54.00,
            'received_at' => Carbon::now('UTC')->subMinutes(1),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/sensor-sources/export.csv");
        $response->assertStatus(200);
        $content = $response->streamedContent();

        $pos21 = strpos($content, '21.00');
        $pos22 = strpos($content, '22.00');
        $pos23 = strpos($content, '23.00');

        $this->assertTrue($pos21 !== false && $pos22 !== false && $pos23 !== false);
        $this->assertTrue($pos21 < $pos22);
        $this->assertTrue($pos22 < $pos23);
    }

    /**
     * 15. Node link metrics menyimpan data suhu dan kelembaban baru (temperature_c, humidity_percent, sensor_valid).
     */
    public function test_15_node_link_metrics_stores_new_sensor_fields(): void
    {
        $topic = "room/{$this->room->id}/node/link-metrics";
        $payload = json_encode([
            'version'              => 1,
            'session_id'           => 101,
            'telemetry_seq'        => 1,
            'telemetry_packet_seq' => 1,
            'sent_total'           => 1,
            'acked_total'          => 1,
            'failed_total'         => 0,
            'retries_total'        => 0,
            'pdr'                  => 100.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'rssi_dbm'             => -65,
            'battery_valid'        => true,
            'battery_mv'           => 3300,
            'battery_percent'      => 80,
            'test_complete'        => false,
            'sensor_valid'         => true,
            'temperature_c'        => 24.45,
            'humidity_percent'     => 69.10,
        ]);

        $handled = $this->command->handleNodeLinkMetrics($topic, $payload);
        $this->assertTrue($handled);

        $sample = NodeLinkSample::where('room_id', $this->room->id)->where('session_id', 101)->first();
        $this->assertNotNull($sample);
        $this->assertTrue((bool) $sample->sensor_valid);
        $this->assertEquals(24.45, (float) $sample->temperature_c);
        $this->assertEquals(69.10, (float) $sample->humidity_percent);
    }

    /**
     * 16. Legacy payload link metrics tanpa data sensor tetap diterima dan valid.
     */
    public function test_16_legacy_node_link_metrics_without_sensor_fields_accepted(): void
    {
        $topic = "room/{$this->room->id}/node/link-metrics";
        $payload = json_encode([
            'version'              => 1,
            'session_id'           => 102,
            'telemetry_seq'        => 1,
            'telemetry_packet_seq' => 1,
            'sent_total'           => 1,
            'acked_total'          => 1,
            'failed_total'         => 0,
            'retries_total'        => 0,
            'pdr'                  => 100.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'rssi_dbm'             => -68,
            'battery_valid'        => true,
            'battery_mv'           => 3250,
            'battery_percent'      => 75,
            'test_complete'        => false,
        ]);

        $handled = $this->command->handleNodeLinkMetrics($topic, $payload);
        $this->assertTrue($handled);

        $sample = NodeLinkSample::where('room_id', $this->room->id)->where('session_id', 102)->first();
        $this->assertNotNull($sample);
        $this->assertFalse((bool) $sample->sensor_valid);
        $this->assertNull($sample->temperature_c);
        $this->assertNull($sample->humidity_percent);
    }

    /**
     * 17. Paket gagal pada link metrics menghasilkan null pada suhu, kelembaban, dan rssi.
     */
    public function test_17_failed_packet_link_metrics_yields_null(): void
    {
        $topic = "room/{$this->room->id}/node/link-metrics";
        $payload = json_encode([
            'version'              => 1,
            'session_id'           => 103,
            'telemetry_seq'        => 2,
            'telemetry_packet_seq' => 2,
            'sent_total'           => 2,
            'acked_total'          => 1,
            'failed_total'         => 1,
            'retries_total'        => 1,
            'pdr'                  => 50.0,
            'telemetry_acked'      => false,
            'telemetry_received'   => false,
            'rssi_dbm'             => null,
            'battery_valid'        => false,
            'battery_mv'           => null,
            'battery_percent'      => null,
            'test_complete'        => false,
            'sensor_valid'         => false,
            'temperature_c'        => null,
            'humidity_percent'     => null,
        ]);

        $handled = $this->command->handleNodeLinkMetrics($topic, $payload);
        $this->assertTrue($handled);

        $sample = NodeLinkSample::where('room_id', $this->room->id)->where('session_id', 103)->first();
        $this->assertNotNull($sample);
        $this->assertFalse((bool) $sample->telemetry_acked);
        $this->assertFalse((bool) $sample->sensor_valid);
        $this->assertNull($sample->temperature_c);
        $this->assertNull($sample->humidity_percent);
        $this->assertNull($sample->rssi_dbm);
    }

    /**
     * 18. PDR dihitung berdasarkan counter kumulatif (acked_total / sent_total).
     */
    public function test_18_pdr_and_session_export_csv(): void
    {
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 104,
            'telemetry_seq'        => 1,
            'telemetry_packet_seq' => 1,
            'sent_total'           => 10,
            'acked_total'          => 8,
            'failed_total'         => 2,
            'retries_total'        => 1,
            'pdr'                  => 80.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'sensor_valid'         => true,
            'temperature_c'        => 24.50,
            'humidity_percent'     => 65.00,
            'battery_valid'        => true,
            'battery_mv'           => 3300,
            'battery_percent'      => 80,
            'rssi_dbm'             => -65,
            'test_complete'        => false,
            'received_at'          => now(),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-sessions/104/export.csv");
        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString('Sensor_Valid,Temperature_C,Humidity_Percent', $content);
        $this->assertStringContainsString('SAMPLE', $content);
        $this->assertStringContainsString('SUMMARY', $content);
        $this->assertStringContainsString('80.000', $content);
        $this->assertStringContainsString('24.50', $content);
    }

    /**
     * 19. UI dashboard menampilkan 3 card unduhan baru dan tombol sesi terupdate.
     */
    public function test_19_dashboard_displays_new_cards_and_updated_session_label(): void
    {
        $response = $this->get("/dashboard?room_id={$this->room->id}");
        $response->assertStatus(200);

        $response->assertSee('Telemetri Remote');
        $response->assertSee('Telemetri Node');
        $response->assertSee('Perbandingan Remote & Node', false);
        $response->assertSee('Unduh CSV Remote');
        $response->assertSee('Unduh CSV Node');
        $response->assertSee('Unduh CSV Perbandingan');
        $response->assertSee('Telemetri Node & Link Metrics ESP-NOW', false);
    }

    /**
     * 20. Unpair room membersihkan RemoteTelemetryStatus.
     */
    public function test_20_unpair_room_cleans_up_remote_telemetry_status(): void
    {
        RemoteTelemetryStatus::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 25.00,
            'humidity'    => 60.00,
            'received_at' => now(),
        ]);

        NodeTelemetryStatus::create([
            'room_id'     => $this->room->id,
            'valid'       => true,
            'temperature' => 24.00,
            'humidity'    => 65.00,
            'received_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/rooms/{$this->room->id}/unpair");
        $response->assertStatus(200);

        $this->assertDatabaseMissing('remote_telemetry_statuses', ['room_id' => $this->room->id]);
        $this->assertDatabaseMissing('node_telemetry_statuses', ['room_id' => $this->room->id]);
    }
}
