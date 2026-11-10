<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['room_id', 'temperature', 'humidity', 'people_count', 'fuzzy_output', 'resulting_setpoint'])]
class ControlLog extends Model
{
    use HasFactory;

    /**
     * Control logs only have created_at, no updated_at.
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
            'humidity' => 'float',
            'people_count' => 'integer',
            'fuzzy_output' => 'float',
            'resulting_setpoint' => 'integer',
        ];
    }

    /**
     * Get the room that owns the control log.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
