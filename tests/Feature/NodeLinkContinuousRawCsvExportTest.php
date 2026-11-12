<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\NodeLinkSample;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeLinkContinuousRawCsvExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Room $room;
    private Room $otherRoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->room = Room::create([
            'user_id'  => $this->user->id,
            'name'     => 'Lab IoT 119',
            'ac_brand' => 'Panasonic',
        ]);

        $this->otherRoom = Room::create([
            'user_id'  => $this->user->id,
            'name'     => 'Lab 120',
            'ac_brand' => 'Daikin',
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
    }

    private function parseCsvLines(string $content): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($content, "\xEF\xBB\xBF\r\n "));
        return array_values(array_filter(explode("\n", $normalized), fn($l) => trim($l) !== ''));
    }

    /**
     * 1, 2, 6, 7. Data dari beberapa session_id dalam room yang sama diekspor bersama sebagai linimasa kontinu tanpa baris SUMMARY.
     */
    public function test_01_multiple_boot_sessions_exported_together_without_summary(): void
    {
        $baseTime = Carbon::parse('2026-11-12 08:00:00', 'Asia/Jakarta')->setTimezone('UTC');

        // Boot Session 101: 2 sampel
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
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
            'sensor_valid'         => true,
            'temperature_c'        => 24.50,
            'humidity_percent'     => 60.00,
            'battery_valid'        => true,
            'battery_mv'           => 3900,
            'battery_percent'      => 85,
            'rssi_dbm'             => -65,
            'test_complete'        => false,
            'received_at'          => $baseTime->copy()->addMinutes(1),
        ]);

        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 101,
            'telemetry_seq'        => 2,
            'telemetry_packet_seq' => 2,
            'sent_total'           => 2,
            'acked_total'          => 2,
            'failed_total'         => 0,
            'retries_total'        => 0,
            'pdr'                  => 100.0,
            'telemetry_acked'      => true,
            'telemetry_received'   => true,
            'sensor_valid'         => true,
            'temperature_c'        => 24.60,
            'humidity_percent'     => 59.80,
            'battery_valid'        => true,
            'battery_mv'           => 3890,
            'battery_percent'      => 84,
            'rssi_dbm'             => -67,
            'test_complete'        => false,
            'received_at'          => $baseTime->copy()->addMinutes(2),
        ]);

        // Boot Session 102 (setelah restart/brownout): 2 sampel
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
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
            'sensor_valid'         => true,
            'temperature_c'        => 24.80,
            'humidity_percent'     => 59.20,
            'battery_valid'        => true,
            'battery_mv'           => 3880,
            'battery_percent'      => 83,
            'rssi_dbm'             => -70,
            'test_complete'        => false,
            'received_at'          => $baseTime->copy()->addMinutes(5),
        ]);

        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 102,
            'telemetry_seq'        => 2,
            'telemetry_packet_seq' => 2,
            'sent_total'           => 2,
            'acked_total'          => 1,
            'failed_total'         => 1,
            'retries_total'        => 1,
            'pdr'                  => 50.0,
            'telemetry_acked'      => false,
            'telemetry_received'   => true,
            'sensor_valid'         => true,
            'temperature_c'        => 24.90,
            'humidity_percent'     => 58.90,
            'battery_valid'        => true,
            'battery_mv'           => 3870,
            'battery_percent'      => 82,
            'rssi_dbm'             => -72,
            'test_complete'        => false,
            'received_at'          => $baseTime->copy()->addMinutes(6),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-samples/export.csv");
        $response->assertStatus(200);

        $content = $response->streamedContent();

        // 1. Pastikan tidak ada baris SUMMARY
        $this->assertStringNotContainsString('SUMMARY', $content);

        // 2. Parse baris CSV
        $lines = $this->parseCsvLines($content);
        // 1 header + 4 data samples = 5 baris
        $this->assertCount(5, $lines);

        // Header check
        $header = str_getcsv($lines[0]);
        $this->assertEquals('Row_ID', $header[0]);
        $this->assertEquals('Timestamp_UTC', $header[1]);
        $this->assertEquals('Timestamp_WIB', $header[2]);
        $this->assertEquals('Room_ID', $header[3]);
        $this->assertEquals('Boot_Session_ID', $header[4]);

        // Verifikasi Boot_Session_ID pada setiap baris
        $row1 = str_getcsv($lines[1]);
        $this->assertEquals('101', $row1[4]);
        $row2 = str_getcsv($lines[2]);
        $this->assertEquals('101', $row2[4]);
        $row3 = str_getcsv($lines[3]);
        $this->assertEquals('102', $row3[4]);
        $row4 = str_getcsv($lines[4]);
        $this->assertEquals('102', $row4[4]);
    }

    /**
     * 3, 4. Data room lain tidak ikut dan filter from/to berfungsi eksklusif.
     */
    public function test_02_room_isolation_and_time_range_filter(): void
    {
        $t1 = Carbon::parse('2026-11-12 10:00:00+07:00')->setTimezone('UTC');
        $t2 = Carbon::parse('2026-11-12 11:00:00+07:00')->setTimezone('UTC');
        $t3 = Carbon::parse('2026-11-12 12:00:00+07:00')->setTimezone('UTC');
        $t4 = Carbon::parse('2026-11-12 13:00:00+07:00')->setTimezone('UTC');

        // Sample room ini di waktu t1 (di luar filter)
        NodeLinkSample::create([
            'room_id' => $this->room->id, 'session_id' => 201, 'telemetry_seq' => 1, 'telemetry_packet_seq' => 1,
            'sent_total' => 1, 'acked_total' => 1, 'failed_total' => 0, 'retries_total' => 0, 'pdr' => 100.0,
            'telemetry_acked' => true, 'telemetry_received' => true, 'sensor_valid' => true, 'battery_valid' => true,
            'test_complete' => false, 'received_at' => $t1,
        ]);

        // Sample room ini di waktu t2 (di dalam filter)
        $sampleIn1 = NodeLinkSample::create([
            'room_id' => $this->room->id, 'session_id' => 201, 'telemetry_seq' => 2, 'telemetry_packet_seq' => 2,
            'sent_total' => 2, 'acked_total' => 2, 'failed_total' => 0, 'retries_total' => 0, 'pdr' => 100.0,
            'telemetry_acked' => true, 'telemetry_received' => true, 'sensor_valid' => true, 'battery_valid' => true,
            'test_complete' => false, 'received_at' => $t2,
        ]);

        // Sample room ini di waktu t3 (di dalam filter)
        $sampleIn2 = NodeLinkSample::create([
            'room_id' => $this->room->id, 'session_id' => 202, 'telemetry_seq' => 1, 'telemetry_packet_seq' => 1,
            'sent_total' => 1, 'acked_total' => 1, 'failed_total' => 0, 'retries_total' => 0, 'pdr' => 100.0,
            'telemetry_acked' => true, 'telemetry_received' => true, 'sensor_valid' => true, 'battery_valid' => true,
            'test_complete' => false, 'received_at' => $t3,
        ]);

        // Sample room ini di waktu t4 (di luar filter)
        NodeLinkSample::create([
            'room_id' => $this->room->id, 'session_id' => 202, 'telemetry_seq' => 2, 'telemetry_packet_seq' => 2,
            'sent_total' => 2, 'acked_total' => 2, 'failed_total' => 0, 'retries_total' => 0, 'pdr' => 100.0,
            'telemetry_acked' => true, 'telemetry_received' => true, 'sensor_valid' => true, 'battery_valid' => true,
            'test_complete' => false, 'received_at' => $t4,
        ]);

        // Sample room LAIN di waktu t2 (harus diisolasi / tidak boleh masuk)
        NodeLinkSample::create([
            'room_id' => $this->otherRoom->id, 'session_id' => 999, 'telemetry_seq' => 1, 'telemetry_packet_seq' => 1,
            'sent_total' => 1, 'acked_total' => 1, 'failed_total' => 0, 'retries_total' => 0, 'pdr' => 100.0,
            'telemetry_acked' => true, 'telemetry_received' => true, 'sensor_valid' => true, 'battery_valid' => true,
            'test_complete' => false, 'received_at' => $t2,
        ]);

        $from = urlencode('2026-11-12T10:30:00+07:00');
        $to   = urlencode('2026-11-12T12:30:00+07:00');

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-samples/export.csv?from={$from}&to={$to}");
        $response->assertStatus(200);

        $content = $response->streamedContent();
        $lines = $this->parseCsvLines($content);

        // Tepat 1 baris header + 2 baris data sampel ($sampleIn1 dan $sampleIn2)
        $this->assertCount(3, $lines);

        $row1 = str_getcsv($lines[1]);
        $row2 = str_getcsv($lines[2]);

        $this->assertEquals((string) $sampleIn1->id, $row1[0]);
        $this->assertEquals((string) $sampleIn2->id, $row2[0]);
        $this->assertEquals((string) $this->room->id, $row1[3]);
        $this->assertEquals((string) $this->room->id, $row2[3]);
    }

    /**
     * 5. Urutan data berdasarkan received_at ASC dan tie-breaker id ASC.
     */
    public function test_03_ordering_by_received_at_asc_and_id_asc(): void
    {
        $sameTime = Carbon::parse('2026-11-12 14:00:00', 'UTC');
        $earlierTime = Carbon::parse('2026-11-12 13:00:00', 'UTC');

        // Dimasukkan acak
        $s3 = NodeLinkSample::create([
            'room_id' => $this->room->id, 'session_id' => 300, 'telemetry_seq' => 3, 'telemetry_packet_seq' => 3,
            'sent_total' => 3, 'acked_total' => 3, 'failed_total' => 0, 'retries_total' => 0, 'pdr' => 100.0,
            'telemetry_acked' => true, 'telemetry_received' => true, 'sensor_valid' => true, 'battery_valid' => true,
            'test_complete' => false, 'received_at' => $sameTime,
        ]);

        $s1 = NodeLinkSample::create([
            'room_id' => $this->room->id, 'session_id' => 300, 'telemetry_seq' => 1, 'telemetry_packet_seq' => 1,
            'sent_total' => 1, 'acked_total' => 1, 'failed_total' => 0, 'retries_total' => 0, 'pdr' => 100.0,
            'telemetry_acked' => true, 'telemetry_received' => true, 'sensor_valid' => true, 'battery_valid' => true,
            'test_complete' => false, 'received_at' => $earlierTime,
        ]);

        $s2 = NodeLinkSample::create([
            'room_id' => $this->room->id, 'session_id' => 300, 'telemetry_seq' => 2, 'telemetry_packet_seq' => 2,
            'sent_total' => 2, 'acked_total' => 2, 'failed_total' => 0, 'retries_total' => 0, 'pdr' => 100.0,
            'telemetry_acked' => true, 'telemetry_received' => true, 'sensor_valid' => true, 'battery_valid' => true,
            'test_complete' => false, 'received_at' => $sameTime,
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-samples/export.csv");
        $response->assertStatus(200);

        $content = $response->streamedContent();
        $lines = $this->parseCsvLines($content);

        $this->assertCount(4, $lines);
        $row1 = str_getcsv($lines[1]);
        $row2 = str_getcsv($lines[2]);
        $row3 = str_getcsv($lines[3]);

        $this->assertEquals((string) $s1->id, $row1[0]); // received_at paling awal
        $this->assertEquals((string) $s3->id, $row2[0]); // received_at sama, id lebih kecil
        $this->assertEquals((string) $s2->id, $row3[0]); // received_at sama, id lebih besar
    }

    /**
     * 8, 9, 10, 11, 12. Format nilai: null kosong, 0 tetap 0, boolean TRUE/FALSE, RSSI negatif (-70), suhu/kelembaban valid vs invalid.
     */
    public function test_04_column_formatting_nulls_booleans_rssi_temperatures(): void
    {
        // Sampel A: data valid lengkap dengan RSSI negatif (-78)
        $sA = NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 401,
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
            'temperature_c'        => 25.45,
            'humidity_percent'     => 63.80,
            'battery_valid'        => true,
            'battery_mv'           => 3850,
            'battery_percent'      => 75,
            'rssi_dbm'             => -78,
            'test_complete'        => false,
            'received_at'          => Carbon::parse('2026-11-12 09:00:00', 'UTC'),
        ]);

        // Sampel B: sensor invalid, telemetry not received, counter zero
        $sB = NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 401,
            'telemetry_seq'        => 2,
            'telemetry_packet_seq' => 2,
            'sent_total'           => 0,
            'acked_total'          => 0,
            'failed_total'         => 0,
            'retries_total'        => 0,
            'pdr'                  => 0.0,
            'telemetry_acked'      => false,
            'telemetry_received'   => false,
            'sensor_valid'         => false,
            'temperature_c'        => null,
            'humidity_percent'     => null,
            'battery_valid'        => false,
            'battery_mv'           => null,
            'battery_percent'      => null,
            'rssi_dbm'             => null,
            'test_complete'        => true,
            'received_at'          => Carbon::parse('2026-11-12 09:01:00', 'UTC'),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-samples/export.csv");
        $response->assertStatus(200);

        $content = $response->streamedContent();
        $lines = $this->parseCsvLines($content);

        $rowA = str_getcsv($lines[1]);
        $rowB = str_getcsv($lines[2]);

        // Kolom Sampel A:
        // [7]=Temp, [8]=Hum, [9]=BattMv, [10]=BattPct, [11]=RSSI, [12]=DelivSucc, [13]=TelemRecv, [14]=SensVal, [15]=Sent, [16]=Ack, [17]=Fail, [18]=Retr, [19]=PDR, [20]=Complete
        $this->assertEquals('25.45', $rowA[7]);
        $this->assertEquals('63.80', $rowA[8]);
        $this->assertEquals('3850', $rowA[9]);
        $this->assertEquals('75', $rowA[10]);
        $this->assertEquals('-78', $rowA[11], 'Nilai RSSI negatif seperti -78 harus dipertahankan');
        $this->assertEquals('TRUE', $rowA[12]);
        $this->assertEquals('TRUE', $rowA[13]);
        $this->assertEquals('TRUE', $rowA[14]);
        $this->assertEquals('1', $rowA[15]);
        $this->assertEquals('1', $rowA[16]);
        $this->assertEquals('0', $rowA[17], 'Angka 0 tetap ditulis 0');
        $this->assertEquals('0', $rowA[18], 'Angka 0 tetap ditulis 0');
        $this->assertEquals('100.000', $rowA[19]);
        $this->assertEquals('FALSE', $rowA[20]);

        // Kolom Sampel B:
        $this->assertEquals('', $rowB[7], 'Suhu harus kosong jika sensor_valid=false');
        $this->assertEquals('', $rowB[8], 'Kelembapan harus kosong jika sensor_valid=false');
        $this->assertEquals('', $rowB[9], 'Battery mV kosong jika null');
        $this->assertEquals('', $rowB[10], 'Battery % kosong jika null');
        $this->assertEquals('', $rowB[11], 'RSSI harus kosong jika telemetry_received=false');
        $this->assertEquals('FALSE', $rowB[12]);
        $this->assertEquals('FALSE', $rowB[13]);
        $this->assertEquals('FALSE', $rowB[14]);
        $this->assertEquals('0', $rowB[15], 'Sent_Total 0 tetap ditulis 0');
        $this->assertEquals('0', $rowB[16]);
        $this->assertEquals('', $rowB[19], 'PDR kosong jika sent_total=0');
        $this->assertEquals('TRUE', $rowB[20]);
    }

    /**
     * 13. Data kosong menghasilkan HTTP 200 dengan header CSV saja.
     */
    public function test_05_empty_data_returns_http_200_header_only(): void
    {
        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-samples/export.csv");
        $response->assertStatus(200);

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'Harus diawali UTF-8 BOM');

        $lines = $this->parseCsvLines($content);
        // Tepat 1 baris (header saja)
        $this->assertCount(1, $lines);
        $header = str_getcsv($lines[0]);
        $this->assertContains('Boot_Session_ID', $header);
        $this->assertContains('PDR_At_Sample_Percent', $header);
    }

    /**
     * 14. Parameter waktu invalid menghasilkan HTTP 422.
     */
    public function test_06_invalid_parameters_return_http_422(): void
    {
        // 1. from format bukan tanggal
        $res1 = $this->getJson("/api/v1/rooms/{$this->room->id}/node-link-samples/export.csv?from=invalid-string");
        $res1->assertStatus(422);
        $res1->assertJsonPath('status', 'error');

        // 2. to format bukan tanggal
        $res2 = $this->getJson("/api/v1/rooms/{$this->room->id}/node-link-samples/export.csv?to=not-a-date");
        $res2->assertStatus(422);

        // 3. from lebih besar dari to
        $from = urlencode('2026-11-13T12:00:00+07:00');
        $to   = urlencode('2026-11-12T12:00:00+07:00');
        $res3 = $this->getJson("/api/v1/rooms/{$this->room->id}/node-link-samples/export.csv?from={$from}&to={$to}");
        $res3->assertStatus(422);
        $this->assertStringContainsString('tidak boleh lebih besar', $res3->json('message'));

        // 4. timezone invalid
        $res4 = $this->getJson("/api/v1/rooms/{$this->room->id}/node-link-samples/export.csv?timezone=Mars/Phobos");
        $res4->assertStatus(422);
    }

    /**
     * 15. Header HTTP Content-Type, Content-Disposition attachment, dan UTF-8 BOM benar.
     */
    public function test_07_http_response_headers_and_bom(): void
    {
        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-samples/export.csv");
        $response->assertStatus(200);

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString("node_link_raw_room_{$this->room->id}_", $disposition);
        $this->assertStringContainsString('.csv', $disposition);
    }

    /**
     * 16. Endpoint lama per-session tetap menghasilkan format lama dengan baris SAMPLE dan SUMMARY.
     */
    public function test_08_legacy_session_export_endpoint_unaffected(): void
    {
        NodeLinkSample::create([
            'room_id'              => $this->room->id,
            'session_id'           => 55881234,
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
            'temperature_c'        => 24.50,
            'humidity_percent'     => 60.00,
            'battery_valid'        => true,
            'battery_mv'           => 3800,
            'battery_percent'      => 80,
            'rssi_dbm'             => -65,
            'test_complete'        => false,
            'received_at'          => now(),
        ]);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-sessions/55881234/export.csv");
        $response->assertStatus(200);

        $content = $response->streamedContent();
        $this->assertStringContainsString('SAMPLE', $content);
        $this->assertStringContainsString('SUMMARY', $content);
    }

    /**
     * 17. Dashboard web merender kartu Ekspor Raw Kontinu Node.
     */
    public function test_09_dashboard_renders_raw_continuous_card_and_guide(): void
    {
        $response = $this->actingAs($this->user)->get("/dashboard?room_id={$this->room->id}");
        $response->assertStatus(200);

        $response->assertSee('Ekspor Raw Kontinu Node');
        $response->assertSee('rawFrom');
        $response->assertSee('rawTo');
        $response->assertSee('btnDownloadRawContinuous');
        $response->assertSee('Panduan Analisis Python Pandas: Menghitung PDR Keseluruhan Lintas Boot');
        $response->assertSee('df.groupby("Boot_Session_ID"', false);
    }

    /**
     * 18. Streaming data dalam jumlah besar (chunking) berfungsi tanpa kendala.
     */
    public function test_10_large_dataset_streamed_in_chunks_correctly(): void
    {
        $samples = [];
        $base = now()->subMinutes(600);
        for ($i = 1; $i <= 550; $i++) {
            $samples[] = [
                'room_id'              => $this->room->id,
                'session_id'           => $i <= 300 ? 601 : 602,
                'telemetry_seq'        => $i <= 300 ? $i : ($i - 300),
                'telemetry_packet_seq' => $i,
                'sent_total'           => $i,
                'acked_total'          => $i,
                'failed_total'         => 0,
                'retries_total'        => 0,
                'pdr'                  => 100.0,
                'telemetry_acked'      => true,
                'telemetry_received'   => true,
                'sensor_valid'         => true,
                'temperature_c'        => 25.0,
                'humidity_percent'     => 60.0,
                'battery_valid'        => true,
                'battery_mv'           => 3800,
                'battery_percent'      => 80,
                'rssi_dbm'             => -65,
                'test_complete'        => false,
                'received_at'          => $base->copy()->addMinutes($i),
                'created_at'           => now(),
                'updated_at'           => now(),
            ];
        }
        NodeLinkSample::insert($samples);

        $response = $this->get("/api/v1/rooms/{$this->room->id}/node-link-samples/export.csv");
        $response->assertStatus(200);

        $content = $response->streamedContent();
        $lines = $this->parseCsvLines($content);

        // 1 header + 550 data samples = 551 lines
        $this->assertCount(551, $lines);
    }
}
