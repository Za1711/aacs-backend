<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['room_id', 'valid', 'temperature', 'humidity', 'received_at'])]
class RemoteTelemetryStatus extends Model
{
    use HasFactory;

    protected $primaryKey = 'room_id';
    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'valid'       => 'boolean',
            'temperature' => 'float',
            'humidity'    => 'float',
            'received_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
