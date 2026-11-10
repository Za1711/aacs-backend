<?php

namespace Tests\Feature;

use App\Console\Commands\MqttSubscribeCommand;
use App\Models\NodeBatteryStatus;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NodeBatteryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Room $room;
    private MqttSubscribeCommand $mqttCommand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->room = Room::create([
            'user_id'         => $this->user->id,
            'name'            => 'Test Room',
            'ac_brand'        => 'Daikin',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ]);

        $this->mqttCommand = new MqttSubscribeCommand();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // Reset clock
        parent::tearDown();
    }

    #[Test]
    public function payload_valid_tersimpan_dan_muncul_pada_latest_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));

        $payload = json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3800,
            'percent'    => 50,
        ]);

        $result = $this->mqttCommand->handleNodeBattery("room/{$this->room->id}/node/battery", $payload);
        $this->assertTrue($result);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);

        // Cek baik di data.node_battery maupun top-level node_battery
        foreach (['data.node_battery', 'node_battery'] as $key) {
            $response->assertJsonPath("{$key}.valid", true)
                ->assertJsonPath("{$key}.is_stale", false)
                ->assertJsonPath("{$key}.millivolts", 3800)
                ->assertJsonPath("{$key}.voltage", 3.8)
                ->assertJsonPath("{$key}.percent", 50);

            $this->assertNotNull($response->json("{$key}.updated_at"));
        }
    }

    #[Test]
    public function payload_valid_false_menghasilkan_nilai_null_dan_tidak_berubah_menjadi_0_persen(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));

        $payload = json_encode([
            'version'    => 1,
            'valid'      => false,
            'millivolts' => null,
            'percent'    => null,
        ]);

        $result = $this->mqttCommand->handleNodeBattery("room/{$this->room->id}/node/battery", $payload);
        $this->assertTrue($result);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);

        foreach (['data.node_battery', 'node_battery'] as $key) {
            $response->assertJsonPath("{$key}.valid", false)
                ->assertJsonPath("{$key}.is_stale", false)
                ->assertJsonPath("{$key}.millivolts", null)
                ->assertJsonPath("{$key}.voltage", null)
                ->assertJsonPath("{$key}.percent", null);

            // Pastikan null bukan integer 0
            $this->assertNull($response->json("{$key}.percent"));
            $this->assertNotSame(0, $response->json("{$key}.percent"));
            $this->assertNotNull($response->json("{$key}.updated_at"));
        }
    }

    #[Test]
    public function percent_nol_adalah_nilai_valid_untuk_baterai_kosong(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));

        $payload = json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 2500,
            'percent'    => 0,
        ]);

        $result = $this->mqttCommand->handleNodeBattery("room/{$this->room->id}/node/battery", $payload);
        $this->assertTrue($result);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);

        foreach (['data.node_battery', 'node_battery'] as $key) {
            $response->assertJsonPath("{$key}.valid", true)
                ->assertJsonPath("{$key}.is_stale", false)
                ->assertJsonPath("{$key}.millivolts", 2500)
                ->assertJsonPath("{$key}.voltage", 2.5)
                ->assertJsonPath("{$key}.percent", 0);

            $this->assertSame(0, $response->json("{$key}.percent"));
        }
    }

    #[Test]
    public function payload_invalid_ditolak_tanpa_menimpa_data_terakhir(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));

        // Simpan data awal yang valid
        $validPayload = json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3800,
            'percent'    => 50,
        ]);
        $this->assertTrue($this->mqttCommand->handleNodeBattery("room/{$this->room->id}/node/battery", $validPayload));

        $invalidPayloads = [
            // Persen di luar 0–100
            json_encode(['version' => 1, 'valid' => true, 'millivolts' => 3800, 'percent' => 101]),
            json_encode(['version' => 1, 'valid' => true, 'millivolts' => 3800, 'percent' => -1]),
            // Millivolts di luar 2500–4350
            json_encode(['version' => 1, 'valid' => true, 'millivolts' => 2499, 'percent' => 50]),
            json_encode(['version' => 1, 'valid' => true, 'millivolts' => 4351, 'percent' => 50]),
            // Versi tidak didukung
            json_encode(['version' => 2, 'valid' => true, 'millivolts' => 3800, 'percent' => 50]),
            json_encode(['version' => '1', 'valid' => true, 'millivolts' => 3800, 'percent' => 50]),
            // Tipe salah (coercion ditolak)
            json_encode(['version' => 1, 'valid' => 'true', 'millivolts' => 3800, 'percent' => 50]),
            json_encode(['version' => 1, 'valid' => 1, 'millivolts' => 3800, 'percent' => 50]),
            // valid=false dengan millivolts/percent bukan null
            json_encode(['version' => 1, 'valid' => false, 'millivolts' => 3000, 'percent' => null]),
            json_encode(['version' => 1, 'valid' => false, 'millivolts' => null, 'percent' => 0]),
            // JSON rusak & tipe non-object
            '{bad json',
            json_encode([1, 2, 3]),
            '',
        ];

        foreach ($invalidPayloads as $invalid) {
            $result = $this->mqttCommand->handleNodeBattery("room/{$this->room->id}/node/battery", $invalid);
            $this->assertFalse($result, "Payload seharusnya ditolak: {$invalid}");
        }

        // Pastikan status terakhir di tabel node_battery_statuses tidak berubah
        $status = NodeBatteryStatus::where('room_id', $this->room->id)->first();
        $this->assertNotNull($status);
        $this->assertTrue($status->valid);
        $this->assertEquals(3800, $status->millivolts);
        $this->assertEquals(50, $status->percent);
    }

    #[Test]
    public function status_menjadi_stale_setelah_30_detik_tanpa_pesan_baru(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));

        $payload = json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3800,
            'percent'    => 50,
        ]);
        $this->mqttCommand->handleNodeBattery("room/{$this->room->id}/node/battery", $payload);

        // Majukan waktu 31 detik (default timeout adalah 30 detik)
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:31'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);

        foreach (['data.node_battery', 'node_battery'] as $key) {
            $response->assertJsonPath("{$key}.valid", false)
                ->assertJsonPath("{$key}.is_stale", true)
                ->assertJsonPath("{$key}.millivolts", null)
                ->assertJsonPath("{$key}.voltage", null)
                ->assertJsonPath("{$key}.percent", null);

            // Data waktu asli tetap tersimpan di database untuk audit
            $this->assertNotNull($response->json("{$key}.updated_at"));
        }
    }

    #[Test]
    public function baterai_tetap_diproses_dan_dikembalikan_saat_telemetry_suhu_tidak_ada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));

        $payload = json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3800,
            'percent'    => 50,
        ]);
        $this->mqttCommand->handleNodeBattery("room/{$this->room->id}/node/battery", $payload);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.sensor.temperature', null)
            ->assertJsonPath('data.sensor.is_stale', true)
            ->assertJsonPath('data.node_battery.valid', true)
            ->assertJsonPath('data.node_battery.millivolts', 3800)
            ->assertJsonPath('node_battery.valid', true)
            ->assertJsonPath('node_battery.millivolts', 3800);
    }

    #[Test]
    public function topic_telemetry_suhu_lama_masih_diproses_seperti_sebelumnya(): void
    {
        $payload = json_encode([
            'suhu'       => 24.45,
            'kelembaban' => 69.10,
        ]);

        $this->mqttCommand->handleTelemetry("room/{$this->room->id}/telemetry", $payload);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.sensor.temperature', 24.45)
            ->assertJsonPath('data.sensor.humidity', 69.1);
    }

    #[Test]
    public function isolasi_antar_room_terjaga(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));

        $room2 = Room::create([
            'user_id'         => $this->user->id,
            'name'            => 'Room 2',
            'ac_brand'        => 'Panasonic',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ]);

        $payload = json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3900,
            'percent'    => 60,
        ]);

        // Kirim hanya ke Room 1
        $this->mqttCommand->handleNodeBattery("room/{$this->room->id}/node/battery", $payload);

        // Cek Room 1
        $res1 = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/rooms/{$this->room->id}/latest-status");
        $res1->assertJsonPath('data.node_battery.millivolts', 3900);
        $res1->assertJsonPath('node_battery.millivolts', 3900);

        // Cek Room 2: tidak boleh terpengaruh
        $res2 = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/rooms/{$room2->id}/latest-status");
        $res2->assertJsonPath('data.node_battery.valid', false)
            ->assertJsonPath('data.node_battery.is_stale', true)
            ->assertJsonPath('data.node_battery.millivolts', null)
            ->assertJsonPath('data.node_battery.updated_at', null)
            ->assertJsonPath('node_battery.valid', false)
            ->assertJsonPath('node_battery.is_stale', true)
            ->assertJsonPath('node_battery.millivolts', null)
            ->assertJsonPath('node_battery.updated_at', null);
    }

    #[Test]
    public function room_yang_belum_pernah_mengirim_baterai_memperoleh_default_stabil(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);

        foreach (['data.node_battery', 'node_battery'] as $key) {
            $response->assertJsonPath("{$key}.valid", false)
                ->assertJsonPath("{$key}.is_stale", true)
                ->assertJsonPath("{$key}.millivolts", null)
                ->assertJsonPath("{$key}.voltage", null)
                ->assertJsonPath("{$key}.percent", null)
                ->assertJsonPath("{$key}.updated_at", null);
        }
    }

    #[Test]
    public function pesan_ke_room_yang_tidak_ada_ditolak_dengan_aman(): void
    {
        $payload = json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3800,
            'percent'    => 50,
        ]);

        $result = $this->mqttCommand->handleNodeBattery("room/999999/node/battery", $payload);
        $this->assertFalse($result);
    }

    #[Test]
    public function unpair_room_mereset_node_battery_ke_default(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));

        $payload = json_encode([
            'version'    => 1,
            'valid'      => true,
            'millivolts' => 3800,
            'percent'    => 50,
        ]);
        $this->mqttCommand->handleNodeBattery("room/{$this->room->id}/node/battery", $payload);

        // Pastikan baterai ada
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status")
            ->assertJsonPath('data.node_battery.millivolts', 3800);

        // Lakukan unpair
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/rooms/{$this->room->id}/unpair")
            ->assertStatus(200);

        // Latest status setelah unpair kembali ke default stabil
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status")
            ->assertJsonPath('data.node_battery.valid', false)
            ->assertJsonPath('data.node_battery.is_stale', true)
            ->assertJsonPath('data.node_battery.millivolts', null)
            ->assertJsonPath('data.node_battery.updated_at', null)
            ->assertJsonPath('node_battery.valid', false)
            ->assertJsonPath('node_battery.is_stale', true)
            ->assertJsonPath('node_battery.millivolts', null)
            ->assertJsonPath('node_battery.updated_at', null);
    }
}
