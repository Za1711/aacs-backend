<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['room_id', 'valid', 'millivolts', 'percent', 'created_at'])]
class NodeBatteryLog extends Model
{
    use HasFactory, MassPrunable;

    /**
     * Battery logs only have created_at, no updated_at.
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
            'valid'      => 'boolean',
            'millivolts' => 'integer',
            'percent'    => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Accessor for voltage in Volts (e.g. 3.937 V).
     */
    public function getVoltageAttribute(): ?float
    {
        return $this->millivolts !== null ? round($this->millivolts / 1000.0, 3) : null;
    }

    /**
     * Get the room that owns the battery log.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Tentukan query untuk data yang bisa dipangkas (Prunable) secara massal.
     * Khusus data baterai: simpan 7 hari ke belakang, hapus jika > 7 hari.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(7));
    }
}
