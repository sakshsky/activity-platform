<?php

namespace Tests\Feature;

use App\Http\Controllers\AgentController;
use App\Jobs\GenerateThumbnail;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\MonitoringPolicy;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\Vault;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TrackerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['tracker.storage' => 'local']);
        MonitoringPolicy::current();
    }

    private function enroll(?User $user = null): array
    {
        $user ??= User::factory()->create(['role' => 'employee']);

        return $this->postJson('/api/agent/login', ['email' => $user->email, 'password' => 'password', 'device_identifier' => (string) Str::uuid(), 'device_name' => 'Test PC', 'os' => 'windows', 'notice_accepted' => true])->assertOk()->json();
    }

    public function test_policy_seeding_does_not_use_a_generated_insert_id(): void
    {
        MonitoringPolicy::query()->delete();
        $connection = DB::connection();
        $original = $connection->getPostProcessor();
        $processor = \Mockery::mock(get_class($original))->makePartial();
        $processor->shouldReceive('processInsertGetId')->andReturnUsing(function ($query, $sql, $values, $sequence) {
            $query->getConnection()->insert($sql, $values);

            return 0; // MySQL returns zero for this non-auto-increment primary key.
        });
        $connection->setPostProcessor($processor);
        try {
            $this->seed(DatabaseSeeder::class);
            $policy = MonitoringPolicy::current();
            $this->assertSame(1, $policy->getKey());
            $this->assertSame(120, $policy->screenshot_interval_seconds);
            $this->seed(DatabaseSeeder::class);
            $this->assertDatabaseCount('monitoring_policies', 1);
        } finally {
            $connection->setPostProcessor($original);
        }
    }

    public function test_case_variant_duplicate_email_returns_validation_error(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        User::factory()->create(['email' => 'employee@example.test']);
        $this->actingAs($admin)->postJson('/api/admin/users', ['name' => 'Duplicate', 'email' => 'EMPLOYEE@EXAMPLE.TEST', 'role' => 'employee', 'password' => 'LocalTestPassword!'])->assertUnprocessable();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_admin_login_normalizes_email_case(): void
    {
        User::factory()->create(['email' => 'admin@example.test', 'role' => 'super_admin']);
        $this->postJson('/api/admin/login', ['email' => 'ADMIN@EXAMPLE.TEST', 'password' => 'password'])->assertOk();
    }

    public function test_upload_rechecks_pause_after_middleware_snapshot(): void
    {
        $login = $this->enroll();
        $request = $this->staleDeviceRequest($login);
        $request->replace(['events' => [['event_id' => (string) Str::uuid(), 'captured_at' => now()->toIso8601String(), 'app_name' => 'test.exe']]]);
        Device::whereKey($login['device']['id'])->update(['paused' => true]);
        try {
            app(AgentController::class)->activity($request);
            $this->fail('A paused device was accepted using stale middleware state.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_old_logout_cannot_clear_a_replacement_device_token(): void
    {
        $login = $this->enroll();
        $request = $this->staleDeviceRequest($login);
        $replacement = $request->user()->createToken('replacement', ['device:collect'], now()->addHour());
        Device::whereKey($login['device']['id'])->update(['token_id' => $replacement->accessToken->id]);
        try {
            app(AgentController::class)->logout($request);
            $this->fail('Stale logout cleared a replacement token.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseHas('devices', ['id' => $login['device']['id'], 'token_id' => $replacement->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $replacement->accessToken->id]);
    }

    private function staleDeviceRequest(array $login): Request
    {
        $token = PersonalAccessToken::findToken($login['token']);
        $request = Request::create('/api/agent/activity', 'POST');
        $request->setUserResolver(fn () => $token->tokenable->withAccessToken($token));
        $request->attributes->set('device', Device::findOrFail($login['device']['id']));

        return $request;
    }

    public function test_expired_device_token_is_rejected_without_global_expiration(): void
    {
        config(['sanctum.expiration' => null]);
        $login = $this->enroll();
        $token = PersonalAccessToken::findToken($login['token']);
        $token->update(['expires_at' => now()->subMinute()]);
        $this->withToken($login['token'])->postJson('/api/agent/heartbeat')->assertUnauthorized();
    }

    public function test_search_treats_percent_and_underscore_as_literal_characters(): void
    {
        ActivityLog::factory()->create(['window_title' => '100%_done!', 'app_name' => 'app_100%.exe']);
        ActivityLog::factory()->create(['window_title' => '100XXdone!', 'app_name' => 'appX100YY.exe']);
        $reviewer = User::factory()->create(['role' => 'reviewer']);
        $this->actingAs($reviewer)->getJson('/api/admin/activity?'.http_build_query(['search' => '%_', 'app' => '_100%']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.window_title', '100%_done!');
    }

    public function test_competing_thumbnail_jobs_publish_one_file_and_remove_only_the_loser(): void
    {
        $shot = Screenshot::factory()->create();
        $source = UploadedFile::fake()->image('source.png', 20, 20);
        $vault = app(Vault::class);
        $vault->put($shot->path, file_get_contents($source->getRealPath()));
        $loserPath = null;
        $competingVault = \Mockery::mock(Vault::class)->makePartial();
        $competingVault->shouldReceive('put')->once()->andReturnUsing(function (string $path, string $bytes, ?string $driver) use ($vault, $shot, &$loserPath): void {
            $loserPath = $path;
            $vault->put($path, $bytes, $driver);
            (new GenerateThumbnail($shot->id))->handle($vault);
        });
        (new GenerateThumbnail($shot->id))->handle($competingVault);
        $winnerPath = $shot->fresh()->thumbnail_path;
        $this->assertNotNull($winnerPath);
        $this->assertNotSame($loserPath, $winnerPath);
        Storage::disk('local')->assertExists('tracker/'.$winnerPath);
        Storage::disk('local')->assertMissing('tracker/'.$loserPath);
    }

    public function test_large_thumbnail_input_is_skipped_before_gd_decode(): void
    {
        $shot = Screenshot::factory()->create();
        $source = UploadedFile::fake()->image('source.png', 2, 2);
        $bytes = file_get_contents($source->getRealPath());
        $bytes = substr_replace($bytes, pack('NN', 5000, 5000), 16, 8);
        $vault = app(Vault::class);
        $vault->put($shot->path, $bytes);
        (new GenerateThumbnail($shot->id))->handle($vault);
        $this->assertNull($shot->fresh()->thumbnail_path);
        Storage::disk('local')->assertExists('tracker/'.$shot->path);
    }

    public function test_admin_login_roles_and_disabled_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'bad'])->assertUnprocessable();
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password'])->assertOk()->assertJsonPath('user.role', 'super_admin');
        $this->getJson('/api/admin/users')->assertOk();
        $this->postJson('/api/admin/logout')->assertOk();
        $employee = User::factory()->create();
        $this->postJson('/api/admin/login', ['email' => $employee->email, 'password' => 'password'])->assertUnprocessable();
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $admin->update(['enabled' => false]);
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password'])->assertUnprocessable();
    }

    public function test_reviewer_can_review_but_cannot_manage(): void
    {
        $reviewer = User::factory()->create(['role' => 'reviewer']);
        $this->actingAs($reviewer)->getJson('/api/admin/screenshots')->assertOk();
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->putJson('/api/admin/policy', [])->assertForbidden();
        $this->getJson('/api/admin/audit')->assertForbidden();
    }

    public function test_device_requires_notice_and_cannot_be_reassigned(): void
    {
        $user = User::factory()->create();
        $identifier = (string) Str::uuid();
        $body = ['email' => $user->email, 'password' => 'password', 'device_identifier' => $identifier, 'device_name' => 'PC', 'os' => 'windows', 'notice_accepted' => false];
        $this->postJson('/api/agent/login', $body)->assertUnprocessable();
        $this->postJson('/api/agent/login', [...$body, 'notice_accepted' => true])->assertOk();
        $other = User::factory()->create();
        $this->postJson('/api/agent/login', [...$body, 'email' => $other->email, 'notice_accepted' => true])->assertForbidden();
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_device_token_cannot_access_admin_endpoints_and_disable_revokes_it(): void
    {
        $session = $this->enroll();
        $this->withToken($session['token'])->postJson('/api/agent/heartbeat')->assertOk();
        $this->getJson('/api/admin/users')->assertUnauthorized();
        Device::find($session['device']['id'])->update(['disabled_at' => now()]);
        $this->postJson('/api/agent/heartbeat')->assertForbidden();
    }

    public function test_screenshot_upload_is_private_idempotent_and_thumbnail_is_generated(): void
    {
        Queue::fake();
        $session = $this->enroll();
        $id = (string) Str::uuid();
        $file = UploadedFile::fake()->image('screen.png', 1200, 700);
        $bytes = file_get_contents($file->getRealPath());
        $body = ['event_id' => $id, 'captured_at' => now()->toIso8601String(), 'file' => $file, 'app_name' => 'editor.exe'];
        $response = $this->withToken($session['token'])->postJson('/api/agent/screenshots', $body)->assertCreated()->assertJsonPath('accepted', true);
        $shot = Screenshot::find($response->json('id'));
        Storage::disk('local')->assertExists('tracker/'.$shot->path);
        $body['file'] = UploadedFile::fake()->createWithContent('again.png', $bytes);
        $this->postJson('/api/agent/screenshots', $body)->assertOk();
        $this->assertDatabaseCount('screenshots', 1);
        Queue::assertPushed(GenerateThumbnail::class, 2);
        (new GenerateThumbnail($shot->id))->handle(app(Vault::class));
        $this->assertNotNull($shot->fresh()->thumbnail_path);
        $body['file'] = UploadedFile::fake()->createWithContent('finished.png', $bytes);
        $this->postJson('/api/agent/screenshots', $body)->assertOk();
        Queue::assertPushed(GenerateThumbnail::class, 2);
        $this->getJson('/api/admin/screenshots/'.$shot->id.'/file')->assertUnauthorized();
    }

    public function test_gallery_file_access_is_audited(): void
    {
        $shot = Screenshot::factory()->create();
        app(Vault::class)->put($shot->path, 'test-image');
        $reviewer = User::factory()->create(['role' => 'reviewer']);
        $this->actingAs($reviewer)->get('/api/admin/screenshots/'.$shot->id.'/file')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $reviewer->id, 'action' => 'screenshot.viewed', 'subject_id' => $shot->id]);
    }

    public function test_activity_batch_is_idempotent_and_strips_url_secrets(): void
    {
        MonitoringPolicy::current()->update(['browser_urls' => true]);
        $session = $this->enroll();
        $event = ['event_id' => (string) Str::uuid(), 'captured_at' => now()->toIso8601String(), 'app_name' => 'chrome.exe', 'window_title' => 'Report', 'url' => 'https://user:secret@example.com/report?token=secret#anchor'];
        $this->withToken($session['token'])->postJson('/api/agent/activity', ['events' => [$event]])->assertOk()->assertJsonPath('accepted_ids.0', $event['event_id']);
        $this->postJson('/api/agent/activity', ['events' => [$event]])->assertOk();
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseHas('activity_logs', ['url' => 'https://example.com/report']);
    }

    public function test_admin_pause_prevents_new_ingestion(): void
    {
        $session = $this->enroll();
        Device::find($session['device']['id'])->update(['paused' => true]);
        $this->withToken($session['token'])->postJson('/api/agent/heartbeat')->assertOk()->assertJsonPath('policy.monitoring_enabled', false);
        $event = ['event_id' => (string) Str::uuid(), 'captured_at' => now()->toIso8601String(), 'app_name' => 'test.exe'];
        $this->postJson('/api/agent/activity', ['events' => [$event]])->assertStatus(409);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_user_disable_revokes_devices_and_preserves_records(): void
    {
        $session = $this->enroll();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$session['user']['id'], ['enabled' => false])->assertOk();
        $device = Device::find($session['device']['id']);
        $this->assertNotNull($device->disabled_at);
        $this->assertNull($device->token_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->patchJson('/api/admin/users/'.$admin->id, ['enabled' => false])->assertUnprocessable();
    }

    public function test_csv_export_neutralizes_spreadsheet_formulas_and_is_audited(): void
    {
        ActivityLog::factory()->create(['window_title' => '=1+1']);
        $reviewer = User::factory()->create(['role' => 'reviewer']);
        $response = $this->actingAs($reviewer)->get('/api/admin/activity/export');
        $response->assertOk();
        $this->assertStringContainsString("'=1+1", $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'activity.exported', 'actor_id' => $reviewer->id]);
    }

    public function test_retention_removes_expired_files_and_keeps_recent_data(): void
    {
        $old = Screenshot::factory()->create(['captured_at' => now()->subDays(40)]);
        app(Vault::class)->put($old->path, 'old screenshot');
        $recent = Screenshot::factory()->create();
        app(Vault::class)->put($recent->path, 'recent screenshot');
        ActivityLog::factory()->create(['captured_at' => now()->subDays(40)]);
        AuditLog::factory()->create(['created_at' => now()->subDays(400)]);
        $this->artisan('tracker:prune')->assertSuccessful();
        Storage::disk('local')->assertMissing('tracker/'.$old->path);
        Storage::disk('local')->assertExists('tracker/'.$recent->path);
        $this->assertDatabaseCount('screenshots', 1);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
