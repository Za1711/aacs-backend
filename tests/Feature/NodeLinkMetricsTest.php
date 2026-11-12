<?php

namespace Tests\Feature;

use App\Console\Commands\MqttSubscribeCommand;
use App\Models\AcLog;
use App\Models\Device;
use App\Models\NodeBatteryStatus;
use App\Models\NodeLinkSample;
use App\Models\RemoteDeviceStatus;
use App\Models\Room;
use App\Models\SensorLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NodeLinkMetricsTest extends TestCase
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
            'name'            => 'Lab IoT Link Metrics',
            'ac_brand'        => 'Daikin',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ]);

        $this->device = Device::create([
            'room_id'       => $this->room->id,
            'user_id'       => $this->user->id,
            'device_type'   => 'ESP32',
            'serial_number' => 'ESP32-LINK-TEST',
            'status'        => 'ONLINE',
            'is_paired'     => true,
            'last_ping'     => now(),
        ]);

        $this->command = app(MqttSubscribeCommand::class);
    }

    /**
     * 1. Sampel pertama dengan test_complete=false langsung muncul pada endpoint current
     */
    public function test_first_sample_with_test_complete_false_appears_in_current_endpoint(): void
    {
        $payload = [
            'version'              => 1,
            'session_id'           => 1001,
            'telemetry_seq'        => 1,
            'telemetry_packet_seq' => 10,
            'sent_total'           => 1,
            'acked_total'          => 1,
            'failed_total'         => 0,
            'retries_total'        => 0,
            'pdr'                  => 100.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 4050,
            'battery_percent'      => 98,
            'rssi_dbm'             => -68,
            'test_complete'        => false,
        ];

        $topic = "room/{$this->room->id}/node/link-metrics";
        $this->command->handleNodeLinkMetrics($topic, json_encode($payload));

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/node-link-sessions/current");

        $res->assertStatus(200);
        $res->assertJsonPath('status', 'success');
        $res->assertJsonPath('data.session_id', 1001);
        $res->assertJsonPath('data.state', 'ACTIVE');
        $res->assertJsonPath('data.test_complete', false);
        $this->assertEquals(100.0, $res->json('data.pdr_percent'));
        $res->assertJsonPath('data.last_delivery_success', true);
        $res->assertJsonPath('data.rssi.latest_dbm', -68);
    }

    /**
     * 2. Session ACTIVE muncul pada daftar session
     */
    public function test_active_session_appears_in_session_list(): void
    {
        $payload = [
            'version'              => 1,
            'session_id'           => 1002,
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
            'rssi_dbm'             => -72,
            'test_complete'        => false,
        ];

        $topic = "room/{$this->room->id}/node/link-metrics";
        $this->command->handleNodeLinkMetrics($topic, json_encode($payload));

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/node-link-sessions");

        $res->assertStatus(200);
        $sessions = $res->json('data');
        $this->assertNotEmpty($sessions);
        $found = collect($sessions)->firstWhere('session_id', 1002);
        $this->assertNotNull($found);
        $this->assertEquals('ACTIVE', $found['state']);
        $this->assertFalse($found['test_complete']);
    }

    /**
     * 3 & 4. Row berhasil dan gagal sama-sama muncul pada endpoint samples & delivery_success sama dengan telemetry_acked
     */
    public function test_success_and_failed_rows_both_appear_in_samples_endpoint(): void
    {
        $sessionId = 1003;
        // Sample 1: Success
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => $sessionId,
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
            'battery_mv'           => 3950,
            'battery_percent'      => 85,
            'rssi_dbm'             => -70,
            'test_complete'        => false,
            'received_at'          => now(),
        ]);

        // Sample 2: Failed
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => $sessionId,
            'telemetry_seq'        => 2,
            'telemetry_packet_seq' => 2,
            'sent_total'           => 2,
            'acked_total'          => 1,
            'failed_total'         => 1,
            'retries_total'        => 2,
            'pdr'                  => 50.0,
            'telemetry_acked'      => false,
            'telemetry_received'   => false,
            'battery_valid'        => true,
            'battery_mv'           => 3940,
            'battery_percent'      => 85,
            'rssi_dbm'             => null,
            'test_complete'        => false,
            'received_at'          => now(),
        ]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/node-link-sessions/{$sessionId}/samples");

        $res->assertStatus(200);
        $items = $res->json('data');
        $this->assertCount(2, $items);

        // Check sample 1
        $this->assertEquals(1, $items[0]['telemetry_seq']);
        $this->assertTrue($items[0]['delivery_success']);
        $this->assertTrue($items[0]['telemetry_received']);
        $this->assertEquals(-70, $items[0]['rssi_dbm']);

        // Check sample 2
        $this->assertEquals(2, $items[1]['telemetry_seq']);
        $this->assertFalse($items[1]['delivery_success']);
        $this->assertFalse($items[1]['telemetry_received']);
        $this->assertNull($items[1]['rssi_dbm']);
    }

    /**
     * 5. PDR dihitung dari counter terakhir, bukan jumlah row database
     */
    public function test_pdr_calculated_from_latest_counter_not_row_count(): void
    {
        $sessionId = 1005;
        // Hanya ada 2 row tersimpan di DB, tapi counter sent_total = 100 dan acked_total = 95
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => $sessionId,
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
            'battery_mv'           => 4000,
            'battery_percent'      => 90,
            'rssi_dbm'             => -65,
            'test_complete'        => false,
            'received_at'          => now(),
        ]);

        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => $sessionId,
            'telemetry_seq'        => 100,
            'telemetry_packet_seq' => 120,
            'sent_total'           => 100,
            'acked_total'          => 95,
            'failed_total'         => 5,
            'retries_total'        => 10,
            'pdr'                  => 95.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3700,
            'battery_percent'      => 60,
            'rssi_dbm'             => -75,
            'test_complete'        => false,
            'received_at'          => now(),
        ]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/node-link-sessions/{$sessionId}");

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertEquals(2, $data['samples_visible']);
        $this->assertEquals(100, $data['sent_total']);
        $this->assertEquals(95, $data['acked_total']);
        $this->assertEquals(95.0, $data['pdr_percent']);
    }

    /**
     * 6. Counter gap akibat MQTT tidak merusak PDR keseluruhan
     */
    public function test_counter_gap_due_to_mqtt_does_not_break_overall_pdr(): void
    {
        $sessionId = 1006;
        $topic = "room/{$this->room->id}/node/link-metrics";

        // Seq 1 masuk
        $this->command->handleNodeLinkMetrics($topic, json_encode([
            'version'              => 1,
            'session_id'           => $sessionId,
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
            'battery_mv'           => 4000,
            'battery_percent'      => 90,
            'rssi_dbm'             => -65,
            'test_complete'        => false,
        ]));

        // MQTT putus seq 2..9, lalu seq 10 masuk dengan counter kumulatif
        $this->command->handleNodeLinkMetrics($topic, json_encode([
            'version'              => 1,
            'session_id'           => $sessionId,
            'telemetry_seq'        => 10,
            'telemetry_packet_seq' => 15,
            'sent_total'           => 10,
            'acked_total'          => 8,
            'failed_total'         => 2,
            'retries_total'        => 3,
            'pdr'                  => 80.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3900,
            'battery_percent'      => 80,
            'rssi_dbm'             => -70,
            'test_complete'        => false,
        ]));

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/node-link-sessions/{$sessionId}");

        $res->assertStatus(200);
        $this->assertEquals(2, $res->json('data.samples_visible'));
        $this->assertEquals(80.0, $res->json('data.pdr_percent'));
    }

    /**
     * 7, 8 & 9. RSSI average/minimum/maximum hanya memakai RSSI valid non-null, -90 minimum, -55 maximum, null tidak dihitung 0
     */
    public function test_rssi_statistics_calculation_and_extremes(): void
    {
        $sessionId = 1007;

        // Sample 1: RSSI -90
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => $sessionId,
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
            'battery_mv'           => 4000,
            'battery_percent'      => 90,
            'rssi_dbm'             => -90,
            'test_complete'        => false,
            'received_at'          => now(),
        ]);

        // Sample 2: Failed packet, null RSSI (harus diabaikan, jangan diubah ke 0!)
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => $sessionId,
            'telemetry_seq'        => 2,
            'telemetry_packet_seq' => 2,
            'sent_total'           => 2,
            'acked_total'          => 1,
            'failed_total'         => 1,
            'retries_total'        => 1,
            'pdr'                  => 50.0,
            'telemetry_acked'      => false,
            'telemetry_received'   => false,
            'battery_valid'        => true,
            'battery_mv'           => 3990,
            'battery_percent'      => 90,
            'rssi_dbm'             => null,
            'test_complete'        => false,
            'received_at'          => now(),
        ]);

        // Sample 3: RSSI -55
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => $sessionId,
            'telemetry_seq'        => 3,
            'telemetry_packet_seq' => 3,
            'sent_total'           => 3,
            'acked_total'          => 2,
            'failed_total'         => 1,
            'retries_total'        => 1,
            'pdr'                  => 66.67,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3980,
            'battery_percent'      => 90,
            'rssi_dbm'             => -55,
            'test_complete'        => false,
            'received_at'          => now(),
        ]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/node-link-sessions/{$sessionId}");

        $res->assertStatus(200);
        $rssi = $res->json('data.rssi');

        // Jumlah valid: 2 (bukan 3)
        $this->assertEquals(2, $rssi['count']);
        // Average: (-90 + -55) / 2 = -72.5
        $this->assertEquals(-72.5, $rssi['average_dbm']);
        // Minimum: -90
        $this->assertEquals(-90, $rssi['minimum_dbm']);
        // Maximum: -55
        $this->assertEquals(-55, $rssi['maximum_dbm']);
        // Latest: -55
        $this->assertEquals(-55, $rssi['latest_dbm']);
    }

    /**
     * 10. Statistik berubah setelah payload berikutnya masuk tanpa menunggu complete
     */
    public function test_stats_update_live_on_subsequent_payload(): void
    {
        $sessionId = 1010;
        $topic = "room/{$this->room->id}/node/link-metrics";

        $this->command->handleNodeLinkMetrics($topic, json_encode([
            'version'              => 1,
            'session_id'           => $sessionId,
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
            'battery_mv'           => 4000,
            'battery_percent'      => 90,
            'rssi_dbm'             => -60,
            'test_complete'        => false,
        ]));

        $res1 = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/node-link-sessions/current");
        $this->assertEquals(100.0, $res1->json('data.pdr_percent'));
        $res1->assertJsonPath('data.consecutive_failures', 0);

        // Kirim payload kedua: gagal
        $this->command->handleNodeLinkMetrics($topic, json_encode([
            'version'              => 1,
            'session_id'           => $sessionId,
            'telemetry_seq'        => 2,
            'telemetry_packet_seq' => 2,
            'sent_total'           => 2,
            'acked_total'          => 1,
            'failed_total'         => 1,
            'retries_total'        => 1,
            'pdr'                  => 50.0,
            'telemetry_acked'      => false,
            'telemetry_received'   => false,
            'battery_valid'        => true,
            'battery_mv'           => 3980,
            'battery_percent'      => 89,
            'rssi_dbm'             => null,
            'test_complete'        => false,
        ]));

        $res2 = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/node-link-sessions/current");
        $this->assertEquals(50.0, $res2->json('data.pdr_percent'));
        $res2->assertJsonPath('data.consecutive_failures', 1);
        $res2->assertJsonPath('data.last_delivery_success', false);
    }

    /**
     * 11. test_complete=true mengubah state menjadi COMPLETED
     */
    public function test_test_complete_true_changes_state_to_completed(): void
    {
        $sessionId = 1011;
        $topic = "room/{$this->room->id}/node/link-metrics";

        $this->command->handleNodeLinkMetrics($topic, json_encode([
            'version'              => 1,
            'session_id'           => $sessionId,
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
            'battery_mv'           => 3200,
            'battery_percent'      => 20,
            'rssi_dbm'             => -75,
            'test_complete'        => true,
        ]));

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/node-link-sessions/current");

        $res->assertStatus(200);
        $res->assertJsonPath('data.state', 'COMPLETED');
        $res->assertJsonPath('data.test_complete', true);
    }

    /**
     * 12. Node offline / stale tanpa complete menghasilkan STALE, bukan COMPLETED
     */
    public function test_stale_without_complete_results_in_stale(): void
    {
        $sessionId = 1012;
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => $sessionId,
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
            'battery_mv'           => 3800,
            'battery_percent'      => 75,
            'rssi_dbm'             => -70,
            'test_complete'        => false,
            'received_at'          => Carbon::now('UTC')->subSeconds(60), // Leewati timeout 30s
        ]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/node-link-sessions/current");

        $res->assertStatus(200);
        $res->assertJsonPath('data.state', 'STALE');
        $this->assertNotEquals('COMPLETED', $res->json('data.state'));
    }

    /**
     * 13 & 14. Endpoint latest-status mengembalikan node_link dengan tipe data konsisten & status saat belum ada data
     */
    public function test_latest_status_returns_node_link_with_correct_structure(): void
    {
        // Kondisi 1: Belum ada data
        $resNoData = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $resNoData->assertStatus(200);
        $nodeLinkNoData = $resNoData->json('data.node_link');
        $this->assertFalse($nodeLinkNoData['available']);
        $this->assertEquals('NO_DATA', $nodeLinkNoData['state']);
        $this->assertNull($nodeLinkNoData['session_id']);
        $this->assertNull($nodeLinkNoData['last_rssi_dbm']);
        $this->assertNull($nodeLinkNoData['pdr_percent']);
        $this->assertTrue($nodeLinkNoData['is_stale']);

        // Kondisi 2: Ada data link-metrics
        $topic = "room/{$this->room->id}/node/link-metrics";
        $this->command->handleNodeLinkMetrics($topic, json_encode([
            'version'              => 1,
            'session_id'           => 2001,
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
            'rssi_dbm'             => -71,
            'test_complete'        => false,
        ]));

        $resData = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $resData->assertStatus(200);
        $nodeLink = $resData->json('data.node_link');
        $this->assertTrue($nodeLink['available']);
        $this->assertEquals(2001, $nodeLink['session_id']);
        $this->assertEquals('ACTIVE', $nodeLink['state']);
        $this->assertTrue($nodeLink['last_delivery_success']);
        $this->assertEquals(-71, $nodeLink['last_rssi_dbm']);
        $this->assertEquals(100.0, $nodeLink['pdr_percent']);
        $this->assertFalse($nodeLink['is_stale']);
        $this->assertFalse($nodeLink['test_complete']);
    }

    /**
     * 15, 16, 17, 18. Ekspor CSV ACTIVE, format SAMPLE & SUMMARY row, dan filter
     */
    public function test_csv_export_format_with_sample_and_summary_rows(): void
    {
        $sessionId = 3001;

        // Sample 1: Success
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => $sessionId,
            'telemetry_seq'        => 1,
            'telemetry_packet_seq' => 101,
            'sent_total'           => 1,
            'acked_total'          => 1,
            'failed_total'         => 0,
            'retries_total'        => 0,
            'pdr'                  => 100.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3950,
            'battery_percent'      => 95,
            'rssi_dbm'             => -68,
            'test_complete'        => false,
            'received_at'          => Carbon::parse('2026-11-07 17:41:03'),
        ]);

        // Sample 2: Success
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => $sessionId,
            'telemetry_seq'        => 2,
            'telemetry_packet_seq' => 102,
            'sent_total'           => 2,
            'acked_total'          => 2,
            'failed_total'         => 0,
            'retries_total'        => 0,
            'pdr'                  => 100.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3948,
            'battery_percent'      => 95,
            'rssi_dbm'             => -70,
            'test_complete'        => false,
            'received_at'          => Carbon::parse('2026-11-07 17:41:08'),
        ]);

        // Sample 3: Failed total
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => $sessionId,
            'telemetry_seq'        => 3,
            'telemetry_packet_seq' => 103,
            'sent_total'           => 3,
            'acked_total'          => 2,
            'failed_total'         => 1,
            'retries_total'        => 1,
            'pdr'                  => 66.667,
            'telemetry_acked'      => false,
            'telemetry_received'   => false,
            'battery_valid'        => true,
            'battery_mv'           => 3946,
            'battery_percent'      => 94,
            'rssi_dbm'             => null,
            'test_complete'        => false,
            'received_at'          => Carbon::parse('2026-11-07 17:41:13'),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-sessions/{$sessionId}/export.csv");

        $response->assertStatus(200);
        $content = $response->streamedContent();

        $lines = array_filter(explode("\n", str_replace(["\r\n", "\r"], "\n", $content)));

        // Header check
        $this->assertStringContainsString('Record_Type,Room_ID,Session_ID,Telemetry_Seq,Telemetry_Packet_Seq', $lines[0]);
        $this->assertStringContainsString('RSSI_dBm', $lines[0]);
        $this->assertStringContainsString('PDR_Percent', $lines[0]);
        $this->assertStringContainsString('Sequence_Gap', $lines[0]);

        $sampleLines = [];
        $summaryLines = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'SAMPLE,')) {
                $sampleLines[] = $line;
            } elseif (str_starts_with($line, 'SUMMARY,')) {
                $summaryLines[] = $line;
            }
        }

        // 16. Memuat satu row SAMPLE untuk setiap sampel database (3 sampel)
        $this->assertCount(3, $sampleLines);
        // Pastikan row gagal memuat FALSE,FALSE
        $this->assertStringContainsString('TRUE,3946,94,,FALSE,FALSE,3,2,1', $sampleLines[2]);

        // 17 & 18. Baris SUMMARY selalu tepat satu baris dan merupakan baris terakhir
        $this->assertCount(1, $summaryLines);
        $lastLine = end($lines);
        $this->assertStringStartsWith('SUMMARY,', $lastLine);

        // Check nilai summary
        $summaryParts = str_getcsv($summaryLines[0]);
        // Sent_Total = 3, Acked_Total = 2, Failed_Total = 1
        $this->assertEquals(3, $summaryParts[16]);
        $this->assertEquals(2, $summaryParts[17]);
        $this->assertEquals(1, $summaryParts[18]);
        // PDR = 66.667
        $this->assertEquals('66.667', $summaryParts[20]);
        // RSSI Valid Count = 2
        $this->assertEquals('2', $summaryParts[24]);
        // RSSI Average = -69.00
        $this->assertEquals('-69.00', $summaryParts[25]);
        // RSSI Minimum = -70
        $this->assertEquals('-70', $summaryParts[26]);
        // RSSI Maximum = -68
        $this->assertEquals('-68', $summaryParts[27]);
        // RSSI Latest = -70
        $this->assertEquals('-70', $summaryParts[28]);
        // Samples Visible = 3, Unobserved = 0, Completeness = 100.000
        $this->assertEquals('3', $summaryParts[29]);
        $this->assertEquals('0', $summaryParts[30]);
        $this->assertEquals('100.000', $summaryParts[31]);
    }

    /**
     * 19. Duplikat (room_id, session_id, telemetry_seq) tidak menambah row
     */
    public function test_duplicate_sample_is_idempotent(): void
    {
        $payload = [
            'version'              => 1,
            'session_id'           => 4001,
            'telemetry_seq'        => 5,
            'telemetry_packet_seq' => 10,
            'sent_total'           => 5,
            'acked_total'          => 5,
            'failed_total'         => 0,
            'retries_total'        => 0,
            'pdr'                  => 100.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3800,
            'battery_percent'      => 70,
            'rssi_dbm'             => -65,
            'test_complete'        => false,
        ];

        $topic = "room/{$this->room->id}/node/link-metrics";
        $this->command->handleNodeLinkMetrics($topic, json_encode($payload));
        $this->command->handleNodeLinkMetrics($topic, json_encode($payload));

        $count = NodeLinkSample::where('room_id', $this->room->id)
            ->where('session_id', 4001)
            ->where('telemetry_seq', 5)
            ->count();

        $this->assertEquals(1, $count);
    }

    /**
     * 20. Session milik room lain tidak dapat diakses
     */
    public function test_session_of_another_room_cannot_be_accessed(): void
    {
        $otherUser = User::factory()->create();
        $otherRoom = Room::create([
            'user_id'         => $otherUser->id,
            'name'            => 'Lab Other User',
            'ac_brand'        => 'Panasonic',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ]);

        NodeLinkSample::create([
            'room_id'              => $otherRoom->id,
            'session_id'           => 5001,
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
            'battery_mv'           => 3800,
            'battery_percent'      => 70,
            'rssi_dbm'             => -65,
            'test_complete'        => false,
            'received_at'          => now(),
        ]);

        // User pertama mencoba akses room milik otherUser
        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$otherRoom->id}/node-link-sessions");

        $res->assertStatus(404);
    }

    /**
     * 21. Topic telemetri, battery, dan ac/status lama tidak terpengaruh
     */
    public function test_old_topics_unaffected(): void
    {
        $this->command->handleTelemetry("room/{$this->room->id}/telemetry", json_encode([
            'suhu'       => 25.5,
            'kelembaban' => 60.0,
        ]));
        $this->assertNotNull(SensorLog::where('room_id', $this->room->id)->first());

        $this->command->handleNodeBattery("room/{$this->room->id}/node/battery", json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3800,
            'percent'    => 68,
        ]));
        $this->assertNotNull(NodeBatteryStatus::where('room_id', $this->room->id)->first());

        $this->command->handleAcStatus("room/{$this->room->id}/ac/status", json_encode([
            'power'   => true,
            'suhu'    => 24,
            'mode'    => 'MANUAL',
            'ac_mode' => 'COOL',
        ]));
        $this->assertNotNull(AcLog::where('room_id', $this->room->id)->first());
    }
}
