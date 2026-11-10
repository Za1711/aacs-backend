<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Device;

class GenerateIotTokenCommand extends Command
{
    protected $signature = 'iot:token {device_id} {--revoke-existing : Hapus semua token lama untuk perangkat ini}';
    protected $description = 'Generate a Sanctum API token for an IoT Device';

    public function handle()
    {
        $deviceId = $this->argument('device_id');
        $device = Device::find($deviceId);

        if (!$device) {
            $this->error("Device dengan ID {$deviceId} tidak ditemukan.");
            return 1;
        }

        if ($device->device_type !== 'RASPBERRY_PI') {
            $this->warn("Peringatan: Perangkat ini bukan RASPBERRY_PI (Tipe: {$device->device_type})");
        }

        if ($this->option('revoke-existing')) {
            $device->tokens()->delete();
            $this->info("Semua token lama untuk Device ID {$device->id} telah di-revoke.");
        }

        // Generate token
        $tokenName = 'pi-token-' . now()->timestamp;
        $token = $device->createToken($tokenName, ['iot:room-read'])->plainTextToken;

        $this->info("Token berhasil dibuat untuk Device ID: {$device->id}");
        $this->info("MAC Address: {$device->mac_address}");
        $this->line("Token (Simpan baik-baik, hanya ditampilkan sekali):");
        $this->line($token);

        return 0;
    }
}
