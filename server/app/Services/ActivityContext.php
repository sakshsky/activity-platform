<?php

namespace App\Services;

use App\Models\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ActivityContext
{
    public static function workAppPercent(array $daily): ?float
    {
        $work = 0;
        $categorized = 0;
        foreach ($daily as $day) {
            $work += (int) ($day['work_related'] ?? 0);
            $categorized += (int) ($day['work_related'] ?? 0) + (int) ($day['neutral'] ?? 0) + (int) ($day['review_context'] ?? 0);
        }

        return $categorized > 0 ? round(100 * $work / $categorized, 2) : null;
    }

    public static function domain(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $host = parse_url(preg_match('~^https?://~i', $url) ? $url : 'https://'.$url, PHP_URL_HOST);

        return $host ? strtolower(rtrim($host, '.')) : null;
    }

    public static function daily(string $userId, string $timezone, ?CarbonImmutable $anchor = null, int $count = 30): array
    {
        $today = ($anchor ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfDay();
        $from = $today->subDays($count - 1);
        $end = $today->addDay();
        $key = Performance::cohort($userId)['key'] ?? '';
        $apps = DB::table('role_app_rules')->where('cohort_key', $key)->get()->keyBy('app_name');
        $domains = DB::table('role_domain_rules')->where('cohort_key', $key)->get()->keyBy('domain');
        $events = [];
        $legacy = 0;
        $observed = 0;
        $switches = 0;
        $domainCounts = [];
        $rows = ActivityLog::whereHas('device', fn ($q) => $q->where('user_id', $userId))->where('captured_at', '>', $from->utc())->where('captured_at', '<', $end->utc()->addSeconds(600))->orderBy('captured_at')->orderBy('id')->cursor();
        foreach ($rows as $row) {
            $at = $row->captured_at->timestamp;
            if ($at > CarbonImmutable::now()->timestamp) {
                continue;
            }
            $domain = self::domain($row->url);
            if ($domain) {
                $domainCounts[$domain] = ($domainCounts[$domain] ?? 0) + 1;
            }
            if ($row->duration_seconds === null) {
                $legacy++;

                continue;
            }
            $observed++;
            $switches += (int) $row->app_switches;
            $rule = $domain ? $domains->get($domain) : null;
            $rule ??= $apps->get(mb_strtolower($row->app_name));
            $category = $rule->category ?? 'unclassified';
            $start = max($from->timestamp, $at - min(600, (int) $row->duration_seconds));
            $at = min($at, $end->timestamp);
            if ($at > $start) {
                $events[$start][] = [$category, 1];
                $events[$at][] = [$category, -1];
            }
        }
        ksort($events, SORT_NUMERIC);
        $days = [];
        for ($d = $today; $d >= $from; $d = $d->subDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'work_related' => 0, 'neutral' => 0, 'review_context' => 0, 'unclassified' => 0];
        }
        $active = [];
        $previous = null;
        foreach ($events as $time => $changes) {
            if ($previous !== null && $time > $previous) {
                $categories = array_keys(array_filter($active, fn ($n) => $n > 0));
                if ($categories) {
                    $category = count($categories) === 1 ? $categories[0] : 'unclassified';
                    $cursor = CarbonImmutable::createFromTimestamp($previous, $timezone);
                    while ($cursor->timestamp < $time) {
                        $stop = min($time, $cursor->startOfDay()->addDay()->timestamp);
                        $date = $cursor->toDateString();
                        if (isset($days[$date])) {
                            $days[$date][$category] += $stop - $cursor->timestamp;
                        } $cursor = CarbonImmutable::createFromTimestamp($stop, $timezone);
                    }
                }
            }
            foreach ($changes as [$category,$delta]) {
                $active[$category] = ($active[$category] ?? 0) + $delta;
            } $previous = $time;
        }
        arsort($domainCounts);
        $domainRows = [];
        foreach (array_slice($domainCounts, 0, 20, true) as $domain => $samples) {
            $rule = $domains->get($domain);
            $domainRows[] = ['domain' => $domain, 'samples' => $samples, 'category' => $rule->category ?? 'unclassified', 'reason' => $rule->reason ?? ''];
        }

        return ['daily' => array_values($days), 'legacy_samples' => $legacy, 'observed_samples' => $observed, 'observed_app_switches' => $switches, 'domains' => $domainRows, 'description' => 'Estimated time between consecutive active observations in the same foreground window, capped at twice the sampling interval. Offline gaps and older samples have no duration. Overlapping devices are counted once; conflicting categories are unclassified. URL observations are sampled less often and exact-domain rules override app rules only when a URL was observed. No activity metric affects scores.', 'review_context' => $observed >= 120 && $switches === 0 ? 'No foreground-window changes observed. This can be normal focused work; it is not evidence of synthetic activity.' : null];
    }
}
