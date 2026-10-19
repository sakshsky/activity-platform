<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class MonitoringPolicyFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => 1, 'notice' => 'Synthetic monitoring notice for automated tests only.'];
    }
}
