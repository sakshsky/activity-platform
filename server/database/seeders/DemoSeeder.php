<?php

namespace Database\Seeders;

use App\Jobs\GenerateThumbnail;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\MonitoringPolicy;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\Vault;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || config('tracker.storage') !== 'local') {
            throw new \RuntimeException('Demo data is allowed only in local/testing with local storage.');
        }
        MonitoringPolicy::current();
        foreach (['admin' => 'super_admin', 'reviewer' => 'reviewer', 'employee' => 'employee'] as $name => $role) {
            User::firstOrCreate(['email' => $name.'@example.test'], ['name' => ucfirst($name).' Demo', 'password' => 'LocalDemo!2026', 'role' => $role]);
        }
        $employee = User::where('email', 'employee@example.test')->firstOrFail();
        $device = Device::firstOrCreate(['device_identifier' => '11111111-1111-4111-8111-111111111111'], ['user_id' => $employee->id, 'name' => 'Demo workstation', 'os' => 'Windows', 'paused' => true, 'notice_version' => 1, 'notice_accepted_at' => now()]);
        if (Screenshot::where('device_id', $device->id)->exists()) {
            return;
        }
        $vault = app(Vault::class);
        foreach (['Code editor', 'Browser', 'Spreadsheet'] as $index => $application) {
            $image = imagecreatetruecolor(1000, 625);
            imagefill($image, 0, 0, imagecolorallocate($image, 235, 243, 241));
            $ink = imagecolorallocate($image, 28, 57, 67);
            imagestring($image, 5, 50, 55, 'SYNTHETIC DEMO - '.$application, $ink);
            imagestring($image, 4, 50, 100, 'This image contains no captured desktop data.', $ink);
            for ($row = 0; $row < 6; $row++) {
                imagefilledrectangle($image, 50, 160 + $row * 55, 900 - $row * 35, 185 + $row * 55, imagecolorallocate($image, 175 + $row * 8, 201 + $row * 5, 193 + $row * 5));
            }
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
            $event = (string) Str::uuid();
            $path = 'screenshots/'.$device->id.'/'.$event.'.png';
            $vault->put($path, $bytes);
            $shot = Screenshot::create(['device_id' => $device->id, 'event_id' => $event, 'captured_at' => now()->subMinutes($index * 3), 'path' => $path, 'storage' => 'local', 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'app_name' => $application, 'window_title' => 'Synthetic demonstration']);
            GenerateThumbnail::dispatchSync($shot->id);
            ActivityLog::create(['device_id' => $device->id, 'event_id' => (string) Str::uuid(), 'captured_at' => $shot->captured_at, 'app_name' => $application, 'window_title' => 'Synthetic demonstration', 'created_at' => now()]);
        }
    }
}
