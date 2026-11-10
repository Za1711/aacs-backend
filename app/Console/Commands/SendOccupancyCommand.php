<?php

namespace App\Console\Commands;

use App\Models\OccupancyLog;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Console\Command;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class SendOccupancyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'occupancy:send {count : Jumlah orang (misal: 0, 1, 2, 3)} {--room= : ID Ruangan (opsional, default: room aktif atau 30)} {--loop : Kirim berulang berkala tiap 60 detik agar tidak stale}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim jumlah orang (occupancy) secara manual ke remote ESP32 via MQTT dan simpan ke database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = (int) $this->argument('count');
        if ($count < 0) {
            $this->error("Jumlah orang tidak boleh negatif!");
            return 1;
        }

        $roomId = $this->option('room');
        if (!$roomId) {
            $room = Room::latest('id')->first();
            $roomId = $room ? $room->id : 30;
        } else {
            $roomId = (int) $roomId;
            $room = Room::find($roomId);
        }

        if (!$room) {
            $this->error("Ruangan dengan ID {$roomId} tidak ditemukan!");
            return 1;
        }

        $topic = "room/{$roomId}/occupancy";
        $host = config('mqtt.host', 'f62988abf955495196dcc5ef9ec94248.s1.eu.hivemq.cloud');
        $port = (int) config('mqtt.port', 8883);
        $username = config('mqtt.username', 'AACS');
        $password = config('mqtt.password', 'Capstone123');

        $isLoop = (bool) $this->option('loop');

        $this->info("==========================================");
        $this->info("  AACS - MANUAL OCCUPANCY SENDER");
        $this->info("==========================================");
        $this->line("Ruangan     : <comment>{$room->name} (ID: {$roomId})</comment>");
        $this->line("Jumlah Orang: <comment>{$count} orang</comment>");
        $this->line("MQTT Topic  : <comment>{$topic}</comment>");
        $this->info("------------------------------------------");

        $sendOnce = function () use ($host, $port, $username, $password, $topic, $roomId, $count) {
            $now = Carbon::now();
            $timeStr = $now->timezone('Asia/Jakarta')->format('H:i:s');

            // 1. Publish ke MQTT
            try {
                $mqtt = new MqttClient($host, $port, 'manual-occ-' . uniqid());
                $settings = (new ConnectionSettings())
                    ->setUsername($username)
                    ->setPassword($password)
                    ->setUseTls(true)
                    ->setTlsVerifyPeer(false)
                    ->setTlsVerifyPeerName(false)
                    ->setConnectTimeout(5)
                    ->setSocketTimeout(5);

                $mqtt->connect($settings, true);
                $mqtt->publish($topic, (string) $count, 1);
                $mqtt->disconnect();
                $this->line("[{$timeStr}] [MQTT] Berhasil publish '{$count}' ke {$topic}");
            } catch (\Throwable $e) {
                $this->error("[{$timeStr}] [MQTT] Gagal publish: {$e->getMessage()}");
            }

            // 2. Simpan ke database
            try {
                $log = OccupancyLog::create([
                    'room_id'      => $roomId,
                    'people_count' => $count,
                    'created_at'   => $now,
                ]);
                $this->line("[{$timeStr}] [DATABASE] Berhasil disimpan (OccupancyLog ID: {$log->id})");
            } catch (\Throwable $e) {
                $this->error("[{$timeStr}] [DATABASE] Gagal simpan: {$e->getMessage()}");
            }
        };

        $sendOnce();

        if ($isLoop) {
            $this->info("------------------------------------------");
            $this->warn("Mode LOOP aktif: Mengirim ulang tiap 60 detik agar data tidak stale.");
            $this->warn("Tekan Ctrl+C di terminal untuk menghentikan.");
            $this->info("------------------------------------------");

            while (true) {
                sleep(60);
                $sendOnce();
            }
        } else {
            $this->info("==========================================");
            $this->info("Selesai! Data okupansi berhasil terkirim.");
            $this->line("Tip: Gunakan opsi --loop jika ingin kirim otomatis tiap 60 detik.");
        }

        return 0;
    }
}
