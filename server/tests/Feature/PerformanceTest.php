<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\MonitoringPolicy;
use App\Models\User;
use App\Services\Performance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'super_admin']);
        $this->employee = User::factory()->create(['role' => 'employee']);
        DB::table('employee_profiles')->insert(['user_id' => $this->employee->id, 'employment_details' => json_encode(['department' => 'Engineering', 'job_title' => 'Developer'])]);
        $this->token = $this->postJson('/api/agent/login', ['email' => $this->employee->email, 'password' => 'password', 'device_identifier' => (string) Str::uuid(), 'device_name' => 'Test PC', 'os' => 'windows', 'notice_accepted' => true])->assertOk()->json('token');
    }

    private function asAdmin(): static
    {
        Auth::forgetGuards();
        $this->withHeader('Authorization', '');

        return $this->actingAs($this->admin, 'web');
    }

    private function asEmployee(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->token);
    }

    private function assign(): string
    {
        return $this->asAdmin()->postJson('/api/admin/performance/tasks', ['user_id' => $this->employee->id, 'kind' => 'goal', 'title' => 'Ship reviewed change', 'criteria' => 'Tests pass and reviewer accepts the documented result.', 'due_date' => '2026-10-09', 'points' => 2])->assertCreated()->json('id');
    }

    private function acceptedWork(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $id = $this->assign();
            $this->asEmployee()->postJson('/api/agent/tasks/'.$id.'/submit', ['revision' => 0, 'evidence' => 'Implemented and tested the agreed result.'])->assertOk();
            $this->asAdmin()->postJson('/api/admin/performance/tasks/'.$id.'/review', ['revision' => 1, 'status' => 'accepted', 'quality' => 80, 'review_note' => 'Checked the result against the agreed success criteria.'])->assertOk();
        }
    }

    public function test_task_workflow_is_owner_scoped_and_rejects_stale_or_final_changes(): void
    {
        $id = $this->assign();
        $other = User::factory()->create(['role' => 'employee']);
        DB::table('performance_tasks')->where('id', $id)->update(['user_id' => $other->id]);
        $this->asEmployee()->postJson('/api/agent/tasks/'.$id.'/submit', ['revision' => 0, 'evidence' => 'A completed result with supporting notes.'])->assertNotFound();
        $this->getJson('/api/agent/performance?week=2026-10-05&user_id='.$other->id)->assertOk()->assertJsonCount(0, 'tasks');
        DB::table('performance_tasks')->where('id', $id)->update(['user_id' => $this->employee->id]);
        $this->asEmployee()->postJson('/api/agent/tasks/'.$id.'/submit', ['revision' => 0, 'evidence' => 'A completed result with supporting notes.'])->assertOk();
        $this->asAdmin()->postJson('/api/admin/performance/tasks/'.$id.'/review', ['revision' => 0, 'status' => 'accepted', 'quality' => 90, 'review_note' => 'The result meets all agreed criteria.'])->assertConflict();
        $this->postJson('/api/admin/performance/tasks/'.$id.'/review', ['revision' => 1, 'status' => 'accepted', 'quality' => 90, 'review_note' => 'The result meets all agreed criteria.'])->assertOk();
        $this->asEmployee()->postJson('/api/agent/tasks/'.$id.'/submit', ['revision' => 2, 'evidence' => 'Trying to overwrite the final result.'])->assertConflict();
        $this->assertDatabaseHas('performance_tasks', ['id' => $id, 'status' => 'accepted', 'quality' => 90]);
    }

    public function test_review_requires_closed_week_sufficient_data_and_current_evidence(): void
    {
        $this->acceptedWork();
        $report = $this->asAdmin()->getJson('/api/admin/performance?user_id='.$this->employee->id.'&week=2026-10-05')->assertOk()->json();
        $body = ['user_id' => $this->employee->id, 'week' => '2026-10-05', 'fingerprint' => $report['fingerprint'], 'collaboration' => 90, 'manager_note' => 'Reviewed delivery evidence and collaboration examples.', 'confirmed' => true];
        $this->postJson('/api/admin/performance/publish', $body)->assertUnprocessable();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'));
        $report = $this->getJson('/api/admin/performance?user_id='.$this->employee->id.'&week=2026-10-05')->assertOk()->json();
        $body['fingerprint'] = $report['fingerprint'];
        $this->asEmployee()->postJson('/api/agent/work-context', ['work_date' => '2026-10-08', 'kind' => 'meeting', 'minutes' => 90, 'note' => 'Design review with the delivery team.'])->assertCreated();
        $this->asAdmin()->postJson('/api/admin/performance/publish', $body)->assertConflict();
        $body['fingerprint'] = $this->getJson('/api/admin/performance?user_id='.$this->employee->id.'&week=2026-10-05')->json('fingerprint');
        $this->postJson('/api/admin/performance/publish', $body)->assertCreated();
        $this->postJson('/api/admin/performance/publish', $body)->assertConflict();
        $result = $this->asEmployee()->getJson('/api/agent/performance?week=2026-10-05')->assertOk()->json();
        $this->assertEquals(93, $result['published']['score']);
        $this->assertNull($result['published']['rank']);
        $this->assertCount(1, $result['published']['snapshot']['explanations']);
        $this->assertSame(0, array_sum(array_column($result['daily'], 'active_seconds')));
    }

    public function test_insufficient_tasks_and_other_roles_cannot_publish(): void
    {
        $this->assign();
        $this->travelTo(CarbonImmutable::parse('2026-10-12', 'UTC'));
        $report = $this->asAdmin()->getJson('/api/admin/performance?user_id='.$this->employee->id.'&week=2026-10-05')->assertOk()->assertJsonPath('eligible', false)->json();
        $this->postJson('/api/admin/performance/publish', ['user_id' => $this->employee->id, 'week' => '2026-10-05', 'fingerprint' => $report['fingerprint'], 'collaboration' => 90, 'manager_note' => 'Attempt to publish without enough supporting evidence.', 'confirmed' => true])->assertUnprocessable();
        $reviewer = User::factory()->create(['role' => 'reviewer']);
        Auth::forgetGuards();
        $this->actingAs($reviewer, 'web')->getJson('/api/admin/performance?user_id='.$this->employee->id)->assertForbidden();
        $this->getJson('/api/admin/performance/rankings')->assertForbidden();
        $this->asEmployee()->getJson('/api/agent/performance?week=2026-10-06')->assertUnprocessable();
    }

    public function test_rankings_compare_only_same_cohort_and_preserve_ties(): void
    {
        $cohort = Performance::cohort($this->employee->id);
        foreach ([[$cohort['key'], 90], [$cohort['key'], 90], ['different-role', 100]] as [$key, $score]) {
            $user = User::factory()->create(['role' => 'employee']);
            DB::table('performance_reviews')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'week' => '2026-10-05', 'cohort_key' => $key, 'cohort_label' => $key, 'score' => $score, 'collaboration' => 90, 'manager_note' => 'Reviewed evidence.', 'snapshot' => '{}', 'reviewed_by' => $this->admin->id, 'published_at' => now()]);
        }
        $rows = $this->asAdmin()->getJson('/api/admin/performance/rankings?week=2026-10-05')->assertOk()->json('data');
        foreach ($rows as $row) {
            $this->assertSame($row['cohort'] === 'different-role' ? null : 1, $row['rank']);
            $this->assertArrayNotHasKey('snapshot', $row);
        }
    }

    public function test_app_classification_and_idle_data_never_change_the_score(): void
    {
        $this->acceptedWork();
        $this->travelTo(CarbonImmutable::parse('2026-10-12', 'UTC'));
        $before = Performance::report($this->employee, CarbonImmutable::parse('2026-10-05', 'UTC'));
        $this->asAdmin()->postJson('/api/admin/performance/app-rule', ['user_id' => $this->employee->id, 'app_name' => 'msedge.exe', 'category' => 'work_related', 'reason' => 'Browser research is part of engineering work.'])->assertOk();
        $session = DB::table('employee_sessions')->first();
        DB::table('work_intervals')->insert(['employee_session_id' => $session->id, 'user_id' => $this->employee->id, 'started_at' => '2026-10-08 09:00:00', 'ended_at' => '2026-10-08 17:00:00', 'activity_state' => 'idle']);
        $after = Performance::report($this->employee, CarbonImmutable::parse('2026-10-05', 'UTC'));
        $this->assertSame($before['metrics'], $after['metrics']);
        $this->assertSame(28800, array_sum(array_column($after['daily'], 'idle_seconds')));
    }

    public function test_screenshot_metadata_uses_actual_image_and_authenticated_owner(): void
    {
        Storage::fake('local');
        config(['tracker.storage' => 'local']);
        Queue::fake();
        MonitoringPolicy::current();
        $file = UploadedFile::fake()->image('capture.png', 32, 24);
        $hash = hash_file('sha256', $file->getRealPath());
        $payload = ['event_id' => (string) Str::uuid(), 'captured_at' => now()->toIso8601String(), 'file' => $file, 'app_name' => 'editor.exe', 'capture_state' => 'active', 'idle_seconds' => 12, 'idle_threshold_seconds' => 300, 'agent_version' => '1.2.0', 'width' => 999, 'employee_id' => $this->admin->id];
        $this->asEmployee()->postJson('/api/agent/screenshots', $payload)->assertCreated();
        $this->getJson('/api/agent/my-data?kind=screenshots')->assertOk()->assertJsonPath('data.0.width', 32)->assertJsonPath('data.0.height', 24)->assertJsonPath('data.0.employee_id', $this->employee->id)->assertJsonPath('data.0.idle_seconds', 12)->assertJsonPath('data.0.sha256', $hash);
        $this->postJson('/api/agent/screenshots', $payload)->assertOk();
        $this->assertDatabaseCount('screenshots', 1);
        $this->asAdmin()->getJson('/api/admin/screenshots')->assertOk()->assertJsonPath('data.0.width', 32)->assertJsonPath('data.0.agent_version', '1.2.0');
    }

    public function test_weighted_scores_handle_late_unfinished_and_cancelled_work(): void
    {
        $ids = [$this->assign(), $this->assign(), $this->assign(), $this->assign()];
        DB::table('performance_tasks')->where('id', $ids[0])->update(['status' => 'accepted', 'points' => 2, 'quality' => 100, 'submitted_at' => '2026-10-09 23:59:59', 'accepted_at' => '2026-10-12 10:00:00']);
        DB::table('performance_tasks')->where('id', $ids[1])->update(['status' => 'accepted', 'points' => 1, 'quality' => 40, 'submitted_at' => '2026-10-10 00:00:01', 'accepted_at' => '2026-10-12 10:00:00']);
        DB::table('performance_tasks')->where('id', $ids[2])->update(['points' => 1]);
        DB::table('performance_tasks')->where('id', $ids[3])->update(['status' => 'cancelled', 'points' => 10]);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));
        $report = Performance::report($this->employee, CarbonImmutable::parse('2026-10-05', 'UTC'));
        $this->assertSame(3, $report['metrics']['task_count']);
        $this->assertEquals(75, $report['metrics']['completion']);
        $this->assertEquals(80, $report['metrics']['quality']);
        $this->assertEquals(50, $report['metrics']['deadlines']);
        $this->assertSame(73.0, Performance::score($report['metrics'], 90));
        $this->assertTrue($report['eligible']);
        $this->employee->forceFill(['created_at' => '2026-10-08 09:00:00'])->save();
        $this->assertFalse(Performance::report($this->employee, CarbonImmutable::parse('2026-10-05', 'UTC'))['eligible']);
        $this->employee->forceFill(['created_at' => '2026-10-05 09:00:00'])->save();
        DB::table('employee_profiles')->where('user_id', $this->employee->id)->update(['employment_details' => '{}']);
        $this->assertFalse(Performance::report($this->employee, CarbonImmutable::parse('2026-10-05', 'UTC'))['eligible']);
    }

    public function test_work_app_percentage_is_informational_and_snapshot_is_immutable(): void
    {
        $this->acceptedWork();
        $week = CarbonImmutable::parse('2026-10-05', 'UTC');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'));
        $key = Performance::cohort($this->employee->id)['key'];
        foreach (['code.exe' => 'work_related', 'chat.exe' => 'neutral'] as $app => $category) {
            DB::table('role_app_rules')->insert(['cohort_key' => $key, 'app_name' => $app, 'category' => $category, 'reason' => 'Fixture', 'created_at' => now(), 'updated_at' => now()]);
        }
        $device = Device::where('user_id', $this->employee->id)->first();
        $add = function (string $app, int $seconds, string $at) use ($device) {
            return ActivityLog::create(['device_id' => $device->id, 'event_id' => (string) Str::uuid(), 'captured_at' => $at, 'duration_seconds' => $seconds, 'app_name' => $app, 'window_title' => '', 'created_at' => now()]);
        };
        $work = $add('code.exe', 80, '2026-10-08 10:02:00');
        $neutral = $add('chat.exe', 20, '2026-10-08 10:04:00');
        $add('unknown.exe', 300, '2026-10-08 10:15:00');
        $report = Performance::report($this->employee, $week);
        $this->assertSame(80.0, $report['work_app_percent']);
        $score = Performance::score($report['metrics'], 90);
        $this->asAdmin()->getJson('/api/admin/performance?user_id='.$this->employee->id.'&week=2026-10-05')->assertJsonPath('work_app_percent', 80);
        $this->postJson('/api/admin/performance/publish', ['user_id' => $this->employee->id, 'week' => '2026-10-05', 'fingerprint' => $report['fingerprint'], 'collaboration' => 90, 'manager_note' => 'Reviewed outcomes and discussed the employee context.', 'confirmed' => true])->assertCreated();
        $work->update(['duration_seconds' => 40]);
        $neutral->update(['duration_seconds' => 60]);
        $changed = Performance::report($this->employee, $week);
        $this->assertSame(40.0, $changed['work_app_percent']);
        $this->assertSame($score, Performance::score($changed['metrics'], 90));
        $this->asEmployee()->getJson('/api/agent/performance?week=2026-10-05')->assertOk()->assertJsonPath('work_app_percent', 40)->assertJsonPath('published.snapshot.work_app_percent', 80);
        $work->delete();
        $neutral->delete();
        $this->assertNull(Performance::report($this->employee,$week)['work_app_percent']);
    }
}
