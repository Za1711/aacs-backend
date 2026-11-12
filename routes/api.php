<?php

use App\Http\Controllers\Api\AcCommandController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\TelemetryController;
use App\Http\Controllers\Api\NodeLinkMetricsController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\DeviceTokenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Smart AC Remote & Monitoring System
|--------------------------------------------------------------------------
|
| Prefix: /api/v1
|
*/

Route::prefix('v1')->group(function () {
    Route::middleware(['auth:sanctum', 'throttle:60,1'])->get('/iot/my-room', [\App\Http\Controllers\Api\IotDeviceController::class, 'myRoom']);

    Route::get('/rooms/active-id', function () {
        $room = \App\Models\Room::latest('id')->first();
        return response()->json([
            'room_id' => $room ? $room->id : null,
            'room_name' => $room ? $room->name : null
        ]);
    });

    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login'])->name('login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::put('/me', [AuthController::class, 'updateProfile']);
            Route::post('/change-password', [AuthController::class, 'changePassword']);
        });
    });

    Route::prefix('rooms/{roomId}')->group(function () {
        Route::get('/export-csv', [TelemetryController::class, 'exportCsv']);
        Route::get('/battery/export-csv', [TelemetryController::class, 'exportBatteryCsv']);
        Route::get('/remote-telemetry/export.csv', [TelemetryController::class, 'exportRemoteTelemetryCsv']);
        Route::get('/node-telemetry/export.csv', [TelemetryController::class, 'exportNodeTelemetryCsv']);
        Route::get('/sensor-sources/export.csv', [TelemetryController::class, 'exportSensorSourcesCsv']);
        Route::get('/node-link-sessions/{sessionId}/export.csv', [NodeLinkMetricsController::class, 'exportCsv']);
        Route::get('/node-link-samples/export.csv', [NodeLinkMetricsController::class, 'exportRawCsv']);
        Route::post('/sensors', [TelemetryController::class, 'storeSensorLog']);
        Route::post('/occupancy', [TelemetryController::class, 'storeOccupancyLog']);
        Route::post('/ac-status', [TelemetryController::class, 'storeAcLog']);
        Route::post('/control-logs', [TelemetryController::class, 'storeControlLog']);
    });

    Route::middleware('auth:sanctum')->group(function () {

        // ----- NOTIFICATIONS -----
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);

        // ----- DEVICE TOKENS (FCM) -----
        Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
        Route::delete('/device-tokens/{token}', [DeviceTokenController::class, 'destroy'])->where('token', '.*');

        // ----- ROOMS -----
        Route::post('/rooms/{room}/unpair', [RoomController::class, 'unpair']);
        Route::apiResource('rooms', RoomController::class);

        // ----- DEVICES -----
        Route::post('/devices/pair', [DeviceController::class, 'pair']);
        Route::apiResource('devices', DeviceController::class);
        Route::post('/devices/{id}/heartbeat', [DeviceController::class, 'heartbeat']);
        Route::get('/rooms/{roomId}/pairing/candidates', [DeviceController::class, 'getPairingCandidates']);

        // ----- TELEMETRY READ -----
        Route::prefix('rooms/{roomId}')->group(function () {
            Route::get('/battery-logs', [TelemetryController::class, 'batteryLogs']);
            Route::get('/sensors', [TelemetryController::class, 'sensorLogs']);
            Route::get('/occupancy', [TelemetryController::class, 'occupancyLogs']);
            Route::get('/control-logs', [TelemetryController::class, 'controlLogs']);
            Route::get('/ac-logs', [TelemetryController::class, 'acLogs']);
            Route::get('/latest-status', [TelemetryController::class, 'latestStatus']);
            Route::get('/node-link-sessions/current', [NodeLinkMetricsController::class, 'currentSession']);
            Route::get('/node-link-sessions', [NodeLinkMetricsController::class, 'indexSessions']);
            Route::get('/node-link-sessions/{sessionId}', [NodeLinkMetricsController::class, 'showSession']);
            Route::get('/node-link-sessions/{sessionId}/samples', [NodeLinkMetricsController::class, 'samples']);
        });

        // ----- AC COMMANDS (via MQTT) -----
        Route::prefix('rooms/{roomId}/ac-command')->group(function () {
            Route::post('/', [AcCommandController::class, 'sendCommand']);
            Route::post('/temperature', [AcCommandController::class, 'setTemperature']);
            Route::post('/power', [AcCommandController::class, 'setPower']);
        });

        // ----- DASHBOARD -----
        Route::prefix('dashboard')->group(function () {
            Route::get('/overview', [DashboardController::class, 'overview']);
            Route::get('/rooms/{roomId}/stats', [DashboardController::class, 'roomStats']);
            Route::get('/rooms/{roomId}/chart', [DashboardController::class, 'chartData']);
        });
    });
});
