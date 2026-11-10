<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['room_id', 'last_seen_at'])]
class RemoteDeviceStatus extends Model
{
    use HasFactory;

    protected $table = 'remote_device_statuses';
    protected $primaryKey = 'room_id';
    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Get the room that owns the remote device status.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
