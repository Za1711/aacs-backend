<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;

class Device extends Model implements Authenticatable
{
    use HasApiTokens, HasFactory, AuthenticatableTrait;

    protected $fillable = [
        'room_id',
        'user_id',
        'device_type',
        'serial_number',
        'firmware_version',
        'mac_address',
        'ip_address',
        'status',
        'is_paired',
        'mode',
        'last_ping',
    ];

    protected $casts = [
        'is_paired' => 'boolean',
        'last_ping' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
