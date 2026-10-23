<?php

namespace Tests\Feature;

use App\Models\MonitoringPolicy;
use App\Models\User;
use App\Models\VideoCapture;
use App\Services\VideoInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OptionalModulesTest extends TestCase
{
    use RefreshDatabase;

    private function enroll(): array
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '');
        $u = User::factory()->create(['role' => 'employee']);

        return $this->postJson('/api/agent/login', ['email' => $u->email, 'password' => 'password', 'device_identifier' => (string) Str::uuid(), 'device_name' => 'Test', 'os' => 'windows', 'notice_accepted' => true])->assertOk()->json();
    }

    public function test_modules_default_off_and_require_current_explicit_acknowledgment(): void
    {
        $login = $this->enroll();
        $this->withToken($login['token']);
        $this->assertFalse(MonitoringPolicy::current()->video_enabled);
        $this->assertFalse(MonitoringPolicy::current()->anomaly_enabled);
        MonitoringPolicy::current()->update(['video_enabled' => true, 'anomaly_enabled' => true, 'version' => 2]);
        $this->postJson('/api/agent/heartbeat')->assertOk()->assertJsonPath('policy.modules_consented', false);
        $this->postJson('/api/agent/modules/consent', ['policy_version' => 1, 'accepted' => true])->assertConflict();
        $this->postJson('/api/agent/modules/consent', ['policy_version' => 2, 'accepted' => false])->assertUnprocessable();
        $this->postJson('/api/agent/modules/consent', ['policy_version' => 2, 'accepted' => true])->assertOk();
        $this->postJson('/api/agent/heartbeat')->assertJsonPath('policy.modules_consented', true);
        MonitoringPolicy::current()->update(['version' => 3]);
        $this->postJson('/api/agent/heartbeat')->assertJsonPath('policy.modules_consented', false);
    }

    public function test_video_upload_idempotency_quota_ownership_ranges_and_disable(): void
    {
        Storage::fake('local');
        $login = $this->enroll();
        MonitoringPolicy::current()->update(['video_enabled' => true]);
        $this->withToken($login['token']);
        $this->mock(VideoInspector::class)->shouldReceive('inspect')->andReturn(['width' => 320, 'height' => 180, 'duration_seconds' => 10]);
        $data = ['event_id' => (string) Str::uuid(), 'captured_at' => now()->toIso8601String(), 'notice_version' => '1'];
        $this->post('/api/agent/videos', [...$data, 'file' => UploadedFile::fake()->createWithContent('clip.mp4', 'test-video')], ['Accept' => 'application/json'])->assertConflict();
        $this->postJson('/api/agent/modules/consent', ['policy_version' => 1, 'accepted' => true])->assertOk();
        $this->post('/api/agent/videos', [...$data, 'file' => UploadedFile::fake()->createWithContent('clip.mp4', 'test-video')], ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/agent/videos', [...$data, 'file' => UploadedFile::fake()->createWithContent('clip.mp4', 'test-video')], ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseCount('video_captures', 1);
        $video = VideoCapture::first();
        $this->getJson('/api/agent/videos')->assertOk()->assertJsonCount(1, 'data');
        $this->get('/api/agent/videos/'.$video->id, ['Range' => 'bytes=0-3'])->assertStatus(206)->assertContent('test')->assertHeader('Content-Range', 'bytes 0-3/10');
        $this->get('/api/agent/videos/'.$video->id, ['Range' => 'bytes=100-200'])->assertStatus(416);
        $other = $this->enroll();
        $this->app['auth']->forgetGuards();
        $this->withToken($other['token'])->get('/api/agent/videos/'.$video->id, ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson('/api/agent/videos')->assertJsonCount(0, 'data');
        $this->app['auth']->forgetGuards();
        $this->withToken($login['token']);
        MonitoringPolicy::current()->update(['video_enabled' => false]);
        $this->post('/api/agent/videos', [...$data, 'file' => UploadedFile::fake()->createWithContent('clip.mp4', 'test-video')], ['Accept' => 'application/json'])->assertConflict();
    }

    public function test_video_inspector_rejects_audio_and_oversized_metadata(): void
    {
        $i = new VideoInspector;
        $valid = ['streams' => [['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 320, 'height' => 180]], 'format' => ['duration' => 10]];
        $this->assertSame(10.0, $i->validate($valid)['duration_seconds']);
        foreach ([[...$valid, 'streams' => [...$valid['streams'], ['codec_type' => 'audio']]], [...$valid, 'format' => ['duration' => 3600]]] as $bad) {
            try {
                $i->validate($bad);
                $this->fail('Invalid media accepted');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function test_anomaly_evidence_is_owned_reviewable_and_never_modifies_scores(): void
    {
        $login = $this->enroll();
        $this->withToken($login['token']);
        MonitoringPolicy::current()->update(['anomaly_enabled' => true]);
        $data = ['event_id' => (string) Str::uuid(), 'notice_version' => 1, 'started_at' => now()->subMinutes(10)->toIso8601String(), 'ended_at' => now()->toIso8601String(), 'interval_count' => 120, 'regular_count' => 115, 'period_ms' => 5000];
        $this->postJson('/api/agent/anomalies', $data)->assertConflict();
        $this->postJson('/api/agent/modules/consent', ['policy_version' => 1, 'accepted' => true])->assertOk();
        $this->postJson('/api/agent/anomalies', [...$data, 'regular_count' => 10])->assertUnprocessable();
        $this->postJson('/api/agent/anomalies', $data)->assertOk();
        $this->postJson('/api/agent/anomalies', $data)->assertOk();
        $this->assertDatabaseCount('activity_anomalies', 1);
        $this->assertDatabaseCount('performance_reviews', 0);
        $this->assertDatabaseCount('performance_tasks', 0);
        $id = DB::table('activity_anomalies')->value('id');
        $this->postJson('/api/agent/anomalies/'.$id.'/context', ['revision' => 0, 'note' => 'I used an approved accessibility tool.'])->assertOk();
        $other = $this->enroll();
        $this->withToken($other['token'])->getJson('/api/agent/anomalies')->assertJsonCount(0, 'data');
        $this->postJson('/api/agent/anomalies/'.$id.'/context', ['revision' => 1, 'note' => 'Another employee comment'])->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'reviewer']), 'web')->getJson('/api/admin/anomalies')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']), 'web');
        $this->getJson('/api/admin/anomalies')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/admin/anomalies/'.$id.'/review', ['revision' => 0, 'note' => 'Reviewed the employee explanation.', 'status' => 'explained'])->assertConflict();
        $this->postJson('/api/admin/anomalies/'.$id.'/review', ['revision' => 1, 'note' => 'Approved accessibility tool confirmed.', 'status' => 'explained'])->assertOk();
        $this->assertDatabaseHas('activity_anomalies', ['id' => $id, 'status' => 'explained']);
        $this->assertDatabaseCount('performance_reviews', 0);
    }
}
