<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tracker:prune')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();

Schedule::command('tracker:weekly-summary')->weeklyOn(1, '07:00')->timezone('UTC')->withoutOverlapping();
