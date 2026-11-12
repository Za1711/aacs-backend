<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['room_id', 'temperature', 'humidity', 'source', 'valid', 'created_at'])]
class SensorLog extends Model
{
    use HasFactory, MassPrunable;

    /**
     * Sensor logs only have created_at, no updated_at.
     */
    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'temperature' => 'float',
            'humidity'    => 'float',
            'valid'       => 'boolean',
        ];
    }

    /**
     * Get the room that owns the sensor log.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Tentukan query untuk data yang bisa dipangkas (Prunable) secara massal.
     * Hapus data yang usianya lebih dari 3 hari.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(3));
    }
}
