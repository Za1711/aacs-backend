<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NodeLinkSample;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NodeLinkMetricsController extends Controller
{
    /**
     * Get the latest session for a room based on the most recent sample.
     * GET /api/v1/rooms/{roomId}/node-link-sessions/current
     */
    public function currentSession(Request $request, int $roomId): JsonResponse
    {
        $room = $this->resolveRoom($roomId);

        $latestSample = NodeLinkSample::where('room_id', $roomId)
            ->orderBy('received_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if (!$latestSample) {
            return response()->json([
                'status' => 'success',
                'data'   => null,
            ]);
        }

        $sessionId = (int) $latestSample->session_id;
        $summary = $this->computeSessionSummary($roomId, $sessionId);

        return response()->json([
            'status' => 'success',
            'data'   => $summary,
        ]);
    }

    /**
     * List all sessions for a room with cumulative summaries (including ACTIVE).
     * GET /api/v1/rooms/{roomId}/node-link-sessions
     */
    public function indexSessions(Request $request, int $roomId): JsonResponse
    {
        $room = $this->resolveRoom($roomId);

        $sessionIds = NodeLinkSample::where('room_id', $roomId)
            ->distinct()
            ->orderBy('session_id', 'desc')
            ->pluck('session_id');

        $sessions = [];
        foreach ($sessionIds as $sessionId) {
            $sessions[] = $this->computeSessionSummary($roomId, (int) $sessionId);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $sessions,
        ]);
    }

    /**
     * Detail of a single session including battery tier aggregation.
     * GET /api/v1/rooms/{roomId}/node-link-sessions/{sessionId}
     */
    public function showSession(Request $request, int $roomId, int $sessionId): JsonResponse
    {
        $room = $this->resolveRoom($roomId);

        $exists = NodeLinkSample::where('room_id', $roomId)
            ->where('session_id', $sessionId)
            ->exists();

        if (!$exists) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Sesi metrik link tidak ditemukan.',
            ], 404);
        }

        $summary = $this->computeSessionSummary($roomId, $sessionId);
        $batteryAggregation = $this->computeBatteryAggregation($roomId, $sessionId);

        return response()->json([
            'status' => 'success',
            'data'   => array_merge($summary, [
                'battery_aggregation' => $batteryAggregation,
            ]),
        ]);
    }

    /**
     * Paginated list of raw samples for a session.
     * GET /api/v1/rooms/{roomId}/node-link-sessions/{sessionId}/samples?page=1&per_page=100
     */
    public function samples(Request $request, int $roomId, int $sessionId): JsonResponse
    {
        $room = $this->resolveRoom($roomId);

        $exists = NodeLinkSample::where('room_id', $roomId)
            ->where('session_id', $sessionId)
            ->exists();

        if (!$exists) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Sesi metrik link tidak ditemukan.',
            ], 404);
        }

        $perPage = min(500, max(1, (int) $request->query('per_page', 100)));

        $paginated = NodeLinkSample::where('room_id', $roomId)
            ->where('session_id', $sessionId)
            ->orderBy('telemetry_seq', 'asc')
            ->paginate($perPage);

        $items = $paginated->getCollection()->map(function ($s) {
            return [
                'telemetry_seq'        => (int) $s->telemetry_seq,
                'telemetry_packet_seq' => (int) $s->telemetry_packet_seq,
                'delivery_success'     => (bool) $s->telemetry_acked,
                'telemetry_received'   => (bool) $s->telemetry_received,
                'sensor_valid'         => (bool) $s->sensor_valid,
                'temperature_c'        => ($s->sensor_valid && $s->temperature_c !== null) ? (float) $s->temperature_c : null,
                'humidity_percent'     => ($s->sensor_valid && $s->humidity_percent !== null) ? (float) $s->humidity_percent : null,
                'rssi_dbm'             => ($s->telemetry_received && $s->rssi_dbm !== null) ? (int) $s->rssi_dbm : null,
                'sent_total'           => (int) $s->sent_total,
                'acked_total'          => (int) $s->acked_total,
                'failed_total'         => (int) $s->failed_total,
                'retries_total'        => (int) $s->retries_total,
                'pdr_percent'          => (float) $s->pdr,
                'battery_valid'        => (bool) $s->battery_valid,
                'battery_mv'           => ($s->battery_valid && $s->battery_mv !== null) ? (int) $s->battery_mv : null,
                'battery_percent'      => ($s->battery_valid && $s->battery_percent !== null) ? (int) $s->battery_percent : null,
                'test_complete'        => (bool) $s->test_complete,
                'received_at'          => Carbon::parse($s->received_at)->utc()->toIso8601ZuluString(),
            ];
        });

        return response()->json([
            'status'     => 'success',
            'data'       => $items,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * Export all raw samples in a session as flat CSV with SAMPLE and SUMMARY rows.
     * GET /api/v1/rooms/{roomId}/node-link-sessions/{sessionId}/export.csv
     */
    public function exportCsv(Request $request, int $roomId, int $sessionId): StreamedResponse
    {
        $room = $this->resolveRoom($roomId);

        $exists = NodeLinkSample::where('room_id', $roomId)
            ->where('session_id', $sessionId)
            ->exists();

        if (!$exists) {
            abort(404, 'Sesi metrik link tidak ditemukan.');
        }

        $summary = $this->computeSessionSummary($roomId, $sessionId);

        $dateSuffix = date('Y-m-d');
        $fileName = "node_link_metrics_room_{$roomId}_session_{$sessionId}_{$dateSuffix}.csv";

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () use ($roomId, $sessionId, $summary) {
            $file = fopen('php://output', 'w');
            // BOM UTF-8 for Excel
            fputs($file, "\xEF\xBB\xBF");

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

            // Write exactly one SUMMARY row at the end
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
                '', // Telemetry_Seq
                '', // Telemetry_Packet_Seq
                '', // Timestamp_UTC
                '', // Timestamp_WIB
                '', // Sensor_Valid
                '', // Temperature_C
                '', // Humidity_Percent
                $batteryCurrent && $batteryCurrent['valid'] ? 'TRUE' : 'FALSE',
                $batteryCurrent && $batteryCurrent['millivolts'] !== null ? $batteryCurrent['millivolts'] : '',
                $batteryCurrent && $batteryCurrent['percent'] !== null ? $batteryCurrent['percent'] : '',
                '', // RSSI_dBm
                '', // Delivery_Success
                '', // Telemetry_Received
                $sent,
                $acked,
                $failed,
                $retries,
                $pdr3,
                '', // Sequence_Gap
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
        }, 200, $headers);
    }

    /**
     * Compute cumulative summary for a session.
     */
    public function computeSessionSummary(int $roomId, int $sessionId): array
    {
        $samplesQuery = NodeLinkSample::where('room_id', $roomId)->where('session_id', $sessionId);

        $samplesCount = (int) (clone $samplesQuery)->count();
        $firstSample = (clone $samplesQuery)->orderBy('telemetry_seq', 'asc')->first();
        $lastSample  = (clone $samplesQuery)->orderBy('telemetry_seq', 'desc')->first();

        $sent    = $lastSample ? (int) $lastSample->sent_total : 0;
        $acked   = $lastSample ? (int) $lastSample->acked_total : 0;
        $failed  = $lastSample ? (int) $lastSample->failed_total : 0;
        $retries = $lastSample ? (int) $lastSample->retries_total : 0;
        $pdrPercent = $sent > 0 ? round(100.0 * ($acked / $sent), 2) : null;

        // Row counts in database
        $successCountVisible = (clone $samplesQuery)->where('telemetry_acked', true)->count();
        $failureCountVisible = (clone $samplesQuery)->where('telemetry_acked', false)->count();

        // Consecutive failures starting from the latest sample backward
        $consecutiveFailures = 0;
        if ($lastSample) {
            $descSamples = (clone $samplesQuery)->orderBy('telemetry_seq', 'desc')->select('telemetry_acked')->get();
            foreach ($descSamples as $s) {
                if (!$s->telemetry_acked) {
                    $consecutiveFailures++;
                } else {
                    break;
                }
            }
        }

        // RSSI statistics from telemetry_received = true and rssi_dbm IS NOT NULL
        $rssiQuery = (clone $samplesQuery)
            ->where('telemetry_received', true)
            ->whereNotNull('rssi_dbm');

        $rssiCount = $rssiQuery->count();
        if ($rssiCount > 0) {
            $rssiStats = (clone $rssiQuery)->selectRaw('AVG(rssi_dbm) as avg_rssi, MIN(rssi_dbm) as min_rssi, MAX(rssi_dbm) as max_rssi')->first();
            $latestRssiSample = (clone $rssiQuery)->orderBy('telemetry_seq', 'desc')->first();

            $rssiAvg = round((float) $rssiStats->avg_rssi, 2);
            $rssiMin = (int) $rssiStats->min_rssi;
            $rssiMax = (int) $rssiStats->max_rssi;
            $rssiLatest = (int) $latestRssiSample->rssi_dbm;
        } else {
            $rssiAvg = null;
            $rssiMin = null;
            $rssiMax = null;
            $rssiLatest = null;
        }

        $rssi = [
            'count'        => $rssiCount,
            'average_dbm'  => $rssiAvg,
            'minimum_dbm'  => $rssiMin,
            'maximum_dbm'  => $rssiMax,
            'latest_dbm'   => $rssiLatest,
            'avg'          => $rssiAvg,
            'min'          => $rssiMin,
            'max'          => $rssiMax,
        ];

        // Battery start (first valid battery sample)
        $firstValidBattery = (clone $samplesQuery)
            ->where('battery_valid', true)
            ->whereNotNull('battery_percent')
            ->orderBy('telemetry_seq', 'asc')
            ->first();

        $batteryStart = $firstValidBattery ? [
            'millivolts' => $firstValidBattery->battery_mv !== null ? (int) $firstValidBattery->battery_mv : null,
            'percent'    => (int) $firstValidBattery->battery_percent,
        ] : null;

        // Battery current (from latest sample)
        $batteryCurrent = [
            'valid'      => $lastSample ? (bool) $lastSample->battery_valid : false,
            'millivolts' => ($lastSample && $lastSample->battery_valid && $lastSample->battery_mv !== null) ? (int) $lastSample->battery_mv : null,
            'percent'    => ($lastSample && $lastSample->battery_valid && $lastSample->battery_percent !== null) ? (int) $lastSample->battery_percent : null,
        ];

        // Battery end (last valid battery sample for showSession compatibility)
        $lastValidBattery = (clone $samplesQuery)
            ->where('battery_valid', true)
            ->whereNotNull('battery_percent')
            ->orderBy('telemetry_seq', 'desc')
            ->first();

        $batteryEnd = $lastValidBattery ? [
            'percent' => (int) $lastValidBattery->battery_percent,
            'mv'      => $lastValidBattery->battery_mv !== null ? (int) $lastValidBattery->battery_mv : null,
        ] : null;

        $hasTestComplete = (clone $samplesQuery)->where('test_complete', true)->exists();

        // State determination
        $now = Carbon::now('UTC');
        $lastSampleAt = $lastSample?->received_at ? Carbon::parse($lastSample->received_at)->utc() : null;
        $firstSampleAt = $firstSample?->received_at ? Carbon::parse($firstSample->received_at)->utc() : null;

        if ($hasTestComplete) {
            $state = 'COMPLETED';
        } elseif ($lastSampleAt !== null) {
            $ageSeconds = max(0, $now->getTimestamp() - $lastSampleAt->getTimestamp());
            $timeout = (int) config('aacs.node_link_active_timeout_seconds', 30);
            $state = ($ageSeconds <= $timeout) ? 'ACTIVE' : 'STALE';
        } else {
            $ageSeconds = null;
            $state = 'NO_DATA';
        }
        $ageSeconds = $lastSampleAt ? max(0, $now->getTimestamp() - $lastSampleAt->getTimestamp()) : null;

        return [
            'room_id'               => $roomId,
            'session_id'            => $sessionId,
            'state'                 => $state,
            'first_sample_at'       => $firstSampleAt?->toIso8601ZuluString(),
            'last_sample_at'        => $lastSampleAt?->toIso8601ZuluString(),
            'age_seconds'           => $ageSeconds,
            'samples_visible'       => $samplesCount,
            'samples_count'         => $samplesCount,
            'sent_total'            => $sent,
            'acked_total'           => $acked,
            'failed_total'          => $failed,
            'retries_total'         => $retries,
            'pdr'                   => $pdrPercent,
            'pdr_percent'           => $pdrPercent,
            'last_delivery_success' => $lastSample ? (bool) $lastSample->telemetry_acked : null,
            'success_count_visible' => $successCountVisible,
            'failure_count_visible' => $failureCountVisible,
            'consecutive_failures'  => $consecutiveFailures,
            'rssi'                  => $rssi,
            'battery_start'         => $batteryStart,
            'battery_current'       => $batteryCurrent,
            'battery_end'           => $batteryEnd,
            'test_complete'         => (bool) $hasTestComplete,
        ];
    }

    /**
     * Compute tier aggregation for the 8 battery percent ranges using delta counters.
     */
    protected function computeBatteryAggregation(int $roomId, int $sessionId): array
    {
        $tierDefs = [
            '100-91%' => ['min' => 91, 'max' => 100],
            '90-81%'  => ['min' => 81, 'max' => 90],
            '80-71%'  => ['min' => 71, 'max' => 80],
            '70-61%'  => ['min' => 61, 'max' => 70],
            '60-51%'  => ['min' => 51, 'max' => 60],
            '50-41%'  => ['min' => 41, 'max' => 50],
            '40-31%'  => ['min' => 31, 'max' => 40],
            '30-20%'  => ['min' => 20, 'max' => 30],
        ];

        $tiers = [];
        foreach ($tierDefs as $key => $range) {
            $tiers[$key] = [
                'range'         => $key,
                'samples_count' => 0,
                'sent_delta'    => 0,
                'acked_delta'   => 0,
                'failed_delta'  => 0,
                'pdr'           => null,
                'rssi'          => [
                    'avg' => null,
                    'min' => null,
                    'max' => null,
                ],
                'voltage_mv'    => [
                    'avg' => null,
                    'min' => null,
                    'max' => null,
                ],
                '_rssi_list'    => [],
                '_mv_list'      => [],
            ];
        }

        $samples = NodeLinkSample::where('room_id', $roomId)
            ->where('session_id', $sessionId)
            ->orderBy('telemetry_seq', 'asc')
            ->get();

        $prevSent = null;
        $prevAcked = null;
        $prevFailed = null;

        foreach ($samples as $sample) {
            // Find tier based on valid battery percent
            $matchedKey = null;
            if ($sample->battery_valid && $sample->battery_percent !== null) {
                $pct = (int) $sample->battery_percent;
                foreach ($tierDefs as $key => $def) {
                    if ($pct >= $def['min'] && $pct <= $def['max']) {
                        $matchedKey = $key;
                        break;
                    }
                }
            }

            // Calculate delta counters
            if ($prevSent === null) {
                $dSent   = (int) $sample->sent_total;
                $dAcked  = (int) $sample->acked_total;
                $dFailed = (int) $sample->failed_total;
            } else {
                if ($sample->sent_total < $prevSent) {
                    $dSent   = (int) $sample->sent_total;
                    $dAcked  = (int) $sample->acked_total;
                    $dFailed = (int) $sample->failed_total;
                } else {
                    $dSent   = max(0, (int) $sample->sent_total - $prevSent);
                    $dAcked  = max(0, (int) $sample->acked_total - $prevAcked);
                    $dFailed = max(0, (int) $sample->failed_total - $prevFailed);
                }
            }

            $prevSent   = (int) $sample->sent_total;
            $prevAcked  = (int) $sample->acked_total;
            $prevFailed = (int) $sample->failed_total;

            if ($matchedKey !== null) {
                $tiers[$matchedKey]['samples_count']++;
                $tiers[$matchedKey]['sent_delta']   += $dSent;
                $tiers[$matchedKey]['acked_delta']  += $dAcked;
                $tiers[$matchedKey]['failed_delta'] += $dFailed;

                if ($sample->telemetry_received && $sample->rssi_dbm !== null) {
                    $tiers[$matchedKey]['_rssi_list'][] = (int) $sample->rssi_dbm;
                }
                if ($sample->battery_valid && $sample->battery_mv !== null) {
                    $tiers[$matchedKey]['_mv_list'][] = (int) $sample->battery_mv;
                }
            }
        }

        // Finalize calculations for each tier
        $result = [];
        foreach ($tiers as $key => $data) {
            $sentDelta = $data['sent_delta'];
            $ackedDelta = $data['acked_delta'];
            $pdr = $sentDelta > 0 ? round(100.0 * ($ackedDelta / $sentDelta), 3) : null;

            $rssiList = $data['_rssi_list'];
            $rssiAvg = count($rssiList) > 0 ? round(array_sum($rssiList) / count($rssiList), 2) : null;
            $rssiMin = count($rssiList) > 0 ? min($rssiList) : null;
            $rssiMax = count($rssiList) > 0 ? max($rssiList) : null;

            $mvList = $data['_mv_list'];
            $mvAvg = count($mvList) > 0 ? round(array_sum($mvList) / count($mvList), 2) : null;
            $mvMin = count($mvList) > 0 ? min($mvList) : null;
            $mvMax = count($mvList) > 0 ? max($mvList) : null;

            $result[] = [
                'range'         => $data['range'],
                'samples_count' => $data['samples_count'],
                'sent_delta'    => $sentDelta,
                'acked_delta'   => $ackedDelta,
                'failed_delta'  => $data['failed_delta'],
                'pdr'           => $pdr,
                'rssi'          => [
                    'avg' => $rssiAvg,
                    'min' => $rssiMin,
                    'max' => $rssiMax,
                ],
                'voltage_mv'    => [
                    'avg' => $mvAvg,
                    'min' => $mvMin,
                    'max' => $mvMax,
                ],
            ];
        }

        return $result;
    }

    protected function resolveRoom(int $roomId): Room
    {
        $user = auth('sanctum')->user() ?? auth()->user();
        if ($user) {
            return Room::where('user_id', $user->id)->findOrFail($roomId);
        }
        return Room::findOrFail($roomId);
    }
}
