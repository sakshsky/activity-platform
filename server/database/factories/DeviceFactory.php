<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return ['device_identifier' => (string) Str::uuid(), 'user_id' => User::factory(), 'name' => fake()->word().' workstation', 'os' => 'windows', 'last_seen_at' => now()];
    }
}
