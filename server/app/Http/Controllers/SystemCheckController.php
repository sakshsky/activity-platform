<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class SystemCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $checks = [];
        $add = function (string $name, string $status, string $detail) use (&$checks): void {
            $checks[] = compact('name', 'status', 'detail');
        };
        $add('PHP runtime', version_compare(PHP_VERSION, '8.4.1', '>=') ? 'pass' : 'fail', 'Installed '.PHP_VERSION.'. Requires PHP 8.4.1 or newer.');
        foreach (['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'hash', 'mbstring', 'openssl', 'pdo', 'session', 'tokenizer', 'xml'] as $extension) {
            $add('PHP '.$extension, extension_loaded($extension) ? 'pass' : 'fail', 'Required server extension. Enable it in the web server PHP configuration if missing.');
        }
        try {
            DB::select('SELECT 1');
            $driver = DB::connection()->getDriverName();
            $add('Database connection', 'pass', 'A query succeeded using '.$driver.'. MySQL is recommended for production; SQLite supports local demos.');
        } catch (\Throwable) {
            $add('Database connection', 'fail', 'Cannot query the database. Check server database settings and availability.');
        }
        $key = 'system-check:'.Str::uuid();
        try {
            Cache::put($key, 'ok', 30);
            $add('Cache read/write', Cache::get($key) === 'ok' ? 'pass' : 'fail', 'Tests a temporary cache entry used by server services and rate limiting.');
        } catch (\Throwable) {
            $add('Cache read/write', 'fail', 'Check cache connection and permissions.');
        } finally {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
            }
        }
        $file = storage_path('app/private/system-check-'.Str::uuid().'.tmp');
        try {
            $written = @file_put_contents($file, 'ok');
            $add('Private storage read/write', $written === 2 && @file_get_contents($file) === 'ok' ? 'pass' : 'fail', 'Tests a temporary file in private local storage. Check directory permissions if this fails.');
        } finally {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $free = @disk_free_space(storage_path());
        $add('Storage free space', $free === false ? 'manual' : ($free >= 1073741824 ? 'pass' : 'warning'), $free === false ? 'Free-space information unavailable.' : round($free / 1073741824, 1).' GB available. Keep at least 1 GB free and allow additional capacity for retention.');
        try {
            $probe = new Process([config('tracker.ffprobe_binary', 'ffprobe'), '-version'], null, null, null, 5);
            $probe->run();
            $ready = $probe->isSuccessful() && str_contains(strtolower($probe->getOutput()), 'ffprobe version');
        } catch (\Throwable) {
            $ready = false;
        }
        $add('FFprobe for optional videos', $ready ? 'pass' : 'warning', $ready ? 'Video inspection tool runs successfully. This does not test an upload.' : 'Install FFprobe on the server and configure TRACKER_FFPROBE_BINARY. Required for video uploads.');
        $add('Built web interface', is_file(public_path('ui/index.html')) ? 'pass' : 'fail', 'Build Vue assets using node scripts/build-web.mjs before deployment.');
        $add('HTTPS', $request->isSecure() ? 'pass' : 'warning', 'Use trusted HTTPS when employees connect over a network. Loopback HTTP is for local development.');
        $add('Queue worker and scheduler', 'manual', 'Confirm a queue worker and the Laravel scheduler are running. Configuration alone cannot prove worker health.');
        $add('External storage and mail', 'manual', 'Use Policy → Test storage for the configured storage destination. Verify email delivery separately; no email or remote file is sent by this check.');
        $add('Build tools', 'manual', 'For source builds: Composer 2, Node 22.12+ and Go 1.22+. These are not required on employee PCs using the compiled agent.');

        return response()->json(['checked_at' => now()->toIso8601String(), 'checks' => $checks])->header('Cache-Control', 'no-store');
    }
}
