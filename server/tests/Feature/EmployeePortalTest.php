<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeePortalTest extends TestCase
{
    use RefreshDatabase;

    private function enroll(?User $user = null): array
    {
        $user ??= User::factory()->create(['role' => 'employee']);

        return $this->postJson('/api/agent/login', ['email' => $user->email, 'password' => 'password', 'device_identifier' => (string) Str::uuid(), 'device_name' => 'Office PC', 'os' => 'windows', 'notice_accepted' => true])->assertOk()->json();
    }

    public function test_hours_exclude_offline_gaps_and_paused_periods(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));
        $login = $this->enroll();
        $this->withToken($login['token']);
        $this->travel(30)->seconds();
        $this->postJson('/api/agent/heartbeat')->assertOk();
        $this->travel(5)->minutes();
        $this->postJson('/api/agent/heartbeat')->assertOk();
        $this->travel(30)->seconds();
        $this->postJson('/api/agent/heartbeat')->assertOk();
        Device::whereKey($login['device']['id'])->update(['paused' => true]);
        $this->travel(30)->seconds();
        $this->postJson('/api/agent/heartbeat')->assertOk();
        Device::whereKey($login['device']['id'])->update(['paused' => false]);
        $this->travel(30)->seconds();
        $this->postJson('/api/agent/heartbeat')->assertOk();
        $this->travel(30)->seconds();
        $this->postJson('/api/agent/heartbeat')->assertOk();
        $this->getJson('/api/agent/portal?timezone=UTC')->assertOk()->assertJsonPath('daily.0.seconds', 90)->assertJsonCount(1, 'sessions.data');
        $this->postJson('/api/agent/logout')->assertOk();
        $this->assertDatabaseHas('employee_sessions', ['token_id' => explode('|', $login['token'])[0], 'end_reason' => 'signed_out']);
    }

    public function test_time_splits_at_local_midnight_and_merges_overlapping_devices(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 18:31:00', 'UTC'));
        $user = User::factory()->create(['role' => 'employee']);
        $first = $this->enroll($user);
        $second = $this->enroll($user);
        foreach (DB::table('employee_sessions')->get() as $session) {
            DB::table('work_intervals')->insert(['employee_session_id' => $session->id, 'user_id' => $user->id, 'started_at' => '2026-10-20 18:29:30', 'ended_at' => '2026-10-20 18:30:30']);
        }
        $days = Attendance::daily($user->id, 'Asia/Kolkata');
        $this->assertSame('2026-10-21', $days[0]['date']);
        $this->assertSame(30, $days[0]['seconds']);
        $this->assertSame(30, $days[1]['seconds']);
        $this->assertSame(60, array_sum(array_column($days, 'seconds')));
    }

    public function test_employee_profile_is_self_scoped_and_cannot_change_role(): void
    {
        $login = $this->enroll();
        $other = User::factory()->create(['role' => 'employee']);
        $this->withToken($login['token'])->postJson('/api/agent/profile', ['phone' => '12345', 'location' => 'Mumbai', 'timezone' => 'Asia/Kolkata', 'role' => 'super_admin', 'user_id' => $other->id])->assertOk();
        $this->assertDatabaseHas('employee_profiles', ['user_id' => $login['user']['id'], 'location' => 'Mumbai']);
        $this->assertDatabaseMissing('employee_profiles', ['user_id' => $other->id]);
        $this->assertDatabaseHas('users', ['id' => $login['user']['id'], 'role' => 'employee']);
        $this->getJson('/api/agent/portal')->assertOk()->assertJsonPath('profile.timezone', 'Asia/Kolkata')->assertJsonCount(30, 'daily');
        $this->postJson('/api/agent/profile', ['timezone' => 'wrong/timezone'])->assertUnprocessable();
        $this->postJson('/api/agent/profile', ['timezone' => 'UTC', 'phone' => null, 'location' => null, 'expected_revision' => 1])->assertOk();
    }

    public function test_leave_request_overlap_ownership_and_admin_decision(): void
    {
        $login = $this->enroll();
        $data = ['start_date' => '2026-11-02', 'end_date' => '2026-11-04', 'type' => 'annual', 'reason' => 'Family visit'];
        $id = $this->withToken($login['token'])->postJson('/api/agent/leave', $data)->assertCreated()->json('id');
        $this->postJson('/api/agent/leave', $data)->assertUnprocessable();
        $this->getJson('/api/agent/portal')->assertOk()->assertJsonPath('leaves.0.status', 'pending');
        $other = User::factory()->create(['role' => 'employee']);
        $foreign = (string) Str::uuid();
        DB::table('leave_requests')->insert([...$data, 'id' => $foreign, 'user_id' => $other->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/agent/leave/'.$foreign.'/cancel')->assertNotFound();
        $this->getJson('/api/agent/portal')->assertJsonCount(1, 'leaves');
        $reviewer = User::factory()->create(['role' => 'reviewer']);
        $this->actingAs($reviewer, 'web')->getJson('/api/admin/leaves')->assertForbidden();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'web')->patchJson('/api/admin/leaves/'.$id, ['status' => 'approved', 'review_note' => 'Enjoy your time away'])->assertOk();
        $this->patchJson('/api/admin/leaves/'.$id, ['status' => 'rejected'])->assertConflict();
        $this->assertDatabaseHas('leave_requests', ['id' => $id, 'status' => 'approved', 'reviewed_by' => $admin->id]);
    }

    public function test_pending_leave_can_be_cancelled_and_invalid_dates_rejected(): void
    {
        $login = $this->enroll();
        $data = ['start_date' => '2026-11-02', 'end_date' => '2026-11-04', 'type' => 'personal', 'reason' => 'Appointment'];
        $id = $this->withToken($login['token'])->postJson('/api/agent/leave', $data)->assertCreated()->json('id');
        $this->postJson('/api/agent/leave/'.$id.'/cancel')->assertOk();
        $this->postJson('/api/agent/leave/'.$id.'/cancel')->assertConflict();
        $this->postJson('/api/agent/leave', [...$data, 'end_date' => '2026-11-01'])->assertUnprocessable();
        $this->postJson('/api/agent/leave', $data)->assertCreated();
    }

    public function test_disabled_device_cannot_read_employee_records(): void
    {
        $login = $this->enroll();
        Device::whereKey($login['device']['id'])->update(['disabled_at' => now()]);
        $this->withToken($login['token'])->getJson('/api/agent/portal')->assertForbidden();
    }
}
