<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'name', 'ac_brand', 'target_temp_min', 'target_temp_max'])]
class Room extends Model
{
    use HasFactory;

    public const NODE_BATTERY_TIMEOUT_SECONDS = 30;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_temp_min' => 'integer',
            'target_temp_max' => 'integer',
        ];
    }

    /**
     * Relasi ke status baterai node sensor.
     */
    public function nodeBatteryStatus(): HasOne
    {
        return $this->hasOne(NodeBatteryStatus::class, 'room_id');
    }

    /**
     * Relasi ke riwayat baterai node sensor (disimpan 7 hari).
     */
    public function nodeBatteryLogs(): HasMany
    {
        return $this->hasMany(NodeBatteryLog::class, 'room_id');
    }

    /**
     * Relasi ke sampel metrik link ESP-NOW node sensor (PDR, RSSI, Battery).
     */
    public function nodeLinkSamples(): HasMany
    {
        return $this->hasMany(NodeLinkSample::class, 'room_id');
    }

    /**
     * Relasi ke status telemetri terkini node sensor (suhu & kelembaban).
     */
    public function nodeTelemetryLogs(): HasMany
    {
        return $this->hasMany(NodeTelemetryLog::class);
    }

    public function nodeTelemetryStatus(): HasOne
    {
        return $this->hasOne(NodeTelemetryStatus::class, 'room_id');
    }

    /**
     * Relasi ke status heartbeat Remote ESP32 terkini.
     */
    public function remoteDeviceStatus(): HasOne
    {
        return $this->hasOne(RemoteDeviceStatus::class, 'room_id');
    }

    /**
     * Get the formatted node battery status with freshness check.
     *
     * @return array{valid: bool, is_stale: bool, millivolts: int|null, voltage: float|null, percent: int|null, updated_at: string|null}
     */
    public function getNodeBatteryStatus(): array
    {
        $timeout = config('mqtt.node_battery_timeout', self::NODE_BATTERY_TIMEOUT_SECONDS);
        $battery = $this->nodeBatteryStatus;

        if (! $battery || is_null($battery->received_at)) {
            return [
                'valid'      => false,
                'is_stale'   => true,
                'millivolts' => null,
                'voltage'    => null,
                'percent'    => null,
                'updated_at' => null,
            ];
        }

        $ageSeconds = now()->getTimestamp() - $battery->received_at->getTimestamp();
        $isStale = $ageSeconds > $timeout;

        // Saat umur status baterai lebih dari 30 detik:
        // hasil API harus valid=false; is_stale=true; millivolts, voltage, dan percent harus null
        if ($isStale) {
            return [
                'valid'      => false,
                'is_stale'   => true,
                'millivolts' => null,
                'voltage'    => null,
                'percent'    => null,
                'updated_at' => $battery->received_at->toISOString(),
            ];
        }

        // Untuk payload eksplisit valid=false yang masih baru (< timeout):
        if (! $battery->valid) {
            return [
                'valid'      => false,
                'is_stale'   => false,
                'millivolts' => null,
                'voltage'    => null,
                'percent'    => null,
                'updated_at' => $battery->received_at->toISOString(),
            ];
        }

        // Payload valid yang masih baru:
        $mv = $battery->millivolts;
        $voltage = ! is_null($mv) ? (float) ($mv / 1000.0) : null;

        return [
            'valid'      => true,
            'is_stale'   => false,
            'millivolts' => (int) $mv,
            'voltage'    => $voltage,
            'percent'    => (int) $battery->percent,
            'updated_at' => $battery->received_at->toISOString(),
        ];
    }

    /**
     * Get the devices for the room.
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Get the sensor logs for the room.
     */
    public function sensorLogs(): HasMany
    {
        return $this->hasMany(SensorLog::class);
    }

    /**
     * Get the occupancy logs for the room.
     */
    public function occupancyLogs(): HasMany
    {
        return $this->hasMany(OccupancyLog::class);
    }

    /**
     * Get the AC logs for the room.
     */
    public function acLogs(): HasMany
    {
        return $this->hasMany(AcLog::class);
    }

    /**
     * Get the control logs for the room.
     */
    public function controlLogs(): HasMany
    {
        return $this->hasMany(ControlLog::class);
    }

    /**
     * Get the user that owns the room.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
