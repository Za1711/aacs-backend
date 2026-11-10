<?php

namespace App\Console\Commands;

use App\Models\NodeBatteryLog;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ExportBatteryCsvCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'battery:export-csv {room_id : ID Ruangan} {--days=7 : Rentang hari ke belakang} {--output= : Path file output CSV}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export data riwayat baterai remote node ke file CSV (default 7 hari ke belakang)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $roomId = (int) $this->argument('room_id');
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $days = 7;
        }

        $room = Room::find($roomId);
        if (!$room) {
            $this->error("Room dengan ID {$roomId} tidak ditemukan!");
            return 1;
        }

        $outputPath = $this->option('output');
        if (!$outputPath) {
            $dateStr = now()->format('Y-m-d_H-i-s');
            $outputPath = "/home/zaza/battery_room_{$roomId}_{$dateStr}.csv";
        }

        $since = Carbon::now()->subDays($days);
        $this->info("Mengambil riwayat baterai untuk Room {$room->name} (ID: {$roomId}) sejak {$since->toDateTimeString()}...");

        $handle = fopen($outputPath, 'w');
        if (!$handle) {
            $this->error("Gagal membuka/membuat file output: {$outputPath}");
            return 1;
        }

        // Header CSV
        fputcsv($handle, [
            'id',
            'room_id',
            'timestamp_utc',
            'timestamp_wib',
            'valid',
            'percent',
            'millivolts',
            'voltage_v'
        ]);

        $query = NodeBatteryLog::where('room_id', $roomId)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at', 'asc');

        $count = 0;
        foreach ($query->cursor() as $log) {
            $utcTs = Carbon::parse($log->created_at)->utc()->toIso8601ZuluString();
            $wibTs = Carbon::parse($log->created_at)->timezone('Asia/Jakarta')->format('Y-m-d H:i:s');

            fputcsv($handle, [
                $log->id,
                $log->room_id,
                $utcTs,
                $wibTs,
                $log->valid ? 'TRUE' : 'FALSE',
                $log->percent !== null ? $log->percent : '',
                $log->millivolts !== null ? $log->millivolts : '',
                $log->voltage !== null ? number_format($log->voltage, 3, '.', '') : ''
            ]);
            $count++;
        }

        fclose($handle);

        $this->info("Sukses! Sebanyak {$count} baris data baterai berhasil diexport ke:");
        $this->info($outputPath);

        return 0;
    }
}
