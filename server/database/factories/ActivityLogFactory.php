<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ActivityLogFactory extends Factory
{
    public function definition(): array
    {
        return ['device_id' => Device::factory(), 'event_id' => (string) Str::uuid(), 'captured_at' => now(), 'app_name' => 'test.exe', 'window_title' => 'Synthetic test window', 'created_at' => now()];
    }
}
