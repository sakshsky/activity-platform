<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/admin')->group(function (): void {
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:6,1');
    Route::middleware(['auth:web', 'admin.access'])->group(function (): void {
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
