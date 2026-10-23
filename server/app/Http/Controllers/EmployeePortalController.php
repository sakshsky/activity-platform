<?php

namespace App\Http\Controllers;

use App\Models\MonitoringPolicy;
use App\Models\User;
use App\Services\ActivityContext;
use App\Services\Attendance;
use App\Services\Audit;
use App\Services\EmployeeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeePortalController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $request->validate(['timezone' => 'sometimes|timezone', 'page' => 'sometimes|integer|min:1']);
        $id = $request->user()->id;
        $profile = DB::table('employee_profiles')->where('user_id', $id)->first();
        $timezone = $profile->timezone ?? $request->input('timezone', 'UTC');
        $sessions = DB::table('employee_sessions')->where('user_id', $id)->orderByDesc('id')->paginate(15, ['id', 'device_name', 'kind', 'started_at', 'last_seen_at', 'ended_at', 'end_reason']);
        $sessions->getCollection()->transform(function ($session) {
            foreach (['started_at', 'last_seen_at', 'ended_at'] as $field) {
                $session->$field = $session->$field ? CarbonImmutable::parse($session->$field, 'UTC')->toIso8601String() : null;
            }

            return $session;
        });

        return response()->json(['profile' => EmployeeRecord::present($request->user(), $timezone), 'daily' => Attendance::daily($id, $timezone), 'application_context' => ActivityContext::daily($id, $timezone), 'sessions' => $sessions, 'leaves' => DB::table('leave_requests')->where('user_id', $id)->orderByDesc('created_at')->limit(50)->get(), 'pending_leave_count' => DB::table('leave_requests')->where('user_id', $id)->where('status', 'pending')->count(), 'idle_alert' => ($request->attributes->get('device')->last_seen_at?->greaterThan(now()->subSeconds(60)) && MonitoringPolicy::current()->idle_alert_minutes > 0 && $request->attributes->get('device')->idle_seconds >= MonitoringPolicy::current()->idle_alert_minutes * 60), 'as_of' => now()->toIso8601String()]);
    }

    public function profile(Request $request): JsonResponse
    {
        $rules = ['phone' => 'nullable|string|max:40', 'location' => 'nullable|string|max:120', 'timezone' => 'required|timezone', 'personal' => 'sometimes|array', 'expected_revision' => 'sometimes|integer|min:0'];
        foreach (EmployeeRecord::personalRules() as $key => $rule) {
            $rules['personal.'.$key] = $rule;
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($request, $data): void {
            app(AgentController::class)->lockedDevice($request);
            $id = $request->user()->id;
            $existing = DB::table('employee_profiles')->where('user_id', $id)->first();
            abort_if(($existing->profile_revision ?? 0) > 0 && ! array_key_exists('expected_revision', $data), 409, 'Reload saved details before editing. A current revision is required.');
            if (array_key_exists('expected_revision', $data)) {
                abort_unless(($existing->profile_revision ?? 0) === $data['expected_revision'], 409, 'This record changed. Reload saved details before editing.');
                unset($data['expected_revision']);
            }
            if (isset($data['personal'])) {
                $old = $existing?->personal_details ? json_decode(Crypt::decryptString($existing->personal_details), true, 512, JSON_THROW_ON_ERROR) : [];
                $data['personal_details'] = Crypt::encryptString(json_encode(array_replace($old, array_intersect_key($data['personal'], EmployeeRecord::personalRules())), JSON_THROW_ON_ERROR));
                unset($data['personal']);
            }
            $data['reviewed_at'] = null;
            $data['reviewed_by'] = null;
            $data['profile_revision'] = ($existing->profile_revision ?? 0) + 1;
            DB::table('employee_profiles')->updateOrInsert(['user_id' => $id], [...$data, 'phone' => $data['phone'] ?? '', 'location' => $data['location'] ?? '', 'created_at' => $existing->created_at ?? now(), 'updated_at' => now()]);
        });
        Audit::record($request, 'employee.profile_updated', 'user', $request->user()->id);

        return response()->json(['success' => true]);
    }

    public function requestLeave(Request $request): JsonResponse
    {
        $data = $request->validate(['start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date', 'type' => ['required', Rule::in(['annual', 'sick', 'personal', 'unpaid'])], 'reason' => 'required|string|min:3|max:2000']);
        abort_if(CarbonImmutable::parse($data['start_date'])->diffInDays(CarbonImmutable::parse($data['end_date'])) > 365, 422, 'A request cannot exceed 366 calendar days.');
        $id = (string) Str::uuid();
        DB::transaction(function () use ($request, $data, $id): void {
            app(AgentController::class)->lockedDevice($request);
            $overlap = DB::table('leave_requests')->where('user_id', $request->user()->id)->whereIn('status', ['pending', 'approved'])->where('start_date', '<=', $data['end_date'])->where('end_date', '>=', $data['start_date'])->exists();
            abort_if($overlap, 422, 'These dates overlap an existing pending or approved request.');
            DB::table('leave_requests')->insert([...$data, 'id' => $id, 'user_id' => $request->user()->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        });
        Audit::record($request, 'leave.requested', 'leave', $id);

        return response()->json(['id' => $id], 201);
    }

    public function cancelLeave(Request $request, string $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id): void {
            app(AgentController::class)->lockedDevice($request);
            $leave = DB::table('leave_requests')->where('id', $id)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($leave, 404);
            abort_unless($leave->status === 'pending', 409, 'Only pending requests can be cancelled.');
            DB::table('leave_requests')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        });
        Audit::record($request, 'leave.cancelled', 'leave', $id);

        return response()->json(['success' => true]);
    }

    public function leaves(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])]]);
        Audit::record($request, 'leave.list');

        return response()->json(DB::table('leave_requests')->join('users', 'users.id', '=', 'leave_requests.user_id')->select('leave_requests.*', 'users.name', 'users.email')->when($request->filled('status'), fn ($query) => $query->where('leave_requests.status', $request->input('status')))->orderByDesc('leave_requests.created_at')->paginate(20));
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected'])], 'review_note' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($request, $id, $data): void {
            $admin = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($admin->enabled && $admin->role === 'super_admin', 403);
            $leave = DB::table('leave_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($leave, 404);
            abort_unless($leave->status === 'pending', 409, 'This request has already been resolved.');
            DB::table('leave_requests')->where('id', $id)->update([...$data, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        });
        Audit::record($request, 'leave.'.$data['status'], 'leave', $id);

        return response()->json(['success' => true]);
    }
}
