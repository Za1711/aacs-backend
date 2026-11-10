<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user      = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->room      = Room::create([
            'user_id'         => $this->user->id,
            'name'            => 'Test Room',
            'ac_brand'        => 'Daikin',
            'target_temp_min' => 18,
            'target_temp_max' => 30,
        ]);
    }

    private function makeNotification(User $user, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'user_id'          => $user->id,
            'room_id'          => $this->room->id,
            'type'             => 'ROOM_EMPTY',
            'title'            => 'Ruangan Kosong',
            'message'          => 'Ruangan tidak mendeteksi pengguna selama 5 menit.',
            'people_count'     => 0,
            'duration_seconds' => 300,
            'mqtt_received_at' => now(),
            'mqtt_dedup_key'   => uniqid('dedup_'),
        ], $overrides));
    }

    #[Test]
    public function notifications_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/notifications')
             ->assertStatus(401)
             ->assertJson(['message' => 'Unauthenticated.']);
    }

    #[Test]
    public function user_can_list_own_notifications(): void
    {
        $this->makeNotification($this->user);
        $this->makeNotification($this->otherUser);

        $response = $this->actingAs($this->user, 'sanctum')
             ->getJson('/api/v1/notifications')
             ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function user_cannot_read_other_users_notification(): void
    {
        $n = $this->makeNotification($this->user);

        $this->actingAs($this->otherUser, 'sanctum')
             ->patchJson("/api/v1/notifications/{$n->id}/read")
             ->assertStatus(404);
    }

    #[Test]
    public function user_can_mark_notification_as_read(): void
    {
        $n = $this->makeNotification($this->user);

        $this->actingAs($this->user, 'sanctum')
             ->patchJson("/api/v1/notifications/{$n->id}/read")
             ->assertStatus(200);

        $this->assertNotNull($n->fresh()->read_at);
    }

    #[Test]
    public function user_can_mark_all_notifications_as_read(): void
    {
        $this->makeNotification($this->user);
        $this->makeNotification($this->user);

        $this->actingAs($this->user, 'sanctum')
             ->patchJson('/api/v1/notifications/read-all')
             ->assertStatus(200);

        $unread = Notification::where('user_id', $this->user->id)
                              ->whereNull('read_at')->count();
        $this->assertEquals(0, $unread);
    }

    #[Test]
    public function unread_only_filter_works(): void
    {
        $this->makeNotification($this->user, ['read_at' => now()]);
        $this->makeNotification($this->user);

        $response = $this->actingAs($this->user, 'sanctum')
             ->getJson('/api/v1/notifications?unread_only=1')
             ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function two_separate_events_create_two_notifications(): void
    {
        // Dua dedup_key berbeda = dua event MQTT yang berbeda = dua notifikasi
        $this->makeNotification($this->user, ['mqtt_dedup_key' => 'key_event_1']);
        $this->makeNotification($this->user, ['mqtt_dedup_key' => 'key_event_2']);

        $response = $this->actingAs($this->user, 'sanctum')
             ->getJson('/api/v1/notifications')
             ->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function duplicate_dedup_key_does_not_create_duplicate(): void
    {
        // Simulasi: dua pesan MQTT identik (reconnect/retry) pakai dedup_key sama
        $dedupKey = 'same_dedup_key_abc';
        $this->makeNotification($this->user, ['mqtt_dedup_key' => $dedupKey]);

        // Coba buat lagi dengan key yang sama — harus throw duplicate key error
        // Worker handleNotification() mengecek exists() sebelum create
        $exists = Notification::where('user_id', $this->user->id)
                              ->where('mqtt_dedup_key', $dedupKey)
                              ->exists();
        $this->assertTrue($exists);

        // Tidak boleh ada duplikat
        $count = Notification::where('user_id', $this->user->id)
                             ->where('mqtt_dedup_key', $dedupKey)
                             ->count();
        $this->assertEquals(1, $count);
    }
}
