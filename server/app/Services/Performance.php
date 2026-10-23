<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Performance
{
    public static function rules(): array
    {
        return ['version' => 1, 'weights' => ['completion' => 40, 'quality' => 30, 'deadlines' => 20, 'collaboration' => 10], 'minimum_tasks' => 3, 'minimum_peers' => 2, 'timezone' => 'UTC', 'description' => 'Closed Monday–Sunday UTC weeks only, with a full reporting week since account creation. At least 3 non-cancelled tasks due in the week, at least one accepted result, and a manager review. Task points weight completion and deadlines; quality is the points-weighted average of accepted results. Timeliness uses employee submission time, not manager approval time. Work App % is informational only and does not affect the score. Unclassified time is excluded; no categorized data means no percentage. Activity, idle time, screenshots and application categories never affect the score. Rankings compare published reviews within the same department and job title. Tied scores share a rank.'];
    }

    public static function cohort(string $userId): array
    {
        $raw = DB::table('employee_profiles')->where('user_id', $userId)->value('employment_details');
        $details = $raw ? json_decode($raw, true) : [];
        $department = trim($details['department'] ?? '');
        $title = trim($details['job_title'] ?? '');

        return ['key' => $department && $title ? hash('sha256', json_encode([mb_strtolower($department), mb_strtolower($title)], JSON_THROW_ON_ERROR)) : null, 'label' => $department && $title ? $department.' / '.$title : 'Department and job title required'];
    }

    public static function report(User $user, CarbonImmutable $week): array
    {
        $end = $week->addDays(7);
        $tasks = DB::table('performance_tasks')->where('user_id', $user->id)->whereBetween('due_date', [$week->toDateString(), $end->subDay()->toDateString()])->orderBy('due_date')->orderBy('id')->get();
        $explanations = DB::table('work_explanations')->where('user_id', $user->id)->whereBetween('work_date', [$week->toDateString(), $end->subDay()->toDateString()])->orderBy('work_date')->orderBy('id')->get();
        $included = $tasks->where('status', '!=', 'cancelled');
        $accepted = $included->where('status', 'accepted');
        $points = $included->sum('points');
        $acceptedPoints = $accepted->sum('points');
        $onTime = $accepted->filter(fn ($task) => CarbonImmutable::parse($task->submitted_at, 'UTC')->toDateString() <= $task->due_date)->sum('points');
        $metrics = ['completion' => $points ? round(100 * $acceptedPoints / $points, 2) : null, 'quality' => $acceptedPoints ? round($accepted->sum(fn ($task) => (int) ($task->quality ?? 0) * $task->points) / $acceptedPoints, 2) : null, 'deadlines' => $points ? round(100 * $onTime / $points, 2) : null, 'task_count' => $included->count(), 'accepted_count' => $accepted->count()];
        $cohort = self::cohort($user->id);
        $reasons = [];
        if (! $user->created_at || CarbonImmutable::instance($user->created_at)->startOfDay()->greaterThan($week)) {
            $reasons[] = 'A full reporting week since account creation is required.';
        }
        if ($end->isFuture()) {
            $reasons[] = 'The week is still in progress.';
        }
        if ($included->count() < 3) {
            $reasons[] = 'At least three non-cancelled tasks due this week are required.';
        }
        if (! $acceptedPoints) {
            $reasons[] = 'At least one accepted result is required for quality evidence.';
        }
        if (! $cohort['key']) {
            $reasons[] = 'Set the employee department and job title in Personnel.';
        }
        $daily = Attendance::daily($user->id, 'UTC', $end->subDay(), 7);
        $categories = DB::table('role_app_rules')->where('cohort_key', $cohort['key'] ?? '')->get()->keyBy('app_name');
        $apps = ActivityLog::whereHas('device', fn ($query) => $query->where('user_id', $user->id))->where('captured_at', '>=', $week)->where('captured_at', '<', $end)->selectRaw('app_name, COUNT(*) AS samples')->groupBy('app_name')->orderByDesc('samples')->orderBy('app_name')->limit(20)->get()->map(function ($app) use ($categories) {
            $rule = $categories->get(mb_strtolower($app->app_name));

            return ['app_name' => $app->app_name, 'samples' => $app->samples, 'category' => $rule->category ?? 'unclassified', 'reason' => $rule->reason ?? ''];
        });
        $report = ['week' => $week->toDateString(), 'week_end' => $end->subDay()->toDateString(), 'user' => ['id' => $user->id, 'name' => $user->name], 'cohort' => $cohort, 'tasks' => $tasks, 'explanations' => $explanations, 'daily' => $daily, 'application_context' => ActivityContext::daily($user->id, 'UTC', $end->subDay(), 7), 'apps' => $apps, 'metrics' => $metrics, 'eligible' => ! $reasons, 'reasons' => $reasons, 'rules' => self::rules()];
        $report['work_app_percent'] = ActivityContext::workAppPercent($report['application_context']['daily']);
        $report['fingerprint'] = hash('sha256', json_encode($report, JSON_THROW_ON_ERROR));
        $published = DB::table('performance_reviews')->where('user_id', $user->id)->where('week', $week->toDateString())->first();
        $report['published'] = $published ? [...(array) $published, 'snapshot' => json_decode($published->snapshot, true), 'rank' => self::rank($published), 'peer_count' => DB::table('performance_reviews')->where('week', $published->week)->where('cohort_key', $published->cohort_key)->count()] : null;

        return $report;
    }

    public static function score(array $metrics, int $collaboration): float
    {
        $weighted = (int) round($metrics['completion'] * 100) * 40 + (int) round($metrics['quality'] * 100) * 30 + (int) round($metrics['deadlines'] * 100) * 20 + $collaboration * 1000;

        return floor(($weighted + 50) / 100) / 100;
    }

    public static function rank(object $review): ?int
    {
        $query = DB::table('performance_reviews')->where('week', $review->week)->where('cohort_key', $review->cohort_key);
        if ((clone $query)->count() < 2) {
            return null;
        }

        return 1 + $query->where('score', '>', $review->score)->count();
    }
}
