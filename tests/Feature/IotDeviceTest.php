<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IotDeviceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Room $roomA;
    protected Room $roomB;
    protected Device $deviceA;
    protected Device $deviceB;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        
        $this->roomA = Room::create([
            'user_id' => $this->user->id,
            'name' => 'Room A',
            'ac_brand' => 'Daikin'
        ]);
        
        $this->roomB = Room::create([
            'user_id' => $this->user->id,
            'name' => 'Room B',
            'ac_brand' => 'Daikin'
        ]);

        $this->deviceA = Device::create([
            'user_id' => $this->user->id,
            'room_id' => $this->roomA->id,
            'device_type' => 'RASPBERRY_PI',
            'mac_address' => 'AA:BB:CC:11:22:33',
            'status' => 'ONLINE'
        ]);

        $this->deviceB = Device::create([
            'user_id' => $this->user->id,
            'room_id' => $this->roomB->id,
            'device_type' => 'RASPBERRY_PI',
            'mac_address' => 'FF:EE:DD:33:22:11',
            'status' => 'ONLINE'
        ]);
    }

    #[Test]
    public function token_device_a_hanya_mendapatkan_room_milik_device_a()
    {
        Sanctum::actingAs($this->deviceA, ['iot:room-read']);

        $response = $this->getJson('/api/v1/iot/my-room');

        $response->assertStatus(200)
                 ->assertJsonPath('data.room_id', $this->roomA->id)
                 ->assertJsonPath('data.room_name', 'Room A')
                 ->assertJsonPath('data.device_id', $this->deviceA->id);
    }

    #[Test]
    public function mac_palsu_tidak_dapat_mengambil_room_perangkat_lain()
    {
        // Menggunakan token Device A, tetapi mencoba menipu dengan data lain
        // (Secara desain, API ini tidak peduli payload MAC, murni mengandalkan token)
        Sanctum::actingAs($this->deviceA, ['iot:room-read']);

        $response = $this->getJson('/api/v1/iot/my-room?mac_address=FF:EE:DD:33:22:11');

        // Harus tetap mengembalikan room milik Device A, bukan Device B
        $response->assertStatus(200)
                 ->assertJsonPath('data.room_id', $this->roomA->id);
    }

    #[Test]
    public function device_tanpa_room_memperoleh_http_409()
    {
        $deviceOrphan = Device::create([
            'user_id' => $this->user->id,
            'room_id' => null, // Tanpa room
            'device_type' => 'RASPBERRY_PI',
            'mac_address' => '00:11:22:33:44:55'
        ]);

        Sanctum::actingAs($deviceOrphan, ['iot:room-read']);

        $response = $this->getJson('/api/v1/iot/my-room');

        $response->assertStatus(409)
                 ->assertJsonPath('message', 'Perangkat belum dipasangkan ke ruangan manapun.');
    }

    #[Test]
    public function token_invalid_memperoleh_http_401()
    {
        // Request tanpa header Authorization (token invalid/kosong)
        $response = $this->getJson('/api/v1/iot/my-room');
        $response->assertStatus(401);

        // Request dengan token User biasa (Bukan token device)
        Sanctum::actingAs($this->user, ['*']);
        $response2 = $this->getJson('/api/v1/iot/my-room');
        // Akan mengembalikan 401 karena middleware atau controller menolak instansiasi
        $response2->assertStatus(401)
                  ->assertJsonPath('message', 'Token tidak valid untuk endpoint IoT.');
    }

    #[Test]
    public function setelah_device_dipindahkan_endpoint_langsung_mengembalikan_room_baru()
    {
        Sanctum::actingAs($this->deviceA, ['iot:room-read']);

        // Awalnya di Room A
        $this->getJson('/api/v1/iot/my-room')
             ->assertJsonPath('data.room_id', $this->roomA->id);

        // Pindahkan Device A ke Room B di database
        $this->deviceA->update(['room_id' => $this->roomB->id]);

        // Cek lagi tanpa mengganti token
        $this->getJson('/api/v1/iot/my-room')
             ->assertJsonPath('data.room_id', $this->roomB->id)
             ->assertJsonPath('data.room_name', 'Room B')
             ->assertJsonPath('data.occupancy_topic', "room/{$this->roomB->id}/occupancy");
    }

    #[Test]
    public function hanya_device_tipe_raspberry_pi_yang_boleh_memakai_endpoint()
    {
        $deviceEsp = Device::create([
            'user_id' => $this->user->id,
            'room_id' => $this->roomA->id,
            'device_type' => 'ESP32', // Bukan RASPBERRY_PI
            'mac_address' => 'AA:AA:AA:AA:AA:AA'
        ]);

        Sanctum::actingAs($deviceEsp, ['iot:room-read']);

        $response = $this->getJson('/api/v1/iot/my-room');

        $response->assertStatus(403)
                 ->assertJsonPath('message', 'Hanya perangkat tipe RASPBERRY_PI yang diizinkan.');
    }
}
