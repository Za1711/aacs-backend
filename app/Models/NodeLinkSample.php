<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'room_id',
    'session_id',
    'telemetry_seq',
    'telemetry_packet_seq',
    'sent_total',
    'acked_total',
    'failed_total',
    'retries_total',
    'pdr',
    'telemetry_acked',
    'telemetry_received',
    'battery_valid',
    'battery_mv',
    'battery_percent',
    'rssi_dbm',
    'test_complete',
    'received_at',
])]
class NodeLinkSample extends Model
{
    use HasFactory;

    protected $table = 'node_link_samples';

    protected function casts(): array
    {
        return [
            'session_id'           => 'integer',
            'telemetry_seq'        => 'integer',
            'telemetry_packet_seq' => 'integer',
            'sent_total'           => 'integer',
            'acked_total'          => 'integer',
            'failed_total'         => 'integer',
            'retries_total'        => 'integer',
            'pdr'                  => 'float',
            'telemetry_acked'      => 'boolean',
            'telemetry_received'   => 'boolean',
            'battery_valid'        => 'boolean',
            'battery_mv'           => 'integer',
            'battery_percent'      => 'integer',
            'rssi_dbm'             => 'integer',
            'test_complete'        => 'boolean',
            'received_at'          => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
