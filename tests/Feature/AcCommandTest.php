<?php

namespace Tests\Feature;

use App\Jobs\PublishMqttCommand;
use App\Models\AcLog;
use App\Models\Device;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AcCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->user = User::factory()->create();
        $this->room = Room::create([
            'user_id'         => $this->user->id,
            'name'            => 'Test Room',
            'ac_brand'        => 'Daikin',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ]);

        // Tambah device agar ac-command tidak ditolak dengan 409
        Device::create([
            'room_id'     => $this->room->id,
            'user_id'     => $this->user->id,
            'device_type' => 'ESP32',
            'mac_address' => '78:42:1C:AA:BB:CC',
            'status'      => 'ONLINE',
        ]);
    }

    private function actingAsUser()
    {
        return $this->actingAs($this->user, 'sanctum');
    }

    #[Test]
    public function set_mode_fuzzy_with_value_field(): void
    {
        $response = $this->actingAsUser()->postJson(
            "/api/v1/rooms/{$this->room->id}/ac-command",
            ['command' => 'SET_MODE', 'value' => 'FUZZY']
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.command', 'SET_MODE')
                 ->assertJsonPath('data.value', 'FUZZY');
    }

    #[Test]
    public function set_mode_fuzzy_with_legacy_mode_field(): void
    {
        $response = $this->actingAsUser()->postJson(
            "/api/v1/rooms/{$this->room->id}/ac-command",
            ['command' => 'SET_MODE', 'mode' => 'FUZZY']
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.value', 'FUZZY');
    }

    #[Test]
    public function set_mode_without_value_returns_422(): void
    {
        $response = $this->actingAsUser()->postJson(
            "/api/v1/rooms/{$this->room->id}/ac-command",
            ['command' => 'SET_MODE']
        );

        $response->assertStatus(422);
    }

    #[Test]
    public function set_mode_with_invalid_value_returns_422(): void
    {
        $response = $this->actingAsUser()->postJson(
            "/api/v1/rooms/{$this->room->id}/ac-command",
            ['command' => 'SET_MODE', 'value' => 'INVALID']
        );

        $response->assertStatus(422);
    }

    #[Test]
    public function unauthenticated_request_returns_401_json(): void
    {
        $response = $this->postJson(
            "/api/v1/rooms/{$this->room->id}/ac-command",
            ['command' => 'SET_MODE', 'value' => 'FUZZY']
        );

        $response->assertStatus(401)
                 ->assertJson(['message' => 'Unauthenticated.']);
    }

    #[Test]
    public function set_mode_saves_to_ac_log(): void
    {
        $this->actingAsUser()->postJson(
            "/api/v1/rooms/{$this->room->id}/ac-command",
            ['command' => 'SET_MODE', 'value' => 'FUZZY']
        )->assertStatus(200);

        $this->assertDatabaseHas('ac_logs', [
            'room_id' => $this->room->id,
            'mode'    => 'FUZZY',
        ]);
    }

    #[Test]
    public function ac_command_publishes_exactly_once_to_canonical_topic(): void
    {
        Bus::fake();

        $this->actingAsUser()->postJson(
            "/api/v1/rooms/{$this->room->id}/ac-command",
            ['command' => 'SET_TEMPERATURE', 'value' => '24']
        )->assertStatus(200);

        Bus::assertDispatchedTimes(PublishMqttCommand::class, 1);

        Bus::assertDispatched(
            PublishMqttCommand::class,
            fn ($job) => str_contains($job->topic, '/ac/command')
        );

        Bus::assertNotDispatched(
            PublishMqttCommand::class,
            fn ($job) => preg_match('#room/\d+/command$#', $job->topic)
        );
    }

    #[Test]
    public function set_mode_publishes_exactly_once(): void
    {
        Bus::fake();

        $this->actingAsUser()->postJson(
            "/api/v1/rooms/{$this->room->id}/ac-command",
            ['command' => 'SET_MODE', 'value' => 'FUZZY']
        )->assertStatus(200);

        Bus::assertDispatchedTimes(PublishMqttCommand::class, 1);

        Bus::assertDispatched(
            PublishMqttCommand::class,
            fn ($job) => $job->payload['command'] === 'SET_MODE'
                      && $job->payload['value']   === 'FUZZY'
                      && str_contains($job->topic, '/ac/command')
        );
    }

    #[Test]
    public function set_power_publishes_exactly_once(): void
    {
        Bus::fake();

        $this->actingAsUser()->postJson(
            "/api/v1/rooms/{$this->room->id}/ac-command",
            ['command' => 'SET_POWER', 'value' => 'ON']
        )->assertStatus(200);

        Bus::assertDispatchedTimes(PublishMqttCommand::class, 1);
    }
}
