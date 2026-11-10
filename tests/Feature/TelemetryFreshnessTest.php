<?php

namespace Tests\Feature;

use App\Models\AcLog;
use App\Models\ControlLog;
use App\Models\Device;
use App\Models\OccupancyLog;
use App\Models\Room;
use App\Models\SensorLog;
use App\Models\RemoteDeviceStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TelemetryFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Room $room;
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->room = Room::create([
            'user_id'         => $this->user->id,
            'name'            => 'Lab IoT',
            'ac_brand'        => 'Daikin',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ]);

        $this->device = Device::create([
            'room_id'       => $this->room->id,
            'user_id'       => $this->user->id,
            'device_type'   => 'ESP32',
            'serial_number' => 'ESP32-TEST-FRESHNESS',
            'status'        => 'ONLINE',
            'is_paired'     => true,
        ]);
    }

    #[Test]
    public function telemetri_berumur_kurang_dari_20_detik_menghasilkan_is_stale_false_dan_device_online_true(): void
    {
        // 1. Telemetri dibuat pada 10:29:55 dan Remote heartbeat aktif
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:29:55'));
        SensorLog::create([
            'room_id'     => $this->room->id,
            'temperature' => 27.4,
            'humidity'    => 68.0,
        ]);
        RemoteDeviceStatus::create([
            'room_id'      => $this->room->id,
            'last_seen_at' => Carbon::now('UTC'),
        ]);

        // 2. Endpoint dipanggil 5 detik kemudian (10:30:00 -> umur 5 detik < 20s)
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:30:00'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.sensor.is_stale', false)
            ->assertJsonPath('data.sensor.updated_at', '2026-10-26T10:29:55Z')
            ->assertJsonPath('data.device_online', true)
            ->assertJsonPath('data.device_paired', true);

        $this->assertEquals(27.4, $response->json('data.sensor.temperature'));
        $this->assertEquals(68.0, $response->json('data.sensor.humidity'));
    }

    #[Test]
    public function telemetri_berumur_20_detik_atau_lebih_menghasilkan_is_stale_true_dan_device_online_false(): void
    {
        // 1. Telemetri dibuat pada 10:30:00
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:30:00'));
        SensorLog::create([
            'room_id'     => $this->room->id,
            'temperature' => 32.0,
            'humidity'    => 70.0,
        ]);

        // 2. Endpoint dipanggil 20 detik kemudian (10:30:20 -> umur 20 detik >= 20s)
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:30:20'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.sensor.is_stale', true)
            ->assertJsonPath('data.sensor.updated_at', '2026-10-26T10:30:00Z')
            ->assertJsonPath('data.device_online', false)
            ->assertJsonPath('data.device_paired', true);

        $this->assertEquals(32.0, $response->json('data.sensor.temperature'));
        $this->assertEquals(70.0, $response->json('data.sensor.humidity'));
    }

    #[Test]
    public function nilai_stale_terakhir_tetap_dikembalikan_dan_tidak_diubah_menjadi_nol(): void
    {
        // 1. Telemetri dibuat pada 10:00:00
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:00:00'));
        SensorLog::create([
            'room_id'     => $this->room->id,
            'temperature' => 32.0,
            'humidity'    => 70.0,
        ]);

        // 2. Endpoint dipanggil 2 jam kemudian (12:00:00)
        Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);

        $temp = $response->json('data.sensor.temperature');
        $hum  = $response->json('data.sensor.humidity');

        // Pastikan tidak diubah menjadi 0 atau null
        $this->assertEquals(32.0, $temp);
        $this->assertEquals(70.0, $hum);
        $this->assertNotEquals(0, $temp);
        $this->assertNotEquals(0, $hum);
        $this->assertNotNull($temp);
        $this->assertNotNull($hum);
        $this->assertTrue($response->json('data.sensor.is_stale'));
        $this->assertFalse($response->json('data.device_online'));
        $this->assertEquals('2026-10-26T10:00:00Z', $response->json('data.sensor.updated_at'));
    }

    #[Test]
    public function belum_pernah_ada_telemetri_mengembalikan_null_dan_is_stale_true(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:00:00'));

        // Tidak ada SensorLog yang dibuat
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.sensor.temperature', null)
            ->assertJsonPath('data.sensor.humidity', null)
            ->assertJsonPath('data.sensor.updated_at', null)
            ->assertJsonPath('data.sensor.is_stale', true)
            ->assertJsonPath('data.device_online', false)
            ->assertJsonPath('data.device_paired', true);
    }

    #[Test]
    public function perangkat_yang_offline_tetap_device_paired_true(): void
    {
        $this->device->update([
            'status'    => 'OFFLINE',
            'is_paired' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.device_paired', true)
            ->assertJsonPath('data.device_online', false);
    }

    #[Test]
    public function room_milik_pengguna_lain_tetap_tidak_dapat_diakses(): void
    {
        $this->actingAs($this->otherUser, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status")
            ->assertStatus(404);
    }

    #[Test]
    public function field_endpoint_lama_tidak_hilang(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:30:00'));

        OccupancyLog::create([
            'room_id'      => $this->room->id,
            'people_count' => 3,
        ]);

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
            'node_battery_low' => false,
        ]);

        ControlLog::create([
            'room_id'            => $this->room->id,
            'temperature'        => 27.5,
            'humidity'           => 65.0,
            'people_count'       => 3,
            'fuzzy_output'       => 23.8,
            'resulting_setpoint' => 24,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/rooms/{$this->room->id}/latest-status");

        $response->assertStatus(200);

        $json = $response->json();

        // 1. Root data fields
        $this->assertArrayHasKey('data', $json);
        $data = $json['data'];

        $this->assertArrayHasKey('sensor', $data);
        $this->assertArrayHasKey('occupancy', $data);
        $this->assertArrayHasKey('ac_status', $data);
        $this->assertArrayHasKey('last_control', $data);
        $this->assertArrayHasKey('node_battery', $data);
        $this->assertArrayHasKey('control_source', $data);
        $this->assertArrayHasKey('node_paired', $data);
        $this->assertArrayHasKey('node_connected', $data);
        $this->assertArrayHasKey('node_battery_low', $data);
        $this->assertArrayHasKey('device_paired', $data);
        $this->assertArrayHasKey('device_online', $data);

        // 2. Top-level node_battery
        $this->assertArrayHasKey('node_battery', $json);

        // 3. Values verification
        $this->assertEquals('USER_NODE', $data['control_source']);
        $this->assertTrue($data['node_paired']);
        $this->assertTrue($data['node_connected']);
        $this->assertFalse($data['node_battery_low']);
        $this->assertEquals(3, $data['occupancy']['people_count']);
        $this->assertEquals('ON', $data['ac_status']['power']);
        $this->assertEquals(24, $data['last_control']['resulting_setpoint']);
    }
}
