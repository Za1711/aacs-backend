<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['room_id', 'temperature', 'humidity', 'valid', 'created_at'])]
class NodeTelemetryLog extends Model
{
    use HasFactory, MassPrunable;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'temperature' => 'float',
            'humidity'    => 'float',
            'valid'       => 'boolean',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(3));
    }
}
