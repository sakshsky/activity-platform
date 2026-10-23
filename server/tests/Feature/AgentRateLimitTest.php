<?php

namespace Tests\Feature;

use App\Models\MonitoringPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_does_not_exhaust_video_consent_or_heartbeat_limits(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $login = $this->postJson('/api/agent/login', ['email' => $user->email, 'password' => 'password', 'device_identifier' => (string) Str::uuid(), 'device_name' => 'Test', 'os' => 'windows', 'notice_accepted' => true])->assertOk()->json();
        $this->withToken($login['token']);
        MonitoringPolicy::current()->update(['video_enabled' => true]);
        // Even rejected activity requests consume rate-limit attempts.
        for ($i = 0; $i < 35; $i++) {
            $this->postJson('/api/agent/activity', [])->assertUnprocessable();
        }
        $this->postJson('/api/agent/modules/consent', ['policy_version' => 1, 'accepted' => true])->assertOk();
        $this->postJson('/api/agent/videos', [])->assertUnprocessable();
        for ($i = 1; $i < 10; $i++) {
            $this->postJson('/api/agent/modules/consent', ['policy_version' => 1, 'accepted' => true])->assertOk();
        }
        $this->postJson('/api/agent/modules/consent', ['policy_version' => 1, 'accepted' => true])->assertStatus(429)->assertHeader('Retry-After');
        $this->postJson('/api/agent/heartbeat')->assertOk();
        $this->postJson('/api/agent/videos', [])->assertUnprocessable();
    }
}
