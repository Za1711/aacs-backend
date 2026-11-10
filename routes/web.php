<?php

use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ExpoDashboardController;
use Illuminate\Support\Facades\Route;

// CSV Data Export Hub
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/dashboard', [DashboardController::class, 'index']);

// Expo Live Demonstration Hub
Route::get('/expo', [ExpoDashboardController::class, 'index'])->name('expo');
Route::get('/expo/rooms/{roomId}/live-data', [ExpoDashboardController::class, 'liveData'])->name('expo.live-data');
Route::post('/expo/rooms/{roomId}/capture-detect', [ExpoDashboardController::class, 'captureAndDetect'])->name('expo.capture-detect');
