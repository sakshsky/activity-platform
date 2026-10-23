<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_run_checks_without_exposing_settings(): void
    {
        $this->postJson('/api/admin/system-check')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'reviewer']))->postJson('/api/admin/system-check')->assertForbidden();
        config(['tracker.ffprobe_binary' => 'missing-ffprobe-test-command', 'tracker.ftps_password' => 'secret-test-value']);
        $response = $this->actingAs(User::factory()->create(['role' => 'super_admin']))->postJson('/api/admin/system-check')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $checks = collect($response->json('checks'))->keyBy('name');
        $this->assertSame('pass', $checks['Database connection']['status']);
        $this->assertSame('pass', $checks['Cache read/write']['status']);
        $this->assertSame('warning', $checks['FFprobe for optional videos']['status']);
        $this->assertSame('manual', $checks['Queue worker and scheduler']['status']);
        $this->assertStringNotContainsString('secret-test-value', $response->getContent());
        $this->assertStringNotContainsString('missing-ffprobe-test-command', $response->getContent());
    }
}
