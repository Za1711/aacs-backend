<?php

namespace App\Console\Commands;

use App\Models\OccupancyLog;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Console\Command;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class CountdownOccupancyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'occupancy:countdown {start_count=3 : Jumlah awal orang (default 3)} {--interval=10 : Interval menit per pengurangan (default 10 menit)} {--room= : ID Ruangan (default ruangan aktif)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulasi penurunan jumlah orang 1 tiap 10 menit hingga 0, lalu otomatis menyalakan YOLO kembali';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startCount = max(0, (int) $this->argument('start_count'));
        $intervalMinutes = max(0.1, (float) $this->option('interval'));
        $intervalSeconds = (int) ($intervalMinutes * 60);

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

        $this->info("=======================================================");
        $this->info("   AACS - SIMULASI PENURUNAN JUMLAH ORANG (ARTISAN)");
        $this->info("=======================================================");
        $this->line("Ruangan       : <comment>{$room->name} (ID: {$roomId})</comment>");
        $this->line("Jumlah Awal   : <comment>{$startCount} orang</comment>");
        $this->line("Interval Turun: <comment>{$intervalMinutes} menit per tahap</comment>");
        $this->line("Target Akhir  : <comment>0 orang selama {$intervalMinutes} menit, lalu aktifkan YOLO kembali</comment>");
        $this->info("-------------------------------------------------------");

        // 1. Matikan supervisor aacs-yolo
        $this->warn("[YOLO] Mematikan service aacs-yolo...");
        shell_exec("echo 'mirza1711' | sudo -S supervisorctl stop aacs-yolo");
        $statusStop = trim(shell_exec("sudo supervisorctl status aacs-yolo"));
        $this->line("[YOLO Status] {$statusStop}");

        $sendPayload = function ($count) use ($host, $port, $username, $password, $topic, $roomId) {
            $now = Carbon::now();
            $mOk = false;
            $dOk = false;

            try {
                $mqtt = new MqttClient($host, $port, 'countdown-occ-' . uniqid());
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
                $mOk = true;
            } catch (\Throwable $e) {
                // Ignore transient network errors
            }

            try {
                OccupancyLog::create([
                    'room_id'      => $roomId,
                    'people_count' => $count,
                    'created_at'   => $now,
                ]);
                $dOk = true;
            } catch (\Throwable $e) {
                // Ignore DB transient
            }

            return [$mOk, $dOk];
        };

        try {
            $current = $startCount;
            while ($current >= 0) {
                $this->info("-------------------------------------------------------");
                $this->info(">>> MEMULAI TAHAP: {$current} ORANG (Selama {$intervalMinutes} menit) <<<");

                $elapsed = 0;
                $stepInterval = 60; // Refresh tiap 60 detik agar tidak stale

                while ($elapsed < $intervalSeconds) {
                    [$mOk, $dOk] = $sendPayload($current);
                    $remMins = max(0, (int) (($intervalSeconds - $elapsed) / 60));
                    $remSecs = ($intervalSeconds - $elapsed) % 60;
                    $timeStr = now()->timezone('Asia/Jakarta')->format('H:i:s');

                    $this->line("[{$timeStr}] Kirim: <comment>{$current} orang</comment> | MQTT: " . ($mOk ? '<info>OK</info>' : '<error>FAIL</error>') . " | DB: " . ($dOk ? '<info>OK</info>' : '<error>FAIL</error>') . " | Sisa tahap ini: {$remMins}m {$remSecs}s");

                    $sleepTime = min($stepInterval, $intervalSeconds - $elapsed);
                    sleep($sleepTime);
                    $elapsed += $sleepTime;
                }

                $this->line("Tahap {$current} orang selesai.");
                $current--;
            }

            $this->info("=======================================================");
            $this->info("Semua tahap penurunan selesai (termasuk interval di angka 0).");
        } finally {
            // 2. Nyalakan kembali supervisor aacs-yolo
            $this->info("-------------------------------------------------------");
            $this->warn("[YOLO] Menyalakan kembali service aacs-yolo...");
            shell_exec("echo 'mirza1711' | sudo -S supervisorctl start aacs-yolo");
            $statusStart = trim(shell_exec("sudo supervisorctl status aacs-yolo"));
            $this->line("[YOLO Status] {$statusStart}");
            $this->info("Sistem deteksi kamera YOLO telah aktif kembali secara normal.");
            $this->info("=======================================================");
        }

        return 0;
    }
}
