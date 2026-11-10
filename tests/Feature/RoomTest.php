<?php

namespace Tests\Feature;

use App\Jobs\PublishMqttCommand;
use App\Models\AcLog;
use App\Models\Device;
use App\Models\Notification;
use App\Models\Room;
use App\Models\SensorLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoomTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user      = User::factory()->create();
        $this->otherUser = User::factory()->create();
    }

    private function createRoom(User $user, array $attrs = []): Room
    {
        return Room::create(array_merge([
            'user_id'         => $user->id,
            'name'            => 'Test Room',
            'ac_brand'        => 'Daikin',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ], $attrs));
    }

    private function createDevice(Room $room, array $attrs = []): Device
    {
        static $counter = 0;
        $counter++;
        return Device::create(array_merge([
            'room_id'     => $room->id,
            'user_id'     => $room->user_id,
            'device_type' => 'ESP32',                       // ENUM valid: ESP32 | RASPBERRY_PI
            'mac_address' => sprintf('AA:BB:CC:DD:EE:%02X', $counter),
            'status'      => 'ONLINE',
            'is_paired'   => true,
        ], $attrs));
    }

    // ── Case 1: User hanya bisa akses room miliknya ───────────────────────────

    #[Test]
    public function user_can_only_see_own_rooms(): void
    {
        $this->createRoom($this->user, ['name' => 'My Room']);
        $this->createRoom($this->otherUser, ['name' => 'Other Room']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/rooms')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('My Room'));
        $this->assertFalse($names->contains('Other Room'));
    }

    #[Test]
    public function user_cannot_send_command_to_other_users_room(): void
    {
        Bus::fake();

        $otherRoom = $this->createRoom($this->otherUser);
        $this->createDevice($otherRoom);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/rooms/{$otherRoom->id}/ac-command", [
                'command' => 'SET_POWER',
                'value'   => 'ON',
            ])
            ->assertStatus(404);
    }

    // ── Case 2: Command ke room tanpa device → 409 ────────────────────────────

    #[Test]
    public function ac_command_to_room_without_device_returns_409(): void
    {
        Bus::fake();

        $room = $this->createRoom($this->user);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/rooms/{$room->id}/ac-command", [
                'command' => 'SET_POWER',
                'value'   => 'ON',
            ])
            ->assertStatus(409)
            ->assertJsonFragment([
                'message' => 'Ruangan belum memiliki perangkat. Pasangkan remote ESP32 terlebih dahulu.',
            ]);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function set_temperature_to_room_without_device_returns_409(): void
    {
        $room = $this->createRoom($this->user);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/rooms/{$room->id}/ac-command/temperature", [
                'temperature' => 24,
            ])
            ->assertStatus(409);
    }

    #[Test]
    public function set_power_to_room_without_device_returns_409(): void
    {
        $room = $this->createRoom($this->user);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/rooms/{$room->id}/ac-command/power", [
                'power' => 'ON',
            ])
            ->assertStatus(409);
    }

    // ── Case 3: Room dengan 0 device dapat dihapus ───────────────────────────

    #[Test]
    public function room_with_no_device_can_be_deleted(): void
    {
        $room = $this->createRoom($this->user);
        $roomId = $room->id;

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/rooms/{$room->id}")
            ->assertStatus(200)
            ->assertJsonFragment(['message' => 'Ruangan berhasil dihapus.']);

        $this->assertDatabaseMissing('rooms', ['id' => $roomId]);
    }

    #[Test]
    public function room_with_related_data_but_no_device_can_be_deleted(): void
    {
        $room = $this->createRoom($this->user);

        AcLog::create([
            'room_id'  => $room->id,
            'ac_brand' => 'Daikin',
            'power'    => 'OFF',
            'setpoint' => 24,
            'mode'     => 'MANUAL',
            'ac_mode'  => 'COOL',
            'fan_speed'=> 'AUTO',
            'swing'    => 'AUTO',
        ]);
        SensorLog::create([
            'room_id'     => $room->id,
            'temperature' => 24.5,
            'humidity'    => 65.0,
        ]);
        Notification::create([
            'user_id'          => $this->user->id,
            'room_id'          => $room->id,
            'type'             => 'ROOM_EMPTY',
            'title'            => 'Ruangan Kosong',
            'message'          => 'Test',
            'people_count'     => 0,
            'duration_seconds' => 300,
            'mqtt_received_at' => now(),
            'mqtt_dedup_key'   => 'test_dedup_delete_' . $room->id,
        ]);

        $roomId = $room->id;

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/rooms/{$room->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('rooms', ['id' => $roomId]);
        $this->assertDatabaseMissing('ac_logs', ['room_id' => $roomId]);
        $this->assertDatabaseMissing('sensor_logs', ['room_id' => $roomId]);
        $this->assertDatabaseMissing('notifications', ['room_id' => $roomId]);
    }

    // ── Case 4: Room dengan device dapat dihapus; device menjadi unpaired ────
    // FK devices.room_id sudah ON DELETE SET NULL — device tidak ikut terhapus

    #[Test]
    public function room_with_device_can_be_deleted_and_device_becomes_unpaired(): void
    {
        Bus::fake();

        $room     = $this->createRoom($this->user);
        $device   = $this->createDevice($room);
        $roomId   = $room->id;
        $deviceId = $device->id;

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/rooms/{$room->id}")
            ->assertStatus(200);

        // Room terhapus
        $this->assertDatabaseMissing('rooms', ['id' => $roomId]);

        // Device masih ada dengan room_id = null (unpaired via SET NULL FK)
        $this->assertDatabaseHas('devices', [
            'id'      => $deviceId,
            'room_id' => null,
        ]);

        // UNPAIR command dikirim ke MQTT
        Bus::assertDispatched(PublishMqttCommand::class, fn ($job) =>
            str_contains($job->topic, '/ac/command') &&
            $job->payload['command'] === 'UNPAIR'
        );
    }

    // ── Case 5: Pairing gagal tidak meninggalkan orphan room yang tidak bisa dihapus

    #[Test]
    public function orphan_room_detectable_by_devices_count(): void
    {
        $orphanRoom = $this->createRoom($this->user, ['name' => 'Orphan Room']);
        $normalRoom = $this->createRoom($this->user, ['name' => 'Normal Room']);
        $this->createDevice($normalRoom);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/rooms')
            ->assertStatus(200);

        $data = collect($response->json('data'));
        $this->assertEquals(0, $data->firstWhere('name', 'Orphan Room')['devices_count']);
        $this->assertEquals(1, $data->firstWhere('name', 'Normal Room')['devices_count']);
    }

    #[Test]
    public function orphan_room_can_be_deleted_without_500(): void
    {
        $orphan = $this->createRoom($this->user, ['name' => 'Orphan Room']);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/rooms/{$orphan->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('rooms', ['id' => $orphan->id]);
    }

    // ── Case 6: GET rooms mengembalikan devices_count yang benar ─────────────

    #[Test]
    public function get_rooms_returns_correct_devices_count(): void
    {
        $room0 = $this->createRoom($this->user, ['name' => 'Zero Devices']);
        $room1 = $this->createRoom($this->user, ['name' => 'One Device']);
        $room2 = $this->createRoom($this->user, ['name' => 'Two Devices']);

        $this->createDevice($room1);
        $this->createDevice($room2);
        $this->createDevice($room2);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/rooms')
            ->assertStatus(200);

        $data = collect($response->json('data'));

        $this->assertEquals(0, $data->firstWhere('name', 'Zero Devices')['devices_count']);
        $this->assertEquals(1, $data->firstWhere('name', 'One Device')['devices_count']);
        $this->assertEquals(2, $data->firstWhere('name', 'Two Devices')['devices_count']);
    }

    #[Test]
    public function user_cannot_delete_other_users_room(): void
    {
        $otherRoom = $this->createRoom($this->otherUser);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/rooms/{$otherRoom->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('rooms', ['id' => $otherRoom->id]);
    }
}
