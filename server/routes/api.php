<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\CaptureDeletionController;
use App\Http\Controllers\EmployeeDataController;
use App\Http\Controllers\EmployeePortalController;
use App\Http\Controllers\OptionalModulesController;
use App\Http\Controllers\PerformanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('agent')->group(function (): void {
    Route::get('info', [AgentController::class, 'info'])->middleware('throttle:60,1,agent-get-info:');
    Route::post('login', [AgentController::class, 'login'])->middleware('throttle:6,1,agent-post-login:');
    Route::middleware(['auth:sanctum', 'device.access'])->group(function (): void {
        Route::post('modules/consent', [OptionalModulesController::class, 'consent'])->middleware('throttle:10,1,agent-post-modules-consent:');
        Route::post('videos', [OptionalModulesController::class, 'upload'])->middleware('throttle:30,1,agent-post-videos:');
        Route::get('videos', [OptionalModulesController::class, 'videos']);
        Route::get('videos/{id}', [OptionalModulesController::class, 'video'])->whereUuid('id');
        Route::post('anomalies', [OptionalModulesController::class, 'anomaly'])->middleware('throttle:10,1,agent-post-anomalies:');
        Route::get('anomalies', [OptionalModulesController::class, 'anomalies']);
        Route::post('anomalies/{id}/context', [OptionalModulesController::class, 'annotate'])->whereUuid('id')->middleware('throttle:20,1,agent-post-anomalies-id-context:');
        Route::get('performance', [PerformanceController::class, 'employeeReport'])->middleware('throttle:60,1,agent-get-performance:');
        Route::post('tasks/{id}/submit', [PerformanceController::class, 'submitTask'])->whereUuid('id')->middleware('throttle:30,1,agent-post-tasks-id-submit:');
        Route::post('work-context', [PerformanceController::class, 'explain'])->middleware('throttle:20,1,agent-post-work-context:');
        Route::post('my-screenshots/{id}/delete', [CaptureDeletionController::class, 'destroy'])->whereUuid('id')->middleware('throttle:30,1,agent-post-my-screenshots-id-delete:');
        Route::get('my-data', [EmployeeDataController::class, 'records'])->middleware('throttle:120,1,agent-get-my-data:');
        Route::get('my-screenshots/{id}', [EmployeeDataController::class, 'image'])->whereUuid('id')->middleware('throttle:240,1,agent-get-my-screenshots-id-:');
        Route::get('portal', [EmployeePortalController::class, 'show'])->middleware('throttle:60,1,agent-get-portal:');
        Route::post('profile', [EmployeePortalController::class, 'profile'])->middleware('throttle:20,1,agent-post-profile:');
        Route::post('leave', [EmployeePortalController::class, 'requestLeave'])->middleware('throttle:20,1,agent-post-leave:');
        Route::post('leave/{id}/cancel', [EmployeePortalController::class, 'cancelLeave'])->whereUuid('id');
        Route::post('heartbeat', [AgentController::class, 'heartbeat'])->middleware('throttle:60,1,agent-post-heartbeat:');
        Route::post('logout', [AgentController::class, 'logout']);
        Route::post('screenshots', [AgentController::class, 'screenshot'])->middleware('throttle:120,1,agent-post-screenshots:');
        Route::post('activity', [AgentController::class, 'activity'])->middleware('throttle:120,1,agent-post-activity:');
    });
});
