<?php

namespace App\Console\Commands;

use App\Mail\WeeklySummaryMail;
use App\Models\ActivityLog;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\Attendance;
use App\Services\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Output\OutputInterface;

class WeeklySummary extends Command
{
    protected $signature = 'tracker:weekly-summary {--dry-run : Print the summary without sending or logging email}';

    protected $description = 'Send the previous UTC week summary to enabled, opted-in super admins';

    public const DISCLOSURE = 'This summary is informational. Scores are published only after manager review. No automatic misconduct verdicts are made.';

    public function handle(): int
    {
        $end = CarbonImmutable::now('UTC')->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $start = $end->subWeek();
        $label = $start->toDateString().' – '.$end->subDay()->toDateString().' UTC';
        $recipients = User::where('role', 'super_admin')->where('enabled', true)->where('weekly_summary_enabled', true)->orderBy('id')->get();
        if (! $this->option('dry-run') && $recipients->isEmpty()) {
            $this->info('No opted-in super admins; no email sent.');

            return self::SUCCESS;
        }
        $body = $this->summary($start, $end);
        if ($this->option('dry-run')) {
            $this->line('DRY RUN — no emails sent. Eligible recipients: '.$recipients->count());
            $this->output->writeln($body, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        $failed = 0;
        $processed = 0;
        foreach ($recipients as $recipient) {
            // Recheck opt-in and role immediately before handing mail to the configured transport.
            $recipient = User::whereKey($recipient->id)->where('role', 'super_admin')->where('enabled', true)->where('weekly_summary_enabled', true)->first();
            if (! $recipient) {
                continue;
            }
            try {
                Mail::to($recipient)->send(new WeeklySummaryMail($body, $label));
                $processed++;
                Audit::record(null, 'weekly_summary.processed', 'user', $recipient->id, ['week' => $start->toDateString(), 'mailer' => config('mail.default')]);
            } catch (\Throwable $error) {
                report($error);
                $failed++;
            }
        }
        $this->info('Summaries processed: '.$processed.'; failures: '.$failed.'. Mailer: '.config('mail.default').'.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    public function summary(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $clean = static fn (string $text): string => preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text);
        $hours = [];
        foreach (User::where('role', 'employee')->orderBy('id')->cursor() as $employee) {
            $seconds = array_sum(array_column(Attendance::daily($employee->id, 'UTC', $end->subDay(), 7), 'active_seconds'));
            $hours[] = ['id' => $employee->id, 'name' => $clean($employee->name), 'seconds' => $seconds];
        }
        usort($hours, fn ($a, $b) => ($b['seconds'] <=> $a['seconds']) ?: strcmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));
        $lines = ['Activity weekly summary', $start->toDateString().' through '.$end->subDay()->toDateString().' (UTC)', '', 'Total tracked active hours: '.number_format(array_sum(array_column($hours, 'seconds')) / 3600, 2, '.', ''), '', 'Top 5 employees by tracked active hours (activity context only):'];
        $top = array_slice(array_values(array_filter($hours, fn ($row) => $row['seconds'] > 0)), 0, 5);
        foreach ($top as $row) {
            $lines[] = '- '.$row['name'].': '.number_format($row['seconds'] / 3600, 2, '.', '').' hours';
        }
        if (! $top) {
            $lines[] = '- No active hours retained for this week.';
        }
        $lines[] = '';
        $lines[] = 'Top 5 foreground applications by sample count:';
        $apps = ActivityLog::where('captured_at', '>=', $start)->where('captured_at', '<', $end)->selectRaw('app_name, COUNT(*) AS samples')->groupBy('app_name')->orderByDesc('samples')->orderBy('app_name')->limit(5)->get();
        foreach ($apps as $app) {
            $lines[] = '- '.$clean($app->app_name ?: 'Unknown').': '.$app->samples.' samples';
        }
        if ($apps->isEmpty()) {
            $lines[] = '- No retained samples for this week.';
        }
        $lines[] = '';
        $lines[] = 'Screenshots captured this week: '.Screenshot::where('captured_at', '>=', $start)->where('captured_at', '<', $end)->count();
        $deletions = DB::table('deletion_events')->where('deleted_at', '>=', $start)->where('deleted_at', '<', $end)->selectRaw('user_id, employee_name, COUNT(*) AS captures')->groupBy('user_id', 'employee_name')->orderBy('employee_name')->get();
        $lines[] = 'Neutral deletions this week: '.$deletions->sum('captures');
        foreach ($deletions as $row) {
            $lines[] = '- '.$clean($row->employee_name).': '.$row->captures;
        }
        $lines[] = 'Activity pattern flags pending review (current): '.DB::table('activity_anomalies')->where('status', 'pending')->count();
        $lines[] = 'Leave requests pending decision (current): '.DB::table('leave_requests')->where('status', 'pending')->count();
        $lines[] = '';
        $lines[] = 'Activity totals are estimates from retained records, not productivity measures. Pending counts are current at generation time. No screenshot content, URLs or deletion reasons are included.';
        $lines[] = 'Manage your opt-in under Policy → Weekly summary email.';
        $lines[] = '';
        $lines[] = self::DISCLOSURE;

        return implode("\n",$lines);
    }
}
