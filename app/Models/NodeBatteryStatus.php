<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['room_id', 'valid', 'millivolts', 'percent', 'received_at'])]
class NodeBatteryStatus extends Model
{
    use HasFactory;

    protected $primaryKey = 'room_id';
    public $incrementing = false;

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'room_id'     => 'integer',
            'valid'       => 'boolean',
            'millivolts'  => 'integer',
            'percent'     => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
