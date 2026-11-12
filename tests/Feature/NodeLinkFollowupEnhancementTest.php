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

class NodeLinkFollowupEnhancementTest extends TestCase
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
     * 1. active_source selalu sama dengan active_sensor.source pada seluruh kondisi.
     */
    public function test_01_active_source_strictly_equals_active_sensor_source(): void
    {
        // Kondisi A: SensorLog dari REMOTE, tapi control_source USER_NODE
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

        SensorLog::create([
            'room_id'     => $this->room->id,
            'temperature' => 25.10,
            'humidity'    => 62.00,
            'source'      => 'REMOTE',
            'valid'       => true,
        ]);

        $responseA = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $responseA->assertStatus(200);
        $dataA = $responseA->json('data');

        $this->assertEquals('REMOTE', $dataA['active_sensor']['source']);
        $this->assertEquals('REMOTE', $dataA['active_source']);
        $this->assertEquals($dataA['active_sensor']['source'], $dataA['active_source']);
        $this->assertEquals('USER_NODE', $dataA['control_source']);

        // Kondisi B: SensorLog dari USER_NODE, control_source REMOTE
        AcLog::create([
            'room_id'        => $this->room->id,
            'control_source' => 'REMOTE',
            'node_paired'    => true,
            'node_connected' => true,
            'power'          => 'ON',
            'setpoint'       => 24,
            'mode'           => 'MANUAL',
            'ac_mode'        => 'COOL',
            'fan_speed'      => 'AUTO',
            'swing'          => 'AUTO',
        ]);

        SensorLog::create([
            'room_id'     => $this->room->id,
            'temperature' => 23.90,
            'humidity'    => 68.00,
            'source'      => 'USER_NODE',
            'valid'       => true,
        ]);

        $responseB = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $responseB->assertStatus(200);
        $dataB = $responseB->json('data');

        $this->assertEquals('USER_NODE', $dataB['active_sensor']['source']);
        $this->assertEquals('USER_NODE', $dataB['active_source']);
        $this->assertEquals($dataB['active_sensor']['source'], $dataB['active_source']);
        $this->assertEquals('REMOTE', $dataB['control_source']);
    }

    /**
     * 2. source UNKNOWN tidak ditebak dari control_source.
     */
    public function test_02_unknown_source_is_not_guessed_from_control_source(): void
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

        // Legacy sensor log dengan source UNKNOWN
        SensorLog::create([
            'room_id'     => $this->room->id,
            'temperature' => 24.50,
            'humidity'    => 65.00,
            'source'      => 'UNKNOWN',
            'valid'       => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals('UNKNOWN', $data['active_sensor']['source']);
        $this->assertEquals('UNKNOWN', $data['active_source']);
        $this->assertEquals('USER_NODE', $data['control_source']);
    }

    /**
     * 3. Badge ACTIVE benar ("BERJALAN / AKTIF").
     */
    public function test_03_badge_active_is_rendered_correctly(): void
    {
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 1001,
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
            'battery_percent'      => 80,
            'test_complete'        => false,
            'received_at'          => Carbon::now('UTC'),
        ]);

        $response = $this->get("/dashboard?room_id={$this->room->id}");
        $response->assertStatus(200);
        $response->assertSee('BERJALAN / AKTIF', false);
    }

    /**
     * 4. Badge STALE benar ("TERHENTI / STALE").
     */
    public function test_04_badge_stale_is_rendered_correctly(): void
    {
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
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
            'battery_mv'           => 3800,
            'battery_percent'      => 80,
            'test_complete'        => false,
            'received_at'          => Carbon::now('UTC')->subSeconds(120),
        ]);

        $response = $this->get("/dashboard?room_id={$this->room->id}");
        $response->assertStatus(200);
        $response->assertSee('TERHENTI / STALE', false);
    }

    /**
     * 5. Badge COMPLETED benar ("SELESAI").
     */
    public function test_05_badge_completed_is_rendered_correctly(): void
    {
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 1003,
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
            'battery_percent'      => 80,
            'test_complete'        => true,
            'received_at'          => Carbon::now('UTC')->subSeconds(200),
        ]);

        $response = $this->get("/dashboard?room_id={$this->room->id}");
        $response->assertStatus(200);
        $response->assertSee('SELESAI');
    }

    /**
     * 6. CSV memiliki RSSI_dBm.
     */
    public function test_06_csv_has_rssi_dbm(): void
    {
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 1004,
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
            'battery_percent'      => 80,
            'rssi_dbm'             => -72,
            'received_at'          => now(),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-sessions/1004/export.csv");
        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString('RSSI_dBm', $content);
        $this->assertStringContainsString('-72', $content);
    }

    /**
     * 7. CSV memiliki PDR_Percent.
     */
    public function test_07_csv_has_pdr_percent(): void
    {
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 1005,
            'telemetry_seq'        => 1,
            'telemetry_packet_seq' => 1,
            'sent_total'           => 2,
            'acked_total'          => 1,
            'failed_total'         => 1,
            'retries_total'        => 1,
            'pdr'                  => 50.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3800,
            'battery_percent'      => 80,
            'received_at'          => now(),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-sessions/1005/export.csv");
        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString('PDR_Percent', $content);
        $this->assertStringContainsString('50.000', $content);
    }

    /**
     * 8. CSV memiliki suhu, RH, baterai dan delivery success.
     */
    public function test_08_csv_has_temp_rh_battery_delivery_success(): void
    {
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 1006,
            'telemetry_seq'        => 1,
            'telemetry_packet_seq' => 1,
            'sent_total'           => 1,
            'acked_total'          => 1,
            'failed_total'         => 0,
            'retries_total'        => 0,
            'pdr'                  => 100.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'sensor_valid'         => true,
            'temperature_c'        => 24.85,
            'humidity_percent'     => 61.20,
            'battery_valid'        => true,
            'battery_mv'           => 3850,
            'battery_percent'      => 75,
            'rssi_dbm'             => -65,
            'received_at'          => now(),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-sessions/1006/export.csv");
        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString('Temperature_C', $content);
        $this->assertStringContainsString('Humidity_Percent', $content);
        $this->assertStringContainsString('Battery_mV', $content);
        $this->assertStringContainsString('Delivery_Success', $content);
        $this->assertStringContainsString('24.85', $content);
        $this->assertStringContainsString('61.20', $content);
        $this->assertStringContainsString('3850', $content);
        $this->assertStringContainsString('TRUE', $content);
    }

    /**
     * 9. Sequence_Gap terhitung benar.
     */
    public function test_09_sequence_gap_calculated_correctly(): void
    {
        // Sample 1: Seq 3 -> gap = max(0, 3 - 1) = 2
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 1007,
            'telemetry_seq'        => 3,
            'telemetry_packet_seq' => 10,
            'sent_total'           => 3,
            'acked_total'          => 1,
            'failed_total'         => 2,
            'retries_total'        => 0,
            'pdr'                  => 33.333,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3800,
            'battery_percent'      => 80,
            'received_at'          => now()->subSeconds(20),
        ]);

        // Sample 2: Seq 4 -> gap = max(0, 4 - 3 - 1) = 0
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 1007,
            'telemetry_seq'        => 4,
            'telemetry_packet_seq' => 11,
            'sent_total'           => 4,
            'acked_total'          => 2,
            'failed_total'         => 2,
            'retries_total'        => 0,
            'pdr'                  => 50.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3800,
            'battery_percent'      => 80,
            'received_at'          => now()->subSeconds(15),
        ]);

        // Sample 3: Seq 7 -> gap = max(0, 7 - 4 - 1) = 2
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 1007,
            'telemetry_seq'        => 7,
            'telemetry_packet_seq' => 14,
            'sent_total'           => 7,
            'acked_total'          => 3,
            'failed_total'         => 4,
            'retries_total'        => 0,
            'pdr'                  => 42.857,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3800,
            'battery_percent'      => 80,
            'received_at'          => now()->subSeconds(10),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-sessions/1007/export.csv");
        $response->assertStatus(200);
        $content = $response->streamedContent();

        $lines = array_filter(explode("\n", str_replace(["\r\n", "\r"], "\n", $content)));
        $samples = array_values(array_filter($lines, fn($l) => str_starts_with($l, 'SAMPLE,')));

        $this->assertCount(3, $samples);

        $row1 = str_getcsv($samples[0]);
        $row2 = str_getcsv($samples[1]);
        $row3 = str_getcsv($samples[2]);

        // Sequence_Gap ada di indeks 21
        $this->assertEquals(2, (int) $row1[21]);
        $this->assertEquals(0, (int) $row2[21]);
        $this->assertEquals(2, (int) $row3[21]);
    }

    /**
     * 10. Sent_Total=10 dan 8 row menghasilkan Samples_Visible=8, Unobserved_Sample_Rows=2, Data_Completeness_Percent=80.000
     */
    public function test_10_sent_total_10_and_8_rows_produces_correct_completeness(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            NodeLinkSample::create([
                'room_id'              => $this->room->id,
                'session_id'           => 1008,
                'telemetry_seq'        => $i,
                'telemetry_packet_seq' => $i,
                'sent_total'           => ($i === 8 ? 10 : $i),
                'acked_total'          => ($i === 8 ? 8 : $i),
                'failed_total'         => ($i === 8 ? 2 : 0),
                'retries_total'        => 0,
                'pdr'                  => ($i === 8 ? 80.0 : 100.0),
                'telemetry_acked'      => true,
                'telemetry_received'   => true,
                'battery_valid'        => true,
                'battery_mv'           => 3800,
                'battery_percent'      => 80,
                'received_at'          => now()->subSeconds(80 - $i * 10),
            ]);
        }

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-sessions/1008/export.csv");
        $response->assertStatus(200);
        $content = $response->streamedContent();

        $lines = array_filter(explode("\n", str_replace(["\r\n", "\r"], "\n", $content)));
        $summaryLine = end($lines);
        $this->assertStringStartsWith('SUMMARY,', $summaryLine);

        $summaryParts = str_getcsv($summaryLine);
        // Samples_Visible di index 29
        $this->assertEquals(8, (int) $summaryParts[29]);
        // Unobserved_Sample_Rows di index 30
        $this->assertEquals(2, (int) $summaryParts[30]);
        // Data_Completeness_Percent di index 31
        $this->assertEquals('80.000', $summaryParts[31]);
    }

    /**
     * 11. PDR tetap berdasarkan counter kumulatif (bukan jumlah row database).
     */
    public function test_11_pdr_strictly_based_on_cumulative_counter(): void
    {
        // Hanya ada 1 row di database tetapi sent_total=100, acked_total=95
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 1009,
            'telemetry_seq'        => 100,
            'telemetry_packet_seq' => 150,
            'sent_total'           => 100,
            'acked_total'          => 95,
            'failed_total'         => 5,
            'retries_total'        => 2,
            'pdr'                  => 95.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'battery_valid'        => true,
            'battery_mv'           => 3800,
            'battery_percent'      => 80,
            'received_at'          => now(),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-sessions/1009/export.csv");
        $response->assertStatus(200);
        $content = $response->streamedContent();

        $lines = array_filter(explode("\n", str_replace(["\r\n", "\r"], "\n", $content)));
        $summaryLine = end($lines);
        $summaryParts = str_getcsv($summaryLine);

        // PDR_Percent di index 20
        $this->assertEquals('95.000', $summaryParts[20]);
    }

    /**
     * 12. SUMMARY tepat satu dan berada paling akhir.
     */
    public function test_12_summary_is_exactly_one_row_and_at_the_end(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            NodeLinkSample::create([
                'room_id'              => $this->room->id,
                'session_id'           => 1010,
                'telemetry_seq'        => $i,
                'telemetry_packet_seq' => $i,
                'sent_total'           => $i,
                'acked_total'          => $i,
                'failed_total'         => 0,
                'retries_total'        => 0,
                'pdr'                  => 100.0,
                'telemetry_acked'      => true,
                'telemetry_received'   => true,
                'battery_valid'        => true,
                'battery_mv'           => 3800,
                'battery_percent'      => 80,
                'received_at'          => now(),
            ]);
        }

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-sessions/1010/export.csv");
        $response->assertStatus(200);
        $content = $response->streamedContent();

        $lines = array_filter(explode("\n", str_replace(["\r\n", "\r"], "\n", $content)));
        $summaryLines = array_values(array_filter($lines, fn($l) => str_starts_with($l, 'SUMMARY,')));

        $this->assertCount(1, $summaryLines);
        $this->assertStringStartsWith('SUMMARY,', end($lines));
    }

    /**
     * 13. Kartu “Data Lengkap Node — RSSI & PDR” muncul.
     */
    public function test_13_prominent_card_appears_on_dashboard(): void
    {
        $response = $this->get("/dashboard?room_id={$this->room->id}");
        $response->assertStatus(200);
        $response->assertSee('Data Lengkap Node — RSSI & PDR', false);
        $response->assertSee('Data per pengiriman Node: suhu, kelembapan, baterai, RSSI, keberhasilan paket, dan PDR.');
    }

    /**
     * 14. Dropdown memuat semua sesi ruangan.
     */
    public function test_14_dropdown_contains_all_sessions_for_room(): void
    {
        foreach ([2001, 2002, 2003] as $sId) {
            NodeLinkSample::create([
                'room_id'              => $this->room->id,
                'session_id'           => $sId,
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
                'battery_percent'      => 80,
                'received_at'          => now(),
            ]);
        }

        $response = $this->get("/dashboard?room_id={$this->room->id}");
        $response->assertStatus(200);
        $response->assertSee('Session ID: 2001');
        $response->assertSee('Session ID: 2002');
        $response->assertSee('Session ID: 2003');
    }

    /**
     * 15. Sesi terbaru terpilih default.
     */
    public function test_15_latest_session_selected_by_default(): void
    {
        // Buat sesi 3001 lebih dahulu, lalu 3002
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 3001,
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
            'battery_percent'      => 80,
            'received_at'          => now()->subMinutes(10),
        ]);

        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 3002,
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
            'battery_percent'      => 80,
            'received_at'          => now(),
        ]);

        $response = $this->get("/dashboard?room_id={$this->room->id}");
        $response->assertStatus(200);
        // Sesi 3002 (paling besar/terbaru) harus memiliki selected
        $response->assertSee('<option value="3002" selected>', false);
    }

    /**
     * 16. Tombol membentuk URL session export yang benar.
     */
    public function test_16_download_button_forms_correct_session_export_url(): void
    {
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 4001,
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
            'battery_percent'      => 80,
            'received_at'          => now(),
        ]);

        $response = $this->get("/dashboard?room_id={$this->room->id}");
        $response->assertStatus(200);
        $expectedUrl = "/api/v1/rooms/{$this->room->id}/node-link-sessions/4001/export.csv";
        $response->assertSee($expectedUrl, false);
    }

    /**
     * 17. Kondisi tanpa sesi menonaktifkan tombol dan dropdown.
     */
    public function test_17_no_sessions_disables_button_and_dropdown(): void
    {
        $newRoom = Room::create([
            'user_id'  => $this->user->id,
            'name'     => 'Lab Kosong',
            'ac_brand' => 'Daikin',
        ]);

        $response = $this->get("/dashboard?room_id={$newRoom->id}");
        $response->assertStatus(200);
        $response->assertSee('Belum ada sesi Node yang tersedia.');
        $response->assertSee('id="prominentSessionSelect" disabled', false);
        $response->assertSee('id="prominentDownloadBtn" disabled', false);
    }

    /**
     * 18. CSV suhu Node lama tetap tersedia.
     */
    public function test_18_legacy_node_telemetry_csv_remains_available(): void
    {
        NodeTelemetryLog::create([
            'room_id'     => $this->room->id,
            'temperature' => 24.50,
            'humidity'    => 65.00,
            'valid'       => true,
            'received_at' => now(),
            'created_at'  => now(),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-telemetry/export.csv");
        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('24.50', $content);
    }

    /**
     * 19. Seluruh negative payload test lulus.
     */
    public function test_19_negative_payloads_strictly_rejected(): void
    {
        // A. handleTelemetry: valid="false", valid=0, valid=1 harus ditolak
        $topic = "room/{$this->room->id}/telemetry";

        $countBefore = SensorLog::count();
        $this->command->handleTelemetry($topic, json_encode(['suhu' => 24.0, 'kelembaban' => 60.0, 'valid' => "false"]));
        $this->assertEquals($countBefore, SensorLog::count());

        $this->command->handleTelemetry($topic, json_encode(['suhu' => 24.0, 'kelembaban' => 60.0, 'valid' => 0]));
        $this->assertEquals($countBefore, SensorLog::count());

        $this->command->handleTelemetry($topic, json_encode(['suhu' => 24.0, 'kelembaban' => 60.0, 'valid' => 1]));
        $this->assertEquals($countBefore, SensorLog::count());

        // B. handleRemoteTelemetry:
        // valid bukan boolean
        $this->assertFalse($this->command->handleRemoteTelemetry(
            "room/{$this->room->id}/remote/telemetry",
            json_encode(['version' => 1, 'source' => 'REMOTE', 'valid' => "true", 'suhu' => 25.0, 'kelembaban' => 60.0])
        ));

        // valid=true tapi suhu di luar rentang
        $this->assertFalse($this->command->handleRemoteTelemetry(
            "room/{$this->room->id}/remote/telemetry",
            json_encode(['version' => 1, 'source' => 'REMOTE', 'valid' => true, 'suhu' => 200.0, 'kelembaban' => 60.0])
        ));

        // valid=false tapi suhu tidak null
        $this->assertFalse($this->command->handleRemoteTelemetry(
            "room/{$this->room->id}/remote/telemetry",
            json_encode(['version' => 1, 'source' => 'REMOTE', 'valid' => false, 'suhu' => 25.0, 'kelembaban' => null])
        ));

        // C. handleNodeTelemetry:
        // version bukan integer 1
        $this->assertFalse($this->command->handleNodeTelemetry(
            "room/{$this->room->id}/node/telemetry",
            json_encode(['version' => "1", 'source' => 'USER_NODE', 'valid' => true, 'suhu' => 24.0, 'kelembaban' => 60.0])
        ));

        // version integer 2
        $this->assertFalse($this->command->handleNodeTelemetry(
            "room/{$this->room->id}/node/telemetry",
            json_encode(['version' => 2, 'source' => 'USER_NODE', 'valid' => true, 'suhu' => 24.0, 'kelembaban' => 60.0])
        ));

        // source bukan USER_NODE / NODE
        $this->assertFalse($this->command->handleNodeTelemetry(
            "room/{$this->room->id}/node/telemetry",
            json_encode(['version' => 1, 'source' => 'REMOTE', 'valid' => true, 'suhu' => 24.0, 'kelembaban' => 60.0])
        ));

        // valid bukan boolean
        $this->assertFalse($this->command->handleNodeTelemetry(
            "room/{$this->room->id}/node/telemetry",
            json_encode(['version' => 1, 'source' => 'USER_NODE', 'valid' => 1, 'suhu' => 24.0, 'kelembaban' => 60.0])
        ));

        // valid=true tapi kelembaban di luar batas
        $this->assertFalse($this->command->handleNodeTelemetry(
            "room/{$this->room->id}/node/telemetry",
            json_encode(['version' => 1, 'source' => 'USER_NODE', 'valid' => true, 'suhu' => 24.0, 'kelembaban' => 150.0])
        ));

        // valid=false tapi kelembaban tidak null
        $this->assertFalse($this->command->handleNodeTelemetry(
            "room/{$this->room->id}/node/telemetry",
            json_encode(['version' => 1, 'source' => 'USER_NODE', 'valid' => false, 'suhu' => null, 'kelembaban' => 50.0])
        ));

        // D. handleNodeLinkMetrics:
        // sensor_valid bukan boolean
        $baseMetrics = [
            'version'              => 1,
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
            'battery_percent'      => 75,
            'rssi_dbm'             => -65,
            'test_complete'        => false,
        ];

        $this->assertFalse($this->command->handleNodeLinkMetrics(
            "room/{$this->room->id}/node/link-metrics",
            json_encode(array_merge($baseMetrics, ['sensor_valid' => "true", 'temperature_c' => 24.0, 'humidity_percent' => 60.0]))
        ));

        // sensor_valid=true tetapi telemetry_received=false
        $this->assertFalse($this->command->handleNodeLinkMetrics(
            "room/{$this->room->id}/node/link-metrics",
            json_encode(array_merge($baseMetrics, [
                'telemetry_received' => false,
                'rssi_dbm'           => null,
                'sensor_valid'       => true,
                'temperature_c'      => 24.0,
                'humidity_percent'   => 60.0,
            ]))
        ));

        // sensor_valid=false tetapi temperature_c tidak null
        $this->assertFalse($this->command->handleNodeLinkMetrics(
            "room/{$this->room->id}/node/link-metrics",
            json_encode(array_merge($baseMetrics, [
                'sensor_valid'     => false,
                'temperature_c'    => 24.0,
                'humidity_percent' => null,
            ]))
        ));
    }

    /**
     * 20. Payload legacy firmware tanpa field sensor tetap diterima.
     */
    public function test_20_legacy_link_metrics_payload_without_sensor_fields_accepted(): void
    {
        $payload = [
            'version'              => 1,
            'session_id'           => 6001,
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
            'rssi_dbm'             => -65,
            'test_complete'        => false,
        ];

        $handled = $this->command->handleNodeLinkMetrics(
            "room/{$this->room->id}/node/link-metrics",
            json_encode($payload)
        );

        $this->assertTrue($handled);

        $sample = NodeLinkSample::where('room_id', $this->room->id)->where('session_id', 6001)->first();
        $this->assertNotNull($sample);
        $this->assertFalse((bool) $sample->sensor_valid);
        $this->assertNull($sample->temperature_c);
        $this->assertNull($sample->humidity_percent);
    }

    /**
     * 21. Penentuan sesi terbaru memprioritaskan waktu sampel terakhir (received_at) daripada ID numerik.
     * Session ID 9000 memiliki received_at lebih lama.
     * Session ID 1000 memiliki received_at lebih baru.
     * Sesi 1000 harus muncul dan terpilih pertama.
     */
    public function test_21_session_ordering_prioritizes_latest_received_at_over_session_id(): void
    {
        $olderTime = Carbon::now('UTC')->subHours(3);
        $newerTime = Carbon::now('UTC')->subMinutes(5);

        // Session 9000: Angka ID lebih besar, tetapi received_at lebih lama
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 9000,
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
            'battery_percent'      => 80,
            'rssi_dbm'             => -70,
            'test_complete'        => false,
            'received_at'          => $olderTime,
        ]);

        // Session 1000: Angka ID lebih kecil, tetapi received_at lebih baru
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 1000,
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
            'battery_percent'      => 80,
            'rssi_dbm'             => -65,
            'test_complete'        => false,
            'received_at'          => $newerTime,
        ]);

        // A. Endpoint API daftar sesi
        $response = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/rooms/{$this->room->id}/node-link-sessions");
        $response->assertStatus(200);
        $sessions = $response->json('data');

        $this->assertNotEmpty($sessions);
        $this->assertEquals(1000, $sessions[0]['session_id'], 'Sesi 1000 harus berada pada urutan pertama di API');
        $this->assertEquals(9000, $sessions[1]['session_id'], 'Sesi 9000 harus berada pada urutan kedua di API');

        // B. Dashboard Web: $sessions dan dropdown otomatis memilih sesi terbaru berdasarkan waktu
        $webResponse = $this->actingAs($this->user)->get("/dashboard?room_id={$this->room->id}");
        $webResponse->assertStatus(200);

        $viewSessions = $webResponse->viewData('sessions');
        $this->assertNotEmpty($viewSessions);
        $this->assertEquals(1000, $viewSessions[0]['session_id'], 'Sesi 1000 harus menjadi elemen pertama ($sessions[0]) di Dashboard');

        // Pastikan HTML dropdown merender sesi 1000 sebagai selected dan tombol download prominent mengarah ke 1000
        $webResponse->assertSee('<option value="1000" selected>', false);
        $webResponse->assertSee("/node-link-sessions/1000/export.csv");
    }

    /**
     * 22. Validasi ketat handleNodeTelemetry sesuai laporan.
     */
    public function test_22_handle_node_telemetry_hardened_validation(): void
    {
        $topic = "room/{$this->room->id}/node/telemetry";

        // version tidak ada
        $this->assertFalse($this->command->handleNodeTelemetry($topic, json_encode([
            'source' => 'USER_NODE', 'valid' => true, 'suhu' => 24.5, 'kelembaban' => 60.0,
        ])));

        // version bukan integer
        $this->assertFalse($this->command->handleNodeTelemetry($topic, json_encode([
            'version' => "1", 'source' => 'USER_NODE', 'valid' => true, 'suhu' => 24.5, 'kelembaban' => 60.0,
        ])));

        // version bukan 1
        $this->assertFalse($this->command->handleNodeTelemetry($topic, json_encode([
            'version' => 2, 'source' => 'USER_NODE', 'valid' => true, 'suhu' => 24.5, 'kelembaban' => 60.0,
        ])));

        // source tidak ada
        $this->assertFalse($this->command->handleNodeTelemetry($topic, json_encode([
            'version' => 1, 'valid' => true, 'suhu' => 24.5, 'kelembaban' => 60.0,
        ])));

        // source bukan USER_NODE atau NODE
        $this->assertFalse($this->command->handleNodeTelemetry($topic, json_encode([
            'version' => 1, 'source' => 'REMOTE', 'valid' => true, 'suhu' => 24.5, 'kelembaban' => 60.0,
        ])));

        // valid bukan boolean
        $this->assertFalse($this->command->handleNodeTelemetry($topic, json_encode([
            'version' => 1, 'source' => 'USER_NODE', 'valid' => "true", 'suhu' => 24.5, 'kelembaban' => 60.0,
        ])));

        // valid=false tetapi key suhu hilang
        $this->assertFalse($this->command->handleNodeTelemetry($topic, json_encode([
            'version' => 1, 'source' => 'USER_NODE', 'valid' => false, 'kelembaban' => null,
        ])));

        // valid=false tetapi key kelembaban hilang
        $this->assertFalse($this->command->handleNodeTelemetry($topic, json_encode([
            'version' => 1, 'source' => 'USER_NODE', 'valid' => false, 'suhu' => null,
        ])));

        // valid=false tetapi nilai suhu tidak null
        $this->assertFalse($this->command->handleNodeTelemetry($topic, json_encode([
            'version' => 1, 'source' => 'USER_NODE', 'valid' => false, 'suhu' => 25.0, 'kelembaban' => null,
        ])));

        // valid=false tetapi nilai kelembaban tidak null
        $this->assertFalse($this->command->handleNodeTelemetry($topic, json_encode([
            'version' => 1, 'source' => 'USER_NODE', 'valid' => false, 'suhu' => null, 'kelembaban' => 55.0,
        ])));

        // valid=false dengan key suhu & kelembaban bernilai null secara eksplisit -> SUKSES
        $this->assertTrue($this->command->handleNodeTelemetry($topic, json_encode([
            'version' => 1, 'source' => 'USER_NODE', 'valid' => false, 'suhu' => null, 'kelembaban' => null,
        ])));

        // valid=true dengan alias source NODE -> SUKSES
        $this->assertTrue($this->command->handleNodeTelemetry($topic, json_encode([
            'version' => 1, 'source' => 'NODE', 'valid' => true, 'suhu' => 24.8, 'kelembaban' => 58.2,
        ])));
    }

    /**
     * 23. Validasi ketat handleRemoteTelemetry: valid=false mewajibkan suhu & kelembaban ada dan bernilai null.
     */
    public function test_23_handle_remote_telemetry_hardened_validation(): void
    {
        $topic = "room/{$this->room->id}/remote/telemetry";

        // valid=false tetapi suhu tidak ada
        $this->assertFalse($this->command->handleRemoteTelemetry($topic, json_encode([
            'version' => 1, 'source' => 'REMOTE', 'valid' => false, 'kelembaban' => null,
        ])));

        // valid=false tetapi kelembaban tidak ada
        $this->assertFalse($this->command->handleRemoteTelemetry($topic, json_encode([
            'version' => 1, 'source' => 'REMOTE', 'valid' => false, 'suhu' => null,
        ])));

        // valid=false tetapi suhu berisi nilai numerik
        $this->assertFalse($this->command->handleRemoteTelemetry($topic, json_encode([
            'version' => 1, 'source' => 'REMOTE', 'valid' => false, 'suhu' => 24.5, 'kelembaban' => null,
        ])));

        // valid=false dengan suhu & kelembaban null eksplisit -> SUKSES
        $this->assertTrue($this->command->handleRemoteTelemetry($topic, json_encode([
            'version' => 1, 'source' => 'REMOTE', 'valid' => false, 'suhu' => null, 'kelembaban' => null,
        ])));
    }

    /**
     * 24. handleNodeLinkMetrics menolak payload sensor parsial dan membedakan legacy vs strict modern.
     */
    public function test_24_handle_node_link_metrics_partial_payload_rejection(): void
    {
        $topic = "room/{$this->room->id}/node/link-metrics";
        $basePayload = [
            'version'              => 1,
            'session_id'           => 7701,
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
            'rssi_dbm'             => -65,
            'test_complete'        => false,
        ];

        // Parsial 1: sensor_valid ada, tapi temperature_c & humidity_percent tidak ada -> DITOLAK
        $this->assertFalse($this->command->handleNodeLinkMetrics($topic, json_encode(array_merge($basePayload, [
            'sensor_valid' => true,
        ]))));

        // Parsial 2: sensor_valid dan temperature_c ada, tapi humidity_percent tidak ada -> DITOLAK
        $this->assertFalse($this->command->handleNodeLinkMetrics($topic, json_encode(array_merge($basePayload, [
            'sensor_valid'  => true,
            'temperature_c' => 24.5,
        ]))));

        // Parsial 3: temperature_c ada tapi sensor_valid tidak ada -> DITOLAK
        $this->assertFalse($this->command->handleNodeLinkMetrics($topic, json_encode(array_merge($basePayload, [
            'temperature_c'    => 24.5,
            'humidity_percent' => 55.0,
        ]))));

        // Modern valid=false: ketiga key ada, temperature_c & humidity_percent null -> SUKSES
        $this->assertTrue($this->command->handleNodeLinkMetrics($topic, json_encode(array_merge($basePayload, [
            'telemetry_seq'    => 2,
            'sensor_valid'     => false,
            'temperature_c'    => null,
            'humidity_percent' => null,
        ]))));

        // Modern valid=true: ketiga key ada dan valid -> SUKSES
        $this->assertTrue($this->command->handleNodeLinkMetrics($topic, json_encode(array_merge($basePayload, [
            'telemetry_seq'    => 3,
            'sensor_valid'     => true,
            'temperature_c'    => 25.2,
            'humidity_percent' => 61.4,
        ]))));
    }

    /**
     * 25. Command node-link:delete-session menghapus sesi tertentu dengan aman dan membuat backup CSV.
     */
    public function test_25_delete_node_link_session_command_safely_removes_session_and_creates_backup(): void
    {
        // Setup data target sesi 8801 dan sesi pembanding 8802 pada room yang sama
        for ($seq = 1; $seq <= 3; $seq++) {
            NodeLinkSample::create([
                'room_id'              => $this->room->id,
                'session_id'           => 8801,
                'telemetry_seq'        => $seq,
                'telemetry_packet_seq' => $seq,
                'sent_total'           => $seq,
                'acked_total'          => $seq,
                'failed_total'         => 0,
                'retries_total'        => 0,
                'pdr'                  => 100.0,
                'telemetry_acked'      => true,
                'telemetry_received'   => true,
                'battery_valid'        => true,
                'battery_mv'           => 3800,
                'battery_percent'      => 80,
                'rssi_dbm'             => -65,
                'sensor_valid'         => true,
                'temperature_c'        => 25.0,
                'humidity_percent'     => 60.0,
                'test_complete'        => false,
                'received_at'          => now()->subMinutes(10),
            ]);
        }

        for ($seq = 1; $seq <= 2; $seq++) {
            NodeLinkSample::create([
                'room_id'              => $this->room->id,
                'session_id'           => 8802,
                'telemetry_seq'        => $seq,
                'telemetry_packet_seq' => $seq,
                'sent_total'           => $seq,
                'acked_total'          => $seq,
                'failed_total'         => 0,
                'retries_total'        => 0,
                'pdr'                  => 100.0,
                'telemetry_acked'      => true,
                'telemetry_received'   => true,
                'battery_valid'        => true,
                'battery_mv'           => 3800,
                'battery_percent'      => 80,
                'rssi_dbm'             => -65,
                'sensor_valid'         => false,
                'temperature_c'        => null,
                'humidity_percent'     => null,
                'test_complete'        => false,
                'received_at'          => now()->subMinutes(5),
            ]);
        }

        $this->assertEquals(3, NodeLinkSample::where('room_id', $this->room->id)->where('session_id', 8801)->count());
        $this->assertEquals(2, NodeLinkSample::where('room_id', $this->room->id)->where('session_id', 8802)->count());

        // Jalankan artisan node-link:delete-session dengan flag --force
        $exitCode = $this->artisan('node-link:delete-session', [
            'roomId'    => (string) $this->room->id,
            'sessionId' => '8801',
            '--force'   => true,
        ])->run();

        $this->assertEquals(0, $exitCode);

        // Verifikasi sesi 8801 terhapus bersih
        $this->assertEquals(0, NodeLinkSample::where('room_id', $this->room->id)->where('session_id', 8801)->count());

        // Verifikasi sesi 8802 tetap utuh tidak tersentuh
        $this->assertEquals(2, NodeLinkSample::where('room_id', $this->room->id)->where('session_id', 8802)->count());

        // Verifikasi file backup tersimpan di storage/app/backups
        $backupFiles = glob(storage_path('app/backups/node_link_backup_room_' . $this->room->id . '_session_8801_*.csv'));
        $this->assertNotEmpty($backupFiles, 'File backup CSV harus terbentuk di folder storage/app/backups');

        // Cleanup file backup test
        foreach ($backupFiles as $bf) {
            @unlink($bf);
        }

        // Test room atau session tidak valid
        $failCode = $this->artisan('node-link:delete-session', [
            'roomId'    => '999999',
            'sessionId' => '8801',
            '--force'   => true,
        ])->run();
        $this->assertEquals(1, $failCode);
    }
}
