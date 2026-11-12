<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AcLog;
use App\Models\NodeBatteryLog;
use App\Models\NodeLinkSample;
use App\Models\OccupancyLog;
use App\Models\Room;
use App\Models\SensorLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $rooms = Room::all();
        $roomId = (int) $request->query('room_id', $rooms->first()?->id ?? 119);
        $currentRoom = Room::find($roomId) ?? $rooms->first();

        $todayWib = Carbon::today('Asia/Jakarta');

        $stats = [
            'sensor_today'    => $currentRoom ? SensorLog::where('room_id', $currentRoom->id)->where('created_at', '>=', $todayWib)->count() : 0,
            'sensor_total'    => $currentRoom ? SensorLog::where('room_id', $currentRoom->id)->count() : 0,
            'occupancy_today' => $currentRoom ? OccupancyLog::where('room_id', $currentRoom->id)->where('created_at', '>=', $todayWib)->count() : 0,
            'occupancy_total' => $currentRoom ? OccupancyLog::where('room_id', $currentRoom->id)->count() : 0,
            'ac_logs_today'   => $currentRoom ? AcLog::where('room_id', $currentRoom->id)->where('created_at', '>=', $todayWib)->count() : 0,
            'ac_logs_total'   => $currentRoom ? AcLog::where('room_id', $currentRoom->id)->count() : 0,
            'battery_logs'    => $currentRoom ? NodeBatteryLog::where('room_id', $currentRoom->id)->count() : 0,
            'sessions_count'  => $currentRoom ? NodeLinkSample::where('room_id', $currentRoom->id)->select('session_id')->distinct()->count('session_id') : 0,
        ];

        $sessions = [];
        if ($currentRoom) {
            $sessionIds = NodeLinkSample::where('room_id', $currentRoom->id)
                ->selectRaw('session_id, MAX(received_at) as last_received_at')
                ->groupBy('session_id')
                ->orderByDesc('last_received_at')
                ->orderByDesc('session_id')
                ->pluck('session_id');

            $timeout = (int) config('aacs.node_link_active_timeout_seconds', 30);
            $now = Carbon::now('UTC');

            foreach ($sessionIds as $sId) {
                $lastSample = NodeLinkSample::where('room_id', $currentRoom->id)
                    ->where('session_id', $sId)
                    ->orderByDesc('telemetry_seq')
                    ->first();

                $firstSample = NodeLinkSample::where('room_id', $currentRoom->id)
                    ->where('session_id', $sId)
                    ->orderBy('telemetry_seq')
                    ->first();

                $samplesCount = NodeLinkSample::where('room_id', $currentRoom->id)
                    ->where('session_id', $sId)
                    ->count();

                $isComplete = NodeLinkSample::where('room_id', $currentRoom->id)
                    ->where('session_id', $sId)
                    ->where('test_complete', true)
                    ->exists();

                $lastSampleAt = $lastSample?->received_at ? Carbon::parse($lastSample->received_at)->utc() : null;
                $ageSeconds = $lastSampleAt ? max(0, $now->getTimestamp() - $lastSampleAt->getTimestamp()) : 999999;

                if ($isComplete) {
                    $sessionState = 'COMPLETED';
                } elseif ($ageSeconds <= $timeout) {
                    $sessionState = 'ACTIVE';
                } else {
                    $sessionState = 'STALE';
                }

                $sent = $lastSample?->sent_total ?? 0;
                $acked = $lastSample?->acked_total ?? 0;
                $pdr = $sent > 0 ? round(100.0 * $acked / $sent, 2) : 0;

                $rssiAvg = NodeLinkSample::where('room_id', $currentRoom->id)
                    ->where('session_id', $sId)
                    ->where('telemetry_received', true)
                    ->whereNotNull('rssi_dbm')
                    ->avg('rssi_dbm');

                $sessions[] = [
                    'session_id'    => $sId,
                    'state'         => $sessionState,
                    'samples_count' => $samplesCount,
                    'sent_total'    => $sent,
                    'acked_total'   => $acked,
                    'failed_total'  => $lastSample?->failed_total ?? 0,
                    'retries_total' => $lastSample?->retries_total ?? 0,
                    'pdr'           => $pdr,
                    'rssi_avg'      => $rssiAvg !== null ? round((float) $rssiAvg, 2) : '-',
                    'battery_start' => $firstSample?->battery_percent,
                    'battery_end'   => $lastSample?->battery_percent,
                    'battery_mv_end'=> $lastSample?->battery_mv,
                    'is_complete'   => $isComplete,
                    'first_at'      => $firstSample?->received_at ? Carbon::parse($firstSample->received_at)->timezone('Asia/Jakarta')->format('d M Y H:i:s') : '-',
                    'last_at'       => $lastSample?->received_at ? Carbon::parse($lastSample->received_at)->timezone('Asia/Jakarta')->format('d M Y H:i:s') : '-',
                ];
            }
        }

        return view('dashboard', compact('rooms', 'currentRoom', 'stats', 'sessions'));
    }
}
