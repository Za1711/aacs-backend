<?php

namespace Tests\Feature;

use App\Models\OccupancyLog;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TelemetryStaleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function latest_status_menandai_occupancy_sebagai_stale_jika_lama()
    {
        $user = User::factory()->create();
        $room = Room::create(['user_id' => $user->id, 'name' => 'Room 26', 'ac_brand' => 'Daikin']);

        DB::table('occupancy_logs')->insert([
            'room_id' => $room->id,
            'people_count' => 3,
            'created_at' => now()->subSeconds(200), // > 180s
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/rooms/{$room->id}/latest-status");

        $response->assertStatus(200)
                 ->assertJsonPath('data.occupancy.people_count', 3)
                 ->assertJsonPath('data.occupancy.is_stale', true);
    }
}
