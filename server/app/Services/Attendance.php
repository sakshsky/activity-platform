<?php

namespace App\Services;

use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Attendance
{
    public static function begin(Device $device, bool $eligible, string $kind = 'login'): void
    {
        DB::table('employee_sessions')->where('device_id', $device->id)->whereNull('ended_at')->update(['ended_at' => DB::raw('last_seen_at'), 'end_reason' => 'replaced', 'eligible' => false]);
        DB::table('employee_sessions')->insert(['user_id' => $device->user_id, 'device_id' => $device->id, 'token_id' => $device->token_id, 'device_name' => $device->name, 'kind' => $kind, 'started_at' => now(), 'last_seen_at' => now(), 'eligible' => $eligible]);
    }

    /** Called inside the transaction holding the device lock; timestamps come only from the server. */
    public static function beat(Device $device, bool $eligible, string $state = 'legacy'): void
    {
        $session = DB::table('employee_sessions')->where('token_id', $device->token_id)->whereNull('ended_at')->lockForUpdate()->first();
        if (! $session) {
            self::begin($device, $eligible, 'restored');

            return;
        }
        $now = CarbonImmutable::now()->startOfSecond();
        $previous = CarbonImmutable::parse($session->last_seen_at, 'UTC');
        $gap = $previous->diffInSeconds($now, false);
        if ($eligible && $session->eligible && $state === $session->activity_state && $state !== 'unavailable' && $gap > 0 && $gap <= 60) {
            $last = DB::table('work_intervals')->where('employee_session_id', $session->id)->orderByDesc('id')->first();
            if ($last && $last->activity_state === $state && $last->ended_at === $session->last_seen_at) {
                DB::table('work_intervals')->where('id', $last->id)->update(['ended_at' => $now]);
            } else {
                DB::table('work_intervals')->insert(['employee_session_id' => $session->id, 'user_id' => $device->user_id, 'started_at' => $previous, 'ended_at' => $now, 'activity_state' => $state]);
            }
        }
        DB::table('employee_sessions')->where('id', $session->id)->update(['last_seen_at' => $now, 'eligible' => $eligible, 'activity_state' => $state]);
    }

    public static function close(Device $device, string $reason): void
    {
        DB::table('employee_sessions')->where('device_id', $device->id)->whereNull('ended_at')->update(['ended_at' => DB::raw('last_seen_at'), 'end_reason' => $reason, 'eligible' => false]);
    }

    public static function daily(string $userId, string $timezone, ?CarbonImmutable $anchor = null, int $dayCount = 30): array
    {
        $today = ($anchor ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfDay();
        $from = $today->subDays($dayCount - 1);
        $rows = DB::table('work_intervals')->where('user_id', $userId)->where('ended_at', '>', $from->utc())->where('started_at', '<', $today->addDay()->utc())->orderBy('started_at')->get();
        $days = [];
        for ($day = $today; $day >= $from; $day = $day->subDay()) {
            $ranges = ['active' => [], 'idle' => [], 'legacy' => []];
            foreach ($rows as $row) {
                $start = max(CarbonImmutable::parse($row->started_at, 'UTC')->timestamp, $day->timestamp);
                $end = min(CarbonImmutable::parse($row->ended_at, 'UTC')->timestamp, $day->addDay()->timestamp, CarbonImmutable::now()->timestamp);
                if ($end > $start && isset($ranges[$row->activity_state])) {
                    $ranges[$row->activity_state][] = [$start, $end];
                }
            }
            $active = self::unionSeconds($ranges['active']);
            $work = self::unionSeconds([...$ranges['active'], ...$ranges['legacy']]);
            $connected = self::unionSeconds([...$ranges['active'], ...$ranges['legacy'], ...$ranges['idle']]);
            $days[] = ['date' => $day->toDateString(), 'seconds' => $work, 'active_seconds' => $active, 'idle_seconds' => $connected - $work, 'legacy_seconds' => $work - $active];
        }

        return $days;
    }

    private static function unionSeconds(array $ranges): int
    {
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $seconds = 0;
        $until = 0;
        foreach ($ranges as [$start, $end]) {
            $seconds += max(0, $end - max($until, $start));
            $until = max($until, $end);
        }

        return $seconds;
    }
}
