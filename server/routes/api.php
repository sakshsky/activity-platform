<?php

use App\Http\Controllers\AgentController;
use Illuminate\Support\Facades\Route;

Route::prefix('agent')->group(function (): void {
    Route::get('info', [AgentController::class, 'info'])->middleware('throttle:60,1');
    Route::post('login', [AgentController::class, 'login'])->middleware('throttle:6,1');
    Route::middleware(['auth:sanctum', 'device.access'])->group(function (): void {
        Route::post('heartbeat', [AgentController::class, 'heartbeat'])->middleware('throttle:60,1');
        Route::post('logout', [AgentController::class, 'logout']);
        Route::post('screenshots', [AgentController::class, 'screenshot'])->middleware('throttle:120,1');
        Route::post('activity', [AgentController::class, 'activity'])->middleware('throttle:120,1');
    });
});
