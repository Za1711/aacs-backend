<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

#[Fillable([
    'room_id', 'ac_brand', 'power', 'setpoint', 'fan_speed', 'mode', 'ac_mode', 'swing',
    'control_source', 'node_paired', 'node_connected', 'node_battery_low',
])]
class AcLog extends Model
{
    use HasFactory, MassPrunable;

    /**
     * AC logs only have created_at, no updated_at.
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
            'setpoint'         => 'integer',
            'node_paired'      => 'boolean',
            'node_connected'   => 'boolean',
            'node_battery_low' => 'boolean',
        ];
    }

    /**
     * Accessor & Mutator untuk control_source:
     * - Nilai canonical: USER_NODE atau REMOTE.
     * - Nilai legacy 'NODE' dinormalisasi menjadi 'USER_NODE'.
     * - Tidak pernah mengembalikan 'NODE'.
     */
    protected function controlSource(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => ($value === 'NODE' || $value === 'USER_NODE') ? 'USER_NODE' : ($value ?: 'REMOTE'),
            set: fn (?string $value) => ($value !== null && (strtoupper($value) === 'NODE' || strtoupper($value) === 'USER_NODE'))
                ? 'USER_NODE'
                : ($value !== null && strtoupper($value) === 'REMOTE' ? 'REMOTE' : ($value ?: 'REMOTE')),
        );
    }

    /**
     * Get the room that owns the AC log.
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
