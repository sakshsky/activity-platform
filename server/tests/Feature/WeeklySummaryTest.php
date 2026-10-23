<?php

namespace Tests\Feature;

use App\Console\Commands\WeeklySummary;
use App\Mail\WeeklySummaryMail;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Screenshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class WeeklySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00:00', 'UTC'));
    }

    private function seedRecords(): User
    {
        $u = User::factory()->create(['role' => 'employee', 'name' => 'Alex Employee']);
        $login = $this->postJson('/api/agent/login', ['email' => $u->email, 'password' => 'password', 'device_identifier' => (string) Str::uuid(), 'device_name' => 'PC', 'os' => 'windows', 'notice_accepted' => true])->assertOk()->json();
        $device = Device::findOrFail($login['device']['id']);
        $session = DB::table('employee_sessions')->where('user_id', $u->id)->value('id');
        foreach ([['2026-10-12 10:00:00', '2026-10-12 11:00:00', 'active'], ['2026-10-12 10:30:00', '2026-10-12 11:00:00', 'active'], ['2026-10-12 11:00:00', '2026-10-12 12:00:00', 'idle'], ['2026-10-19 10:00:00', '2026-10-19 12:00:00', 'active']] as [$start,$end,$state]) {
            DB::table('work_intervals')->insert(['user_id' => $u->id, 'employee_session_id' => $session, 'started_at' => $start, 'ended_at' => $end, 'activity_state' => $state]);
        }
        foreach (['2026-10-12 10:00:00', '2026-10-18 23:59:59', '2026-10-19 00:00:00'] as $at) {
            ActivityLog::create(['device_id' => $device->id, 'event_id' => (string) Str::uuid(), 'captured_at' => $at, 'app_name' => 'code.exe', 'created_at' => now()]);
            Screenshot::create(['device_id' => $device->id, 'event_id' => (string) Str::uuid(), 'captured_at' => $at, 'path' => 'fixture.png', 'bytes' => 1, 'sha256' => str_repeat('a', 64)]);
        }
        DB::table('deletion_events')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'employee_name' => $u->name, 'device_id' => $device->id, 'capture_type' => 'screenshot', 'capture_id' => (string) Str::uuid(), 'deleted_at' => '2026-10-18 12:00:00', 'reason' => 'Private reason must not appear', 'flagged' => false]);
        DB::table('activity_anomalies')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'device_id' => $device->id, 'event_id' => (string) Str::uuid(), 'started_at' => '2026-10-12 10:00:00', 'ended_at' => '2026-10-12 10:10:00', 'rule' => 'fixture', 'evidence' => '{}', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('leave_requests')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'start_date' => '2026-10-22', 'end_date' => '2026-10-22', 'type' => 'personal', 'reason' => 'Private leave reason', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return $u;
    }

    public function test_dry_run_prints_previous_week_without_sending(): void
    {
        Mail::fake();
        $this->seedRecords();
        $this->assertSame(0, Artisan::call('tracker:weekly-summary', ['--dry-run' => true]));
        $body = Artisan::output();
        foreach (['2026-10-12 through 2026-10-18 (UTC)', 'Total tracked active hours: 1.00', 'Alex Employee: 1.00 hours', 'code.exe: 2 samples', 'Screenshots captured this week: 2', 'Neutral deletions this week: 1', 'Alex Employee: 1', 'Activity pattern flags pending review (current): 1', 'Leave requests pending decision (current): 1', WeeklySummary::DISCLOSURE] as $text) {
            $this->assertStringContainsString($text, $body);
        }
        $this->assertStringNotContainsString('Private reason', $body);
        $this->assertStringNotContainsString('Private leave reason', $body);
        Mail::assertNothingSent();
    }

    public function test_only_enabled_opted_in_superadmins_receive_plain_text_mail(): void
    {
        Mail::fake();
        $this->seedRecords();
        $yes = User::factory()->create(['role' => 'super_admin', 'weekly_summary_enabled' => true]);
        User::factory()->create(['role' => 'super_admin', 'weekly_summary_enabled' => false]);
        User::factory()->create(['role' => 'reviewer', 'weekly_summary_enabled' => true]);
        User::factory()->create(['role' => 'employee', 'weekly_summary_enabled' => true]);
        User::factory()->create(['role' => 'super_admin', 'enabled' => false, 'weekly_summary_enabled' => true]);
        $this->artisan('tracker:weekly-summary')->assertSuccessful();
        Mail::assertSent(WeeklySummaryMail::class, 1);
        Mail::assertSent(WeeklySummaryMail::class, function ($mail) use ($yes) {
            $this->assertSame('emails.weekly-summary', $mail->content()->text);
            $this->assertNull($mail->content()->view);
            $mail->assertSeeInText(WeeklySummary::DISCLOSURE);

            return $mail->hasTo($yes->email);
        });
    }

    public function test_log_mailer_is_successful_without_external_delivery(): void
    {
        config(['mail.default' => 'log']);
        User::factory()->create(['role' => 'super_admin', 'weekly_summary_enabled' => true]);
        $this->artisan('tracker:weekly-summary')->assertSuccessful();
        $this->assertDatabaseHas('audit_logs', ['action' => 'weekly_summary.processed']);
    }

    public function test_no_opt_in_sends_nothing_and_preference_is_self_scoped(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $other = User::factory()->create(['role' => 'super_admin']);
        $this->assertFalse($admin->fresh()->weekly_summary_enabled);
        $this->artisan('tracker:weekly-summary')->assertSuccessful();
        Mail::assertNothingSent();
        $this->actingAs($admin, 'web')->putJson('/api/admin/weekly-summary-preference', ['weekly_summary_enabled' => true, 'user_id' => $other->id])->assertOk()->assertJsonPath('user.weekly_summary_enabled', true);
        $this->assertFalse($other->fresh()->weekly_summary_enabled);
        $this->putJson('/api/admin/weekly-summary-preference', ['weekly_summary_enabled' => false])->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'reviewer']), 'web')->putJson('/api/admin/weekly-summary-preference', ['weekly_summary_enabled' => true])->assertForbidden();
    }
}
