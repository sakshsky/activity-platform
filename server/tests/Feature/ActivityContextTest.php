<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MonitoringPolicy;
use App\Models\User;
use App\Services\ActivityContext;
use App\Services\Performance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActivityContextTest extends TestCase
{
    use RefreshDatabase;

    private function enroll(): array
    {
        $u = User::factory()->create(['role' => 'employee']);

        return $this->postJson('/api/agent/login', ['email' => $u->email, 'password' => 'password', 'device_identifier' => (string) Str::uuid(), 'device_name' => 'PC', 'os' => 'windows', 'notice_accepted' => true])->assertOk()->json();
    }

    public function test_duration_validation_idempotency_and_legacy_unknown(): void
    {
        $login = $this->enroll();
        $this->withToken($login['token']);
        $event = ['event_id' => (string) Str::uuid(), 'captured_at' => now()->subSeconds(5)->toIso8601String(), 'app_name' => 'code.exe', 'duration_seconds' => 30, 'sample_interval_seconds' => 5, 'app_switches' => 0];
        $this->postJson('/api/agent/activity', ['events' => [$event]])->assertOk();
        $this->postJson('/api/agent/activity', ['events' => [$event]])->assertOk();
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseHas('activity_logs', ['duration_seconds' => 10]);
        $event['duration_seconds'] = -1;
        $this->postJson('/api/agent/activity', ['events' => [$event]])->assertUnprocessable();
        unset($event['duration_seconds'],$event['sample_interval_seconds']);
        $event['event_id'] = (string) Str::uuid();
        $this->postJson('/api/agent/activity', ['events' => [$event]])->assertOk();
        $this->assertDatabaseHas('activity_logs', ['event_id' => $event['event_id'], 'duration_seconds' => null]);
    }

    public function test_daily_context_splits_midnight_and_unions_conflicting_devices(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 01:00:00', 'UTC'));
        $login = $this->enroll();
        $id = $login['user']['id'];
        DB::table('employee_profiles')->insert(['user_id' => $id, 'employment_details' => json_encode(['department' => 'Engineering', 'job_title' => 'Developer']), 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        $key = Performance::cohort($id)['key'];
        DB::table('role_app_rules')->insert(['cohort_key' => $key, 'app_name' => 'code.exe', 'category' => 'work_related', 'reason' => 'Coding work', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_domain_rules')->insert(['cohort_key' => $key, 'domain' => 'example.com', 'category' => 'neutral', 'reason' => 'Domain override', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['code.exe', null, 20], ['browser.exe', 'https://example.com/path', 10]] as [$app,$url,$seconds]) {
            ActivityLog::create(['device_id' => $login['device']['id'], 'event_id' => (string) Str::uuid(), 'captured_at' => '2026-10-21 00:00:10', 'duration_seconds' => $seconds, 'app_name' => $app, 'window_title' => 'Test', 'url' => $url, 'created_at' => now()]);
        }
        $result = ActivityContext::daily($id, 'UTC', null, 2);
        $this->assertSame(10, $result['daily'][0]['unclassified']);
        $this->assertSame(10, $result['daily'][1]['work_related']);
        $this->assertSame('example.com', $result['domains'][0]['domain']);
        $other = User::factory()->create();
        $this->assertSame(0, ActivityContext::daily($other->id, 'UTC', null, 2)['observed_samples']);
    }

    public function test_continuous_heartbeats_merge_and_logout_does_not_add_legacy(): void
    {
        $login = $this->enroll();
        $this->withToken($login['token']);
        for ($i = 0; $i < 20; $i++) {
            $this->travel(30)->seconds();
            $this->postJson('/api/agent/heartbeat', ['activity_state' => 'active'])->assertOk();
        }
        $this->assertDatabaseCount('work_intervals', 1);
        $this->travel(15)->seconds();
        $this->postJson('/api/agent/logout')->assertOk();
        $this->assertDatabaseCount('work_intervals', 1);
        $this->assertDatabaseMissing('work_intervals', ['activity_state' => 'legacy']);
    }

    public function test_domain_rules_and_defaults_require_admin_and_preserve_custom_rules(): void
    {
        $login = $this->enroll();
        $id = $login['user']['id'];
        DB::table('employee_profiles')->insert(['user_id' => $id, 'employment_details' => json_encode(['department' => 'Design', 'job_title' => 'Artist']), 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        $reviewer = User::factory()->create(['role' => 'reviewer']);
        $this->actingAs($reviewer, 'web')->postJson('/api/admin/performance/app-defaults', ['user_id' => $id, 'family' => 'design'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'web');
        $this->postJson('/api/admin/performance/app-rule', ['user_id' => $id, 'app_name' => 'figma.exe', 'category' => 'neutral', 'reason' => 'Custom classification'])->assertOk();
        $this->postJson('/api/admin/performance/app-defaults', ['user_id' => $id, 'family' => 'design'])->assertOk();
        $this->assertDatabaseHas('role_app_rules', ['cohort_key' => Performance::cohort($id)['key'], 'app_name' => 'figma.exe', 'category' => 'neutral']);
        $this->postJson('/api/admin/performance/domain-rule', ['user_id' => $id, 'domain' => 'https://bad/path', 'category' => 'neutral', 'reason' => 'Test classification'])->assertUnprocessable();
        $this->postJson('/api/admin/performance/domain-rule', ['user_id' => $id, 'domain' => 'EXAMPLE.COM', 'category' => 'neutral', 'reason' => 'Test classification'])->assertOk();
        $this->assertDatabaseHas('role_domain_rules', ['domain' => 'example.com']);
    }

    public function test_download_requires_login_and_rejects_tampered_release(): void
    {
        $this->getJson('/api/admin/agent-release')->assertUnauthorized();
        $directory = sys_get_temp_dir().'/agent-release-test-'.Str::uuid();
        mkdir($directory.'/app/agent-release', 0777, true);
        $previous = $this->app->storagePath();
        $this->app->useStoragePath($directory);
        try {
            $path = $directory.'/app/agent-release/';
            file_put_contents($path.'activity-agent.exe', 'fixture');
            file_put_contents($path.'release.json', json_encode(['version' => 'test', 'files' => ['exe' => ['name' => 'activity-agent.exe', 'sha256' => hash('sha256', 'fixture')]]]));
            $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'web');
            $this->getJson('/api/admin/agent-release')->assertOk()->assertJsonPath('available', true);
            $this->get('/api/admin/agent-download/exe')->assertDownload('activity-agent.exe');
            file_put_contents($path.'activity-agent.exe', 'tampered');
            $this->getJson('/api/admin/agent-download/exe')->assertStatus(503);
        } finally {
            $this->app->useStoragePath($previous);
            unlink($path.'activity-agent.exe');
            unlink($path.'release.json');
            rmdir($path);
            rmdir($directory.'/app');
            rmdir($directory);
        }
    }

    public function test_policy_idle_settings_and_employee_reminder(): void
    {
        $login = $this->enroll();
        $policy = MonitoringPolicy::current()->toArray();
        $policy['idle_threshold_seconds'] = 120;
        $policy['idle_alert_minutes'] = 2;
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'web');
        $this->putJson('/api/admin/policy', $policy)->assertOk()->assertJsonPath('policy.idle_threshold_seconds', 120);
        $policy['idle_threshold_seconds'] = 29;
        $this->putJson('/api/admin/policy', $policy)->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($login['token']);
        $this->postJson('/api/agent/heartbeat', ['activity_state' => 'idle', 'idle_seconds' => 130])->assertOk();
        $this->getJson('/api/agent/portal')->assertOk()->assertJsonPath('idle_alert', true);
        $this->travel(61)->seconds();
        $this->getJson('/api/agent/portal')->assertOk()->assertJsonPath('idle_alert', false);
    }

    public function test_new_idle_default_and_upgrades_preserve_existing_values(): void
    {
        $this->assertSame(120, MonitoringPolicy::current()->idle_threshold_seconds);
        MonitoringPolicy::current()->update(['idle_threshold_seconds' => 300]);
        $migration = require database_path('migrations/2026_10_21_183005_change_new_policy_idle_default.php');
        $migration->up();
        $this->assertSame(300, MonitoringPolicy::current()->idle_threshold_seconds);
        DB::table('monitoring_policies')->where('id', 1)->delete();
        DB::table('monitoring_policies')->insert(['id' => 1, 'notice' => 'Default from database']);
        $this->assertSame(120, MonitoringPolicy::current()->idle_threshold_seconds);
    }

    public function test_idle_threshold_sixty_is_public_and_bounds_are_validated(): void
    {
        $policy = MonitoringPolicy::current()->toArray();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'web');
        $this->putJson('/api/admin/policy', [...$policy, 'idle_threshold_seconds' => 60])->assertOk();
        $this->getJson('/api/agent/info')->assertOk()->assertJsonPath('policy.idle_threshold_seconds', 60);
        foreach ([29, 601] as $seconds) {
            $this->putJson('/api/admin/policy', [...$policy, 'idle_threshold_seconds' => $seconds])->assertUnprocessable();
        }
    }

    public function test_industry_presets_preserve_rules_and_are_idempotent_and_admin_only(): void
    {
        $login = $this->enroll();
        $id = $login['user']['id'];
        DB::table('employee_profiles')->insert(['user_id' => $id, 'employment_details' => json_encode(['department' => 'Studio', 'job_title' => 'Designer']), 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        $key = Performance::cohort($id)['key'];
        DB::table('role_app_rules')->insert(['cohort_key' => $key, 'app_name' => 'chrome.exe', 'category' => 'neutral', 'reason' => 'Existing custom rule', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'reviewer']), 'web')->postJson('/api/admin/performance/apply-preset', ['user_id' => $id, 'preset' => 'agency'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'web');
        $this->postJson('/api/admin/performance/apply-preset', ['user_id' => $id, 'preset' => 'unknown'])->assertUnprocessable();
        foreach (['agency', 'devshop', 'office'] as $preset) {
            $this->postJson('/api/admin/performance/apply-preset', ['user_id' => $id, 'preset' => $preset])->assertOk();
            $count = DB::table('role_app_rules')->where('cohort_key', $key)->count();
            $this->postJson('/api/admin/performance/apply-preset', ['user_id' => $id, 'preset' => $preset])->assertOk()->assertJsonPath('rules_added', 0);
            $this->assertSame($count, DB::table('role_app_rules')->where('cohort_key', $key)->count());
        }
        $this->assertDatabaseHas('role_app_rules', ['cohort_key' => $key, 'app_name' => 'chrome.exe', 'category' => 'neutral', 'reason' => 'Existing custom rule']);
        foreach (['figma.exe', 'code.exe', 'excel.exe'] as $app) {
            $this->assertDatabaseHas('role_app_rules', ['cohort_key' => $key, 'app_name' => $app, 'category' => 'work_related']);
        }
    }
}
