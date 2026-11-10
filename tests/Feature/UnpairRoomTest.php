<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Room;
use App\Models\User;
use App\Models\OccupancyLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnpairRoomTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Room $room;
    protected Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->room = Room::create([
            'user_id' => $this->user->id,
            'name' => 'Living Room',
            'ac_brand' => 'Daikin'
        ]);

        $this->device = Device::create([
            'user_id' => $this->user->id,
            'room_id' => $this->room->id,
            'device_type' => 'ESP32',
            'serial_number' => 'ABC123XYZ',
            'is_paired' => true,
        ]);
    }

    #[Test]
    public function owner_berhasil_unpair_dan_room_tetap_ada()
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/rooms/{$this->room->id}/unpair");

        $response->assertStatus(200)
                 ->assertJsonPath('message', 'Perangkat berhasil dilepas. Ruangan tetap tersedia.')
                 ->assertJsonPath('data.room_id', $this->room->id)
                 ->assertJsonPath('data.devices_count', 0);

        // Room tetap ada
        $this->assertDatabaseHas('rooms', ['id' => $this->room->id]);

        // Device room_id menjadi null dan is_paired false
        $this->assertDatabaseHas('devices', [
            'id' => $this->device->id,
            'room_id' => null,
            'is_paired' => false
        ]);
    }

    #[Test]
    public function unpair_room_kosong_tetap_http_200()
    {
        $this->device->update(['room_id' => null]); // Kosongkan room

        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/rooms/{$this->room->id}/unpair");

        $response->assertStatus(200)
                 ->assertJsonPath('data.devices_count', 0);
    }

    #[Test]
    public function user_lain_tidak_dapat_unpair_room_tersebut()
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser, 'sanctum')->postJson("/api/v1/rooms/{$this->room->id}/unpair");

        $response->assertStatus(404); // Fail findOrFail for user's room
    }

    #[Test]
    public function perangkat_yang_sama_bisa_dipasangkan_kembali_setelah_unpair()
    {
        // 1. Unpair
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/rooms/{$this->room->id}/unpair")->assertStatus(200);

        // 2. Pair kembali
        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/devices/pair", [
            'serial_number' => 'ABC123XYZ',
            'room_id' => $this->room->id
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('devices', [
            'serial_number' => 'ABC123XYZ',
            'room_id' => $this->room->id,
            'is_paired' => true
        ]);
    }

    #[Test]
    public function latest_status_tidak_mengembalikan_telemetri_lama_setelah_unpair()
    {
        // Insert fake log
        OccupancyLog::create([
            'room_id' => $this->room->id,
            'people_count' => 5
        ]);

        // Pastikan ada sebelum unpair
        $this->actingAs($this->user, 'sanctum')
             ->getJson("/api/v1/rooms/{$this->room->id}/latest-status")
             ->assertJsonPath('data.occupancy.people_count', 5);

        // Unpair
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/rooms/{$this->room->id}/unpair")->assertStatus(200);

        // Cek latest-status
        $this->actingAs($this->user, 'sanctum')
             ->getJson("/api/v1/rooms/{$this->room->id}/latest-status")
             ->assertJsonPath('data.occupancy', null);
    }
}
