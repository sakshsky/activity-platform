<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\Performance;
use Carbon\CarbonImmutable;
use Database\Seeders\AppRuleSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PerformanceController extends Controller
{
    private function week(Request $request): CarbonImmutable
    {
        $request->validate(['week' => 'sometimes|required|date_format:Y-m-d|after_or_equal:2020-01-01|before_or_equal:today']);
        $week = $request->filled('week') ? CarbonImmutable::parse($request->input('week'), 'UTC') : CarbonImmutable::now('UTC')->startOfWeek();
        abort_unless($week->dayOfWeekIso === 1, 422, 'Choose a Monday for the reporting week.');

        return $week->startOfDay();
    }

    private function employee(string $id): User
    {
        return User::where('role', 'employee')->findOrFail($id);
    }

    private function lockAdmin(Request $request, string $employeeId): User
    {
        $admin = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        abort_unless($admin->enabled && $admin->role === 'super_admin', 403);

        return User::whereKey($employeeId)->where('role', 'employee')->lockForUpdate()->firstOrFail();
    }

    public function employeeReport(Request $request): JsonResponse
    {
        $report = Performance::report($request->user(), $this->week($request));
        $report['open_tasks'] = DB::table('performance_tasks')->where('user_id', $request->user()->id)->whereNotIn('status', ['accepted', 'cancelled'])->orderBy('due_date')->orderBy('id')->limit(50)->get();
        $report['open_count'] = DB::table('performance_tasks')->where('user_id', $request->user()->id)->whereNotIn('status', ['accepted', 'cancelled'])->count();
        Audit::record($request, 'performance.self_viewed', 'user', $request->user()->id);

        return response()->json($report, 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function adminReport(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|uuid']);
        $employee = $this->employee($request->input('user_id'));
        Audit::record($request, 'performance.viewed', 'user', $employee->id);

        return response()->json(Performance::report($employee, $this->week($request)), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function createTask(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|uuid', 'kind' => 'required|in:task,goal', 'title' => 'required|string|max:180', 'criteria' => 'required|string|min:10|max:2000', 'due_date' => 'required|date_format:Y-m-d|after_or_equal:today', 'points' => 'required|integer|min:1|max:10']);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($request, $data, $id): void {
            $this->lockAdmin($request, $data['user_id']);
            $week = CarbonImmutable::parse($data['due_date'], 'UTC')->startOfWeek();
            abort_if(DB::table('performance_tasks')->where('user_id', $data['user_id'])->whereBetween('due_date', [$week->toDateString(), $week->addDays(6)->toDateString()])->count() >= 100, 422, 'A reporting week supports up to 100 tasks.');
            DB::table('performance_tasks')->insert([...$data, 'id' => $id, 'assigned_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($request, 'task.assigned', 'task', $id);
        });

        return response()->json(['id' => $id], 201);
    }

    public function submitTask(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:0', 'evidence' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($request, $id, $data): void {
            app(AgentController::class)->lockedDevice($request);
            $task = DB::table('performance_tasks')->where('id', $id)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($task, 404);
            abort_unless($task->revision === $data['revision'], 409, 'Task changed. Refresh before submitting.');
            abort_unless(in_array($task->status, ['assigned', 'changes_requested']), 409, 'Only open tasks can be submitted.');
            DB::table('performance_tasks')->where('id', $id)->update(['evidence' => $data['evidence'], 'status' => 'submitted', 'submitted_at' => now(), 'revision' => $task->revision + 1, 'updated_at' => now()]);
            Audit::record($request, 'task.submitted', 'task', $id);
        });

        return response()->json(['success' => true]);
    }

    public function reviewTask(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:0', 'status' => 'required|in:accepted,changes_requested,cancelled', 'quality' => 'required_if:status,accepted|nullable|integer|min:0|max:100', 'review_note' => 'required|string|min:10|max:2000']);
        $owner = DB::table('performance_tasks')->where('id', $id)->value('user_id');
        abort_unless($owner, 404);
        DB::transaction(function () use ($request, $id, $data, $owner): void {
            $this->lockAdmin($request, $owner);
            $task = DB::table('performance_tasks')->where('id', $id)->lockForUpdate()->first();
            abort_unless($task->revision === $data['revision'], 409, 'Task changed. Refresh before reviewing.');
            abort_if(in_array($task->status, ['accepted', 'cancelled']), 409, 'This task is already final.');
            abort_unless($data['status'] === 'cancelled' || $task->status === 'submitted', 409, 'Review requires an employee submission.');
            DB::table('performance_tasks')->where('id', $id)->update(['status' => $data['status'], 'quality' => $data['status'] === 'accepted' ? $data['quality'] : null, 'review_note' => $data['review_note'], 'reviewed_by' => $request->user()->id, 'accepted_at' => $data['status'] === 'accepted' ? now() : null, 'revision' => $task->revision + 1, 'updated_at' => now()]);
            Audit::record($request, 'task.reviewed', 'task', $id, ['status' => $data['status']]);
        });

        return response()->json(['success' => true]);
    }

    public function explain(Request $request): JsonResponse
    {
        $data = $request->validate(['work_date' => 'required|date_format:Y-m-d|before_or_equal:today|after_or_equal:'.now('UTC')->subDays(90)->toDateString(), 'kind' => 'required|in:meeting,research,offline,context', 'minutes' => 'required|integer|min:1|max:1440', 'note' => 'required|string|min:10|max:1000']);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($request, $data, $id): void {
            app(AgentController::class)->lockedDevice($request);
            $week = CarbonImmutable::parse($data['work_date'], 'UTC')->startOfWeek();
            abort_if(DB::table('work_explanations')->where('user_id', $request->user()->id)->whereBetween('work_date', [$week->toDateString(), $week->addDays(6)->toDateString()])->count() >= 100, 422, 'A week supports up to 100 context notes.');
            DB::table('work_explanations')->insert([...$data, 'id' => $id, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($request, 'work.context_added', 'explanation', $id);
        });

        return response()->json(['id' => $id], 201);
    }

    public function publish(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|uuid', 'week' => 'required|date_format:Y-m-d', 'fingerprint' => 'required|string|size:64', 'collaboration' => 'required|integer|min:0|max:100', 'manager_note' => 'required|string|min:20|max:2000', 'confirmed' => 'required|accepted']);
        $week = $this->week($request);
        DB::transaction(function () use ($request, $data, $week): void {
            $employee = $this->lockAdmin($request, $data['user_id']);
            $report = Performance::report($employee, $week);
            abort_if($report['published'], 409, 'This week already has an immutable published review.');
            abort_unless($report['eligible'], 422, implode(' ', $report['reasons']));
            abort_unless(hash_equals($report['fingerprint'], $data['fingerprint']), 409, 'The report changed. Refresh and review it again before publishing.');
            $id = (string) Str::uuid();
            DB::table('performance_reviews')->insert(['id' => $id, 'user_id' => $employee->id, 'week' => $week->toDateString(), 'cohort_key' => $report['cohort']['key'], 'cohort_label' => $report['cohort']['label'], 'score' => Performance::score($report['metrics'], $data['collaboration']), 'collaboration' => $data['collaboration'], 'manager_note' => $data['manager_note'], 'snapshot' => json_encode($report, JSON_THROW_ON_ERROR), 'reviewed_by' => $request->user()->id, 'published_at' => now()]);
            Audit::record($request, 'performance.published', 'review', $id);
        });

        return response()->json(['success' => true], 201);
    }

    public function rankings(Request $request): JsonResponse
    {
        $week = $this->week($request)->toDateString();
        $reviews = DB::table('performance_reviews')->join('users', 'users.id', '=', 'performance_reviews.user_id')->where('week', $week)->select('performance_reviews.user_id', 'performance_reviews.week', 'performance_reviews.cohort_key', 'performance_reviews.cohort_label', 'performance_reviews.score', 'performance_reviews.published_at', 'users.name')->orderBy('cohort_label')->orderByDesc('score')->orderBy('name')->paginate(50);
        Audit::record($request, 'performance.rankings_viewed');

        return response()->json($reviews->through(fn ($row) => ['name' => $row->name, 'user_id' => $row->user_id, 'cohort' => $row->cohort_label, 'score' => $row->score, 'rank' => Performance::rank($row), 'published_at' => $row->published_at]));
    }

    public function appRule(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|uuid', 'app_name' => 'required|string|max:160', 'category' => 'required|in:work_related,neutral,review_context,unclassified', 'reason' => 'required|string|min:10|max:500']);
        DB::transaction(function () use ($request, $data): void {
            $this->lockAdmin($request, $data['user_id']);
            $cohort = Performance::cohort($data['user_id']);
            abort_unless($cohort['key'], 422, 'Set department and job title first.');
            DB::table('role_app_rules')->updateOrInsert(['cohort_key' => $cohort['key'], 'app_name' => mb_strtolower(trim($data['app_name']))], ['category' => $data['category'], 'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($request, 'performance.app_classified', 'user', $data['user_id']);
        });

        return response()->json(['success' => true]);
    }

    public function domainRule(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|uuid', 'domain' => 'required|string|max:253|regex:/^[a-zA-Z0-9.-]+$/', 'category' => 'required|in:work_related,neutral,review_context,unclassified', 'reason' => 'required|string|min:10|max:500']);
        abort_unless(filter_var($data['domain'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME), 422, 'Enter an exact hostname without a path.');
        DB::transaction(function () use ($request, $data) {
            $this->lockAdmin($request, $data['user_id']);
            $key = Performance::cohort($data['user_id'])['key'];
            abort_unless($key, 422, 'Set department and job title first.');
            DB::table('role_domain_rules')->updateOrInsert(['cohort_key' => $key, 'domain' => strtolower(rtrim($data['domain'], '.'))], ['category' => $data['category'], 'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($request, 'performance.domain_classified', 'user', $data['user_id']);
        });

        return response()->json(['success' => true]);
    }

    public function appDefaults(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|uuid', 'family' => 'required|in:general,engineering,office,design']);
        DB::transaction(function () use ($request, $data) {
            $this->lockAdmin($request, $data['user_id']);
            $key = Performance::cohort($data['user_id'])['key'];
            abort_unless($key, 422, 'Set department and job title first.');
            $templates = AppRuleSeeder::templates();
            foreach (array_replace($templates['general'], $templates[$data['family']]) as $app => $category) {
                DB::table('role_app_rules')->insertOrIgnore(['cohort_key' => $key, 'app_name' => $app, 'category' => $category, 'reason' => 'Administrator applied '.$data['family'].' defaults. Review with employee context.', 'created_at' => now(), 'updated_at' => now()]);
            }
            Audit::record($request, 'performance.defaults_applied', 'user', $data['user_id'], ['family' => $data['family']]);
        });

        return response()->json(['success' => true]);
    }

    public function applyPreset(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|uuid', 'preset' => 'required|in:agency,devshop,office']);
        $added = DB::transaction(function () use ($request, $data) {
            $this->lockAdmin($request, $data['user_id']);
            $key = Performance::cohort($data['user_id'])['key'];
            abort_unless($key, 422, 'Set department and job title first.');
            $added = 0;
            foreach (AppRuleSeeder::templates()[$data['preset']] as $app => $category) {
                $added += DB::table('role_app_rules')->insertOrIgnore(['cohort_key' => $key, 'app_name' => $app, 'category' => $category, 'reason' => 'Administrator applied '.$data['preset'].' preset. Review with employee context.', 'created_at' => now(), 'updated_at' => now()]);
            }
            Audit::record($request, 'performance.preset_applied', 'user', $data['user_id'], ['preset' => $data['preset'], 'rules_added' => $added]);

            return $added;
        });

        return response()->json(['success' => true, 'rules_added' => $added]);
    }
}
