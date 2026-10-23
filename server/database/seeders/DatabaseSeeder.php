<?php

namespace Database\Seeders;

use App\Models\MonitoringPolicy;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        MonitoringPolicy::current();
        $this->call(AppRuleSeeder::class);
    }
}
