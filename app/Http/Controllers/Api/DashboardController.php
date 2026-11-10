<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcLog;
use App\Models\ControlLog;
use App\Models\OccupancyLog;
use App\Models\Room;
use App\Models\SensorLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * Overview — all rooms with their latest status.
     */
    public function overview(): JsonResponse
    {
        $rooms = Room::with('devices')->get()->map(function (Room $room) {
            $data = $room->toArray();

            $data['latest_sensor'] = SensorLog::where('room_id', $room->id)
                ->latest('created_at')->first();

            $data['latest_occupancy'] = OccupancyLog::where('room_id', $room->id)
                ->latest('created_at')->first();

            $data['latest_ac_status'] = AcLog::where('room_id', $room->id)
                ->latest('created_at')->first();

            return $data;
        });

        return response()->json(['data' => $rooms]);
    }

    /**
     * Room stats — averages and counts for a specific room.
     */
    public function roomStats(int $roomId): JsonResponse
    {
        Room::findOrFail($roomId);

        $oneHourAgo = Carbon::now()->subHour();
        $today = Carbon::today();

        // Average temperature & humidity in the last hour
        $sensorAvg = SensorLog::where('room_id', $roomId)
            ->where('created_at', '>=', $oneHourAgo)
            ->selectRaw('AVG(temperature) as avg_temp, AVG(humidity) as avg_rh, COUNT(*) as count')
            ->first();

        // Average occupancy in the last hour
        $occupancyAvg = OccupancyLog::where('room_id', $roomId)
            ->where('created_at', '>=', $oneHourAgo)
            ->selectRaw('AVG(people_count) as avg_people, MAX(people_count) as max_people')
            ->first();

        // Current AC status
        $currentAc = AcLog::where('room_id', $roomId)
            ->latest('created_at')
            ->first();

        // Total control actions today
        $controlActionsToday = ControlLog::where('room_id', $roomId)
            ->where('created_at', '>=', $today)
            ->count();

        return response()->json([
            'data' => [
                'sensor_avg_1h' => [
                    'temperature' => round($sensorAvg->avg_temp ?? 0, 1),
                    'humidity'    => round($sensorAvg->avg_rh ?? 0, 1),
                    'data_points' => $sensorAvg->count ?? 0,
                ],
                'occupancy_1h' => [
                    'average' => round($occupancyAvg->avg_people ?? 0, 1),
                    'max'     => $occupancyAvg->max_people ?? 0,
                ],
                'current_ac'          => $currentAc,
                'control_actions_today' => $controlActionsToday,
            ],
        ]);
    }

    /**
     * Chart data — time-series data for frontend graphs.
     *
     * Query params:
     *   - type: temperature | humidity | occupancy | setpoint
     *   - period: 1h | 6h | 24h | 7d | 30d
     */
    public function chartData(Request $request, int $roomId): JsonResponse
    {
        Room::findOrFail($roomId);

        $type = $request->input('type', 'temperature');
        $period = $request->input('period', '24h');

        $from = match ($period) {
            '1h'  => Carbon::now()->subHour(),
            '6h'  => Carbon::now()->subHours(6),
            '24h' => Carbon::now()->subDay(),
            '7d'  => Carbon::now()->subDays(7),
            '30d' => Carbon::now()->subDays(30),
            default => Carbon::now()->subDay(),
        };

        $data = match ($type) {
            'temperature' => SensorLog::where('room_id', $roomId)
                ->where('created_at', '>=', $from)
                ->orderBy('created_at')
                ->get(['created_at as timestamp', 'temperature as value']),

            'humidity' => SensorLog::where('room_id', $roomId)
                ->where('created_at', '>=', $from)
                ->orderBy('created_at')
                ->get(['created_at as timestamp', 'humidity as value']),

            'occupancy' => OccupancyLog::where('room_id', $roomId)
                ->where('created_at', '>=', $from)
                ->orderBy('created_at')
                ->get(['created_at as timestamp', 'people_count as value']),

            'setpoint' => AcLog::where('room_id', $roomId)
                ->where('created_at', '>=', $from)
                ->orderBy('created_at')
                ->get(['created_at as timestamp', 'setpoint as value']),

            default => collect(),
        };

        return response()->json([
            'data' => $data,
            'meta' => [
                'type'    => $type,
                'period'  => $period,
                'room_id' => $roomId,
                'from'    => $from->toIso8601String(),
                'to'      => Carbon::now()->toIso8601String(),
                'count'   => $data->count(),
            ],
        ]);
    }
}
