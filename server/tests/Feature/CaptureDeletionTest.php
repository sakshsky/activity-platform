<?php

namespace Tests\Feature;

use App\Jobs\CleanupDeletedCapture;
use App\Jobs\GenerateThumbnail;
use App\Models\Device;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\CaptureDeletion;
use App\Services\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CaptureDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function enroll(): array
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '');
        $u = User::factory()->create(['role' => 'employee']);

        return $this->postJson('/api/agent/login', ['email' => $u->email, 'password' => 'password', 'device_identifier' => (string) Str::uuid(), 'device_name' => 'PC', 'os' => 'windows', 'notice_accepted' => true])->assertOk()->json();
    }

    private function shot(): Screenshot
    {
        Storage::fake('local');
        Queue::fake();
        config(['tracker.storage' => 'local']);
        $s = Screenshot::create(['device_id' => Device::latest()->first()->id, 'event_id' => (string) Str::uuid(), 'captured_at' => now(), 'path' => 'original.png', 'thumbnail_path' => 'thumb.jpg', 'storage' => 'local', 'sha256' => hash('sha256', 'original'), 'bytes' => 8]);
        app(Vault::class)->put('original.png', 'original');
        app(Vault::class)->put('thumb.jpg', 'thumb');

        return $s;
    }

    public function test_employee_deletion_removes_both_files_keeps_placeholder_and_neutral_audit(): void
    {
        $login = $this->enroll();
        $s = $this->shot();
        $this->withToken($login['token']);
        $this->postJson('/api/agent/my-screenshots/'.$s->id.'/delete', ['reason' => 'Personal screen'])->assertOk()->assertJsonPath('cleanup_pending', false);
        Storage::disk('local')->assertMissing(['tracker/original.png', 'tracker/thumb.jpg']);
        Storage::disk('local')->assertExists(CaptureDeletion::PLACEHOLDER);
        $this->assertDatabaseHas('deletion_events', ['capture_id' => $s->id, 'reason' => 'Personal screen', 'flagged' => false, 'flag_reason' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'capture.deleted', 'subject_id' => $s->id]);
        $this->getJson('/api/agent/my-data?kind=screenshots')->assertJsonPath('data.0.deleted', true)->assertJsonPath('data.0.deleted_reason', 'Personal screen');
        $this->get('/api/agent/my-screenshots/'.$s->id)->assertOk()->assertHeader('Content-Type', 'image/png')->assertContent(app(CaptureDeletion::class)->placeholder());
        $this->postJson('/api/agent/my-screenshots/'.$s->id.'/delete')->assertConflict();
        $this->assertDatabaseCount('deletion_events', 1);
        (new GenerateThumbnail($s->id))->handle(app(Vault::class));
        $this->assertNull($s->fresh()->thumbnail_path);
    }

    public function test_other_employees_cannot_delete_or_read_and_only_superadmins_see_events(): void
    {
        $this->enroll();
        $s = $this->shot();
        $other = $this->enroll();
        $this->app['auth']->forgetGuards();
        $this->withToken($other['token']);
        $this->postJson('/api/agent/my-screenshots/'.$s->id.'/delete')->assertNotFound();
        $this->getJson('/api/agent/my-screenshots/'.$s->id)->assertNotFound();
        $this->withHeader('Authorization', '');
        $this->app['auth']->forgetGuards();
        $reviewer = User::factory()->create(['role' => 'reviewer']);
        $this->actingAs($reviewer, 'web')->getJson('/api/admin/deletions')->assertForbidden();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'web')->getJson('/api/admin/deletions?flagged=0')->assertOk();
        $this->assertDatabaseCount('deletion_events', 0);
    }

    public function test_storage_failure_hides_original_and_retry_finishes_cleanup(): void
    {
        $l = $this->enroll();
        $s = $this->shot();
        $this->withToken($l['token']);
        $broken = \Mockery::mock(Vault::class);
        $broken->shouldReceive('delete')->once()->andThrow(new \RuntimeException('Storage offline'));
        $this->app->instance(Vault::class, $broken);
        $this->postJson('/api/agent/my-screenshots/'.$s->id.'/delete')->assertStatus(202)->assertJsonPath('cleanup_pending', true);
        Queue::assertPushed(CleanupDeletedCapture::class);
        $this->get('/api/agent/my-screenshots/'.$s->id)->assertOk()->assertContent(app(CaptureDeletion::class)->placeholder());
        $real = new Vault;
        app(CaptureDeletion::class)->cleanup($s->id, $real);
        $this->assertFalse($s->fresh()->deletion_cleanup_pending);
        Storage::disk('local')->assertMissing(['tracker/original.png', 'tracker/thumb.jpg']);
    }

    public function test_pruning_deleted_record_keeps_shared_placeholder_and_audit(): void
    {
        $l = $this->enroll();
        $s = $this->shot();
        $this->withToken($l['token'])->postJson('/api/agent/my-screenshots/'.$s->id.'/delete')->assertOk();
        $s->update(['captured_at' => now()->subDays(40)]);
        $this->artisan('tracker:prune')->assertSuccessful();
        $this->assertDatabaseMissing('screenshots', ['id' => $s->id]);
        $this->assertDatabaseCount('deletion_events', 1);
        Storage::disk('local')->assertExists(CaptureDeletion::PLACEHOLDER);
    }
}
