<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdleAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_idle_lock_and_offline_do_not_count_as_active_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));
        $user = User::factory()->create(['role' => 'employee']);
        $login = $this->postJson('/api/agent/login', ['email' => $user->email, 'password' => 'password', 'device_identifier' => (string) Str::uuid(), 'device_name' => 'PC', 'os' => 'windows', 'notice_accepted' => true])->assertOk()->json();
        $this->withToken($login['token']);
        foreach (['active', 'active', 'idle', 'idle', 'unavailable', 'unavailable', 'active', 'active'] as $state) {
            $this->travel(30)->seconds();
            $this->postJson('/api/agent/heartbeat', ['activity_state' => $state])->assertOk();
        }
        $this->travel(10)->minutes();
        $this->postJson('/api/agent/heartbeat', ['activity_state' => 'active'])->assertOk();
        $this->getJson('/api/agent/portal?timezone=UTC')->assertOk()->assertJsonPath('daily.0.active_seconds', 60)->assertJsonPath('daily.0.idle_seconds', 30)->assertJsonPath('daily.0.legacy_seconds', 0);
        $this->postJson('/api/agent/heartbeat', ['activity_state' => 'invalid'])->assertUnprocessable();
        $this->travel(30)->seconds();
        $this->postJson('/api/agent/logout')->assertOk();
        $this->assertDatabaseCount('work_intervals', 3);
    }

    public function test_active_time_takes_priority_over_idle_on_another_device(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'UTC'));
        $user = User::factory()->create(['role' => 'employee']);
        foreach (['active', 'idle'] as $state) {
            $this->postJson('/api/agent/login', ['email' => $user->email, 'password' => 'password', 'device_identifier' => (string) Str::uuid(), 'device_name' => $state, 'os' => 'windows', 'notice_accepted' => true])->assertOk();
            $session = DB::table('employee_sessions')->orderByDesc('id')->first();
            DB::table('work_intervals')->insert(['employee_session_id' => $session->id, 'user_id' => $user->id, 'started_at' => '2026-10-20 08:00:00', 'ended_at' => $state === 'active' ? '2026-10-20 08:30:00' : '2026-10-20 09:00:00', 'activity_state' => $state]);
        }
        $day = Attendance::daily($user->id, 'UTC')[0];
        $this->assertSame(1800, $day['active_seconds']);
        $this->assertSame(1800, $day['idle_seconds']);
        $this->assertSame(0, $day['legacy_seconds']);
    }
}
