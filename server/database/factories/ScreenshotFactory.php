<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ScreenshotFactory extends Factory
{
    public function definition(): array
    {
        $id = (string) Str::uuid();

        return ['device_id' => Device::factory(), 'event_id' => $id, 'captured_at' => now(), 'path' => 'screenshots/'.$id.'.png', 'storage' => 'local', 'sha256' => hash('sha256', $id), 'bytes' => 100, 'app_name' => 'test.exe', 'window_title' => 'Synthetic test window'];
    }
}
