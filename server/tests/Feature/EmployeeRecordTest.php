<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\EmployeeRecord;
use App\Services\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeRecordTest extends TestCase
{
    use RefreshDatabase;

    private function enroll(): array
    {
        $user = User::factory()->create(['role' => 'employee']);

        return $this->postJson('/api/agent/login', ['email' => $user->email, 'password' => 'password', 'device_identifier' => (string) Str::uuid(), 'device_name' => 'Test PC', 'os' => 'windows', 'notice_accepted' => true])->assertOk()->json();
    }

    public function test_personal_details_are_encrypted_and_unknown_fields_cannot_escalate_access(): void
    {
        $login = $this->enroll();
        $this->withToken($login['token'])->postJson('/api/agent/profile', ['timezone' => 'Asia/Kolkata', 'expected_revision' => 0, 'personal' => ['legal_name' => 'Sample Employee', 'date_of_birth' => '1993-04-05', 'emergency_name' => 'Emergency Person', 'current_address' => 'Private street address', 'role' => 'super_admin'], 'employment' => ['department' => 'Unapproved department']])->assertOk();
        $stored = DB::table('employee_profiles')->where('user_id', $login['user']['id'])->first();
        $this->assertStringNotContainsString('Private street address', $stored->personal_details);
        $this->assertNull($stored->employment_details);
        $response = $this->getJson('/api/agent/portal')->assertOk()->assertJsonPath('profile.personal.legal_name', 'Sample Employee')->assertJsonPath('profile.revision', 1);
        $this->assertArrayNotHasKey('role', $response->json('profile.personal'));
        $this->assertDatabaseHas('users', ['id' => $login['user']['id'], 'role' => 'employee']);
        $this->postJson('/api/agent/profile', ['timezone' => 'UTC', 'expected_revision' => 0, 'personal' => ['legal_name' => 'Overwrite']])->assertConflict();
        $this->postJson('/api/agent/profile', ['timezone' => 'UTC', 'phone' => '12345'])->assertConflict();
        $this->postJson('/api/agent/profile', ['timezone' => 'UTC', 'phone' => '12345', 'expected_revision' => 1])->assertOk();
        $this->getJson('/api/agent/portal')->assertJsonPath('profile.personal.current_address', 'Private street address');
    }

    public function test_profile_rejects_future_birth_date_and_invalid_contact_email(): void
    {
        $login = $this->enroll();
        $this->withToken($login['token'])->postJson('/api/agent/profile', ['timezone' => 'UTC', 'personal' => ['date_of_birth' => now()->addDay()->toDateString(), 'personal_email' => 'invalid']])->assertUnprocessable();
        $this->assertDatabaseCount('employee_profiles', 0);
    }

    public function test_personnel_review_requires_admin_and_current_revision(): void
    {
        $login = $this->enroll();
        $this->withToken($login['token'])->postJson('/api/agent/profile', ['timezone' => 'UTC', 'personal' => ['legal_name' => 'Employee']])->assertOk();
        $id = $login['user']['id'];
        $reviewer = User::factory()->create(['role' => 'reviewer']);
        $this->actingAs($reviewer, 'web')->getJson('/api/admin/personnel/'.$id)->assertForbidden();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'web')->getJson('/api/admin/personnel/'.$id)->assertOk()->assertJsonPath('profile.personal.legal_name', 'Employee');
        $this->putJson('/api/admin/personnel/'.$id, ['employment' => ['department' => 'Engineering'], 'mark_reviewed' => true, 'expected_revision' => 0])->assertConflict();
        $this->putJson('/api/admin/personnel/'.$id, ['employment' => ['department' => 'Engineering', 'job_title' => 'Developer', 'role' => 'super_admin'], 'mark_reviewed' => true, 'expected_revision' => 1])->assertOk()->assertJsonPath('profile.employment.department', 'Engineering')->assertJsonPath('profile.revision', 2);
        $this->assertNotNull(DB::table('employee_profiles')->where('user_id', $id)->value('reviewed_at'));
        $this->app['auth']->forgetGuards();
        $this->withToken($login['token'])->postJson('/api/agent/profile', ['timezone' => 'UTC', 'expected_revision' => 2, 'personal' => ['city' => 'Pune']])->assertOk();
        $this->assertNull(DB::table('employee_profiles')->where('user_id', $id)->value('reviewed_at'));
        $record = EmployeeRecord::present(User::findOrFail($id));
        $this->assertSame('Engineering', $record['employment']['department']);
        $this->assertArrayNotHasKey('role', $record['employment']);
    }

    public function test_my_data_and_image_endpoint_never_return_another_employees_records(): void
    {
        Storage::fake('local');
        $login = $this->enroll();
        $own = Screenshot::factory()->create(['device_id' => $login['device']['id']]);
        $other = Screenshot::factory()->create();
        ActivityLog::factory()->create(['device_id' => $login['device']['id'], 'url' => 'https://example.test/work']);
        ActivityLog::factory()->create();
        app(Vault::class)->put($own->path, 'synthetic-png-bytes', 'local');
        $this->withToken($login['token'])->getJson('/api/agent/my-data?kind=screenshots&user_id=other')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/agent/my-data?kind=activity')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.url', 'https://example.test/work');
        $this->get('/api/agent/my-screenshots/'.$own->id)->assertOk()->assertContent('synthetic-png-bytes')->assertHeader('Content-Type', 'image/png');
        $this->getJson('/api/agent/my-screenshots/'.$other->id)->assertNotFound();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'employee.screenshot_viewed', 'subject_id' => $other->id]);
        $own->delete();
        $this->getJson('/api/agent/my-screenshots/'.$own->id)->assertNotFound();
    }

    public function test_my_data_date_filters_use_reporting_timezone_and_support_to_only(): void
    {
        $login = $this->enroll();
        ActivityLog::factory()->create(['device_id' => $login['device']['id'], 'captured_at' => '2026-10-19 18:45:00']);
        ActivityLog::factory()->create(['device_id' => $login['device']['id'], 'captured_at' => '2026-10-19 17:45:00']);
        $this->withToken($login['token'])->getJson('/api/agent/my-data?kind=activity&from=2026-10-20&to=2026-10-20&timezone=Asia%2FKolkata')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/agent/my-data?kind=activity&to=2026-10-19&timezone=Asia%2FKolkata')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/agent/my-data?kind=activity&from=2026-10-20&to=2026-10-19')->assertUnprocessable();
        Device::whereKey($login['device']['id'])->update(['disabled_at' => now()]);
        $this->getJson('/api/agent/my-data?kind=activity')->assertForbidden();
    }
}
