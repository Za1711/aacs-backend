<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'room_id', 'type', 'title', 'message',
    'people_count', 'duration_seconds',
    'read_at', 'mqtt_received_at', 'mqtt_dedup_key',
])]
class Notification extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'read_at'          => 'datetime',
            'mqtt_received_at' => 'datetime',
            'people_count'     => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
