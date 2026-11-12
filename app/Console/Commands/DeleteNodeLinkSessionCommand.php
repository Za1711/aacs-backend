<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\NodeLinkMetricsController;
use App\Models\NodeLinkSample;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DeleteNodeLinkSessionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'node-link:delete-session 
                            {roomId : ID Ruangan} 
                            {sessionId : ID Sesi Node Link} 
                            {--backup : Cadangkan data sesi ke file CSV sebelum dihapus}
                            {--force : Hapus langsung tanpa konfirmasi manual}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Menghapus sesi link metrics node tertentu secara aman dengan opsi backup CSV';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $roomId = (int) $this->argument('roomId');
        $sessionId = (int) $this->argument('sessionId');

        $room = Room::find($roomId);
        if (!$room) {
            $this->error("Ruangan dengan ID {$roomId} tidak ditemukan.");
            return 1;
        }

        $query = NodeLinkSample::where('room_id', $roomId)->where('session_id', $sessionId);
        $count = $query->count();

        if ($count === 0) {
            $this->error("Sesi {$sessionId} pada ruangan {$roomId} ('{$room->name}') tidak ditemukan atau memiliki 0 data.");
            return 1;
        }

        $firstSample = (clone $query)->orderBy('telemetry_seq', 'asc')->first();
        $lastSample = (clone $query)->orderBy('telemetry_seq', 'desc')->first();

        $this->info("=== DETAIL SESI YANG AKAN DIHAPUS ===");
        $this->line("Ruangan     : {$room->name} (ID: {$roomId})");
        $this->line("Session ID  : {$sessionId}");
        $this->line("Jumlah Row  : {$count} baris");
        $this->line("Sampel Awal : " . ($firstSample?->received_at ?? '-') . " (Seq: " . ($firstSample?->telemetry_seq ?? '-') . ")");
        $this->line("Sampel Akhir: " . ($lastSample?->received_at ?? '-') . " (Seq: " . ($lastSample?->telemetry_seq ?? '-') . ")");

        // Export backup CSV jika diminta atau secara default untuk keamanan
        $backupPath = $this->createBackupCsv($roomId, $sessionId);
        $this->info("Backup CSV berhasil disimpan ke: {$backupPath}");

        if (!$this->option('force')) {
            if (!$this->confirm("Apakah Anda yakin ingin MENGHAPUS PERMANEN {$count} baris sesi {$sessionId} pada room {$roomId}? Data sesi lain tidak akan terpengaruh.", false)) {
                $this->warn("Operasi dibatalkan oleh pengguna. Tidak ada data yang dihapus.");
                return 0;
            }
        }

        $deleted = NodeLinkSample::where('room_id', $roomId)
            ->where('session_id', $sessionId)
            ->delete();

        $this->info("Sukses: {$deleted} baris untuk sesi {$sessionId} (Room {$roomId}) berhasil dihapus.");
        $this->line("Sesi lain pada ruangan {$roomId} tetap aman dan tidak tersentuh.");

        return 0;
    }

    /**
     * Membuat file backup CSV sebelum penghapusan.
     */
    protected function createBackupCsv(int $roomId, int $sessionId): string
    {
        $backupDir = storage_path('app/backups');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Ymd_His');
        $fileName = "node_link_backup_room_{$roomId}_session_{$sessionId}_{$timestamp}.csv";
        $filePath = "{$backupDir}/{$fileName}";

        $controller = app(NodeLinkMetricsController::class);
        $summary = $controller->computeSessionSummary($roomId, $sessionId);

        $file = fopen($filePath, 'w');
        fputs($file, "\xEF\xBB\xBF"); // BOM UTF-8

        fputcsv($file, [
            'Record_Type',
            'Room_ID',
            'Session_ID',
            'Telemetry_Seq',
            'Telemetry_Packet_Seq',
            'Timestamp_UTC',
            'Timestamp_WIB',
            'Sensor_Valid',
            'Temperature_C',
            'Humidity_Percent',
            'Battery_Valid',
            'Battery_mV',
            'Battery_Percent',
            'RSSI_dBm',
            'Delivery_Success',
            'Telemetry_Received',
            'Sent_Total',
            'Acked_Total',
            'Failed_Total',
            'Retries_Total',
            'PDR_Percent',
            'Sequence_Gap',
            'Test_Complete',
            'Session_State',
            'RSSI_Valid_Count',
            'RSSI_Average_dBm',
            'RSSI_Minimum_dBm',
            'RSSI_Maximum_dBm',
            'RSSI_Latest_dBm',
            'Samples_Visible',
            'Unobserved_Sample_Rows',
            'Data_Completeness_Percent',
        ]);

        $prevSeq = null;

        NodeLinkSample::where('room_id', $roomId)
            ->where('session_id', $sessionId)
            ->orderBy('telemetry_seq', 'asc')
            ->chunk(500, function ($samples) use ($file, $summary, &$prevSeq) {
                foreach ($samples as $s) {
                    $utcTs = Carbon::parse($s->received_at, 'UTC')->utc()->toIso8601ZuluString();
                    $wibTs = Carbon::parse($s->received_at, 'UTC')->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s');

                    $currSeq = (int) $s->telemetry_seq;
                    if ($prevSeq === null) {
                        $sequenceGap = max(0, $currSeq - 1);
                    } else {
                        $sequenceGap = max(0, $currSeq - $prevSeq - 1);
                    }
                    $prevSeq = $currSeq;

                    fputcsv($file, [
                        'SAMPLE',
                        $s->room_id,
                        $s->session_id,
                        $s->telemetry_seq,
                        $s->telemetry_packet_seq,
                        $utcTs,
                        $wibTs,
                        $s->sensor_valid ? 'TRUE' : 'FALSE',
                        ($s->sensor_valid && $s->temperature_c !== null) ? number_format((float) $s->temperature_c, 2, '.', '') : '',
                        ($s->sensor_valid && $s->humidity_percent !== null) ? number_format((float) $s->humidity_percent, 2, '.', '') : '',
                        $s->battery_valid ? 'TRUE' : 'FALSE',
                        ($s->battery_valid && $s->battery_mv !== null) ? $s->battery_mv : '',
                        ($s->battery_valid && $s->battery_percent !== null) ? $s->battery_percent : '',
                        ($s->telemetry_received && $s->rssi_dbm !== null) ? $s->rssi_dbm : '',
                        $s->telemetry_acked ? 'TRUE' : 'FALSE',
                        $s->telemetry_received ? 'TRUE' : 'FALSE',
                        $s->sent_total,
                        $s->acked_total,
                        $s->failed_total,
                        $s->retries_total,
                        sprintf('%.3f', (float) $s->pdr),
                        $sequenceGap,
                        $s->test_complete ? 'TRUE' : 'FALSE',
                        $summary['state'],
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                    ]);
                }
            });

        $rssi = $summary['rssi'];
        $batteryCurrent = $summary['battery_current'];
        $sent = (int) $summary['sent_total'];
        $acked = (int) $summary['acked_total'];
        $failed = (int) $summary['failed_total'];
        $retries = (int) $summary['retries_total'];
        $pdr3 = $sent > 0 ? sprintf('%.3f', round(100.0 * $acked / $sent, 3)) : '';

        $samplesVisible = (int) NodeLinkSample::where('room_id', $roomId)->where('session_id', $sessionId)->count();
        $unobservedRows = max(0, $sent - $samplesVisible);
        $dataCompletenessPercent = $sent > 0 ? ($samplesVisible / $sent * 100.0) : null;

        fputcsv($file, [
            'SUMMARY',
            $roomId,
            $sessionId,
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            $batteryCurrent && $batteryCurrent['valid'] ? 'TRUE' : 'FALSE',
            $batteryCurrent && $batteryCurrent['millivolts'] !== null ? $batteryCurrent['millivolts'] : '',
            $batteryCurrent && $batteryCurrent['percent'] !== null ? $batteryCurrent['percent'] : '',
            '',
            '',
            '',
            $sent,
            $acked,
            $failed,
            $retries,
            $pdr3,
            '',
            $summary['test_complete'] ? 'TRUE' : 'FALSE',
            $summary['state'],
            $rssi['count'],
            $rssi['average_dbm'] !== null ? sprintf('%.2f', $rssi['average_dbm']) : '',
            $rssi['minimum_dbm'] !== null ? $rssi['minimum_dbm'] : '',
            $rssi['maximum_dbm'] !== null ? $rssi['maximum_dbm'] : '',
            $rssi['latest_dbm'] !== null ? $rssi['latest_dbm'] : '',
            $samplesVisible,
            $unobservedRows,
            $dataCompletenessPercent !== null ? sprintf('%.3f', $dataCompletenessPercent) : '',
        ]);

        fclose($file);

        return $filePath;
    }
}
