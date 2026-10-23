<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AgentDownloadController;
use App\Http\Controllers\CaptureDeletionController;
use App\Http\Controllers\EmployeeDataController;
use App\Http\Controllers\EmployeePortalController;
use App\Http\Controllers\OptionalModulesController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\SystemCheckController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/admin')->group(function (): void {
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:6,1');
    Route::middleware(['auth:web', 'admin.access'])->group(function (): void {
        Route::get('videos', [OptionalModulesController::class, 'videos']);
        Route::get('videos/{id}', [OptionalModulesController::class, 'video'])->whereUuid('id')->name('video.file');
        Route::get('trust-guide', fn () => response()->download(resource_path('docs/COMPLIANCE.md'), 'Activity-Trust-Guide.md', ['Cache-Control' => 'private, no-store']));
        Route::get('agent-release', [AgentDownloadController::class, 'info']);
        Route::get('agent-download/{kind}', [AgentDownloadController::class, 'download'])->whereIn('kind', ['exe', 'zip'])->name('agent.download');
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::get('overview', [AdminController::class, 'overview']);
        Route::get('options', [AdminController::class, 'options']);
        Route::get('devices', [AdminController::class, 'devices']);
        Route::get('screenshots', [AdminController::class, 'screenshots']);
        Route::get('screenshots/{screenshot}/file', [AdminController::class, 'image'])->name('screenshot.file');
        Route::get('screenshots/{screenshot}/thumbnail', [AdminController::class, 'image'])->name('screenshot.thumbnail');
        Route::get('activity/export', [AdminController::class, 'export']);
        Route::get('activity', [AdminController::class, 'activity']);
        Route::middleware('admin.access:super_admin')->group(function (): void {
            Route::post('system-check', [SystemCheckController::class, '__invoke'])->middleware('throttle:6,1,admin-system-check:');
            Route::put('weekly-summary-preference', [AdminAuthController::class, 'weeklySummaryPreference'])->middleware('throttle:20,1');
            Route::get('deletions', [CaptureDeletionController::class, 'index']);
            Route::get('anomalies', [OptionalModulesController::class, 'anomalies']);
            Route::post('anomalies/{id}/review', [OptionalModulesController::class, 'annotate'])->whereUuid('id')->middleware('throttle:20,1');
            Route::get('performance', [PerformanceController::class, 'adminReport']);
            Route::get('performance/rankings', [PerformanceController::class, 'rankings']);
            Route::post('performance/tasks', [PerformanceController::class, 'createTask'])->middleware('throttle:30,1');
            Route::post('performance/tasks/{id}/review', [PerformanceController::class, 'reviewTask'])->whereUuid('id')->middleware('throttle:30,1');
            Route::post('performance/publish', [PerformanceController::class, 'publish'])->middleware('throttle:10,1');
            Route::post('performance/domain-rule', [PerformanceController::class, 'domainRule'])->middleware('throttle:30,1');
            Route::post('performance/apply-preset', [PerformanceController::class, 'applyPreset'])->middleware('throttle:10,1');
            Route::post('performance/app-defaults', [PerformanceController::class, 'appDefaults'])->middleware('throttle:10,1');
            Route::post('performance/app-rule', [PerformanceController::class, 'appRule'])->middleware('throttle:30,1');
            Route::get('personnel', [EmployeeDataController::class, 'people']);
            Route::get('personnel/{user}', [EmployeeDataController::class, 'person']);
            Route::put('personnel/{user}', [EmployeeDataController::class, 'employment']);
            Route::get('leaves', [EmployeePortalController::class, 'leaves']);
            Route::patch('leaves/{id}', [EmployeePortalController::class, 'review'])->whereUuid('id');
            Route::get('users', [AdminController::class, 'users']);
            Route::post('users', [AdminController::class, 'createUser']);
            Route::patch('users/{user}', [AdminController::class, 'updateUser']);
            Route::patch('devices/{device}', [AdminController::class, 'updateDevice']);
            Route::get('policy', [AdminController::class, 'policy']);
            Route::put('policy', [AdminController::class, 'savePolicy']);
            Route::post('storage-check', [AdminController::class, 'storageCheck'])->middleware('throttle:10,1');
            Route::get('audit', [AdminController::class, 'audit']);
        });
    });
});
Route::get('/', function () {
    abort_unless(is_file(public_path('ui/index.html')), 503, 'Build the Vue frontend with node scripts/build-web.mjs from the project root.');

    return response()->file(public_path('ui/index.html'), ['Cache-Control' => 'no-store']);
})->name('login');
