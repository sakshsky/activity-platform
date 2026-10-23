<?php

namespace App\Http\Controllers;

use App\Models\MonitoringPolicy;
use App\Models\User;
use App\Models\VideoCapture;
use App\Services\Audit;
use App\Services\Vault;
use App\Services\VideoInspector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OptionalModulesController extends Controller
{
    public static function allowed($device, MonitoringPolicy $policy, string $module): bool
    {
        return $policy->monitoring_enabled && ! $device->paused && $policy->$module && (int) $device->modules_notice_version === (int) $policy->version;
    }

    public function consent(Request $request)
    {
        $data = $request->validate(['policy_version' => 'required|integer', 'accepted' => 'required|accepted']);
        DB::transaction(function () use ($request, $data) {
            $device = app(AgentController::class)->lockedDevice($request);
            $policy = MonitoringPolicy::whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless((int) $policy->version === $data['policy_version'], 409, 'Policy changed. Read the current notice again.');
            $device->update(['modules_notice_version' => $policy->version, 'modules_accepted_at' => now()]);
            Audit::record($request, 'modules.notice_accepted', 'device', $device->id, ['version' => $policy->version]);
        });

        return response()->json(['success' => true]);
    }

    public function upload(Request $request, Vault $vault, VideoInspector $inspector)
    {
        $data = $request->validate(['event_id' => 'required|uuid', 'captured_at' => 'required|date|before_or_equal:'.now()->addMinutes(5)->toIso8601String(), 'notice_version' => 'required|integer', 'file' => 'required|file|max:20480']);
        $device = $request->attributes->get('device');
        $policy = MonitoringPolicy::whereKey(1)->lockForUpdate()->firstOrFail();
        abort_unless(self::allowed($device, $policy, 'video_enabled'), 409, 'Video recording is disabled or needs acknowledgment.');
        $captured = CarbonImmutable::parse($data['captured_at'])->utc();
        abort_if($captured->lessThan(now()->subDays($policy->video_retention_days)), 422, 'Clip is outside video retention.');
        $file = $request->file('file');
        $info = $inspector->inspect($file->getRealPath());
        $sha = hash_file('sha256', $file->getRealPath());
        $video = DB::transaction(function () use ($request, $data, $captured, $file, $info, $sha, $vault) {
            $device = app(AgentController::class)->lockedDevice($request);
            $policy = MonitoringPolicy::whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless(self::allowed($device, $policy, 'video_enabled') && (int) $data['notice_version'] === (int) $policy->version, 409, 'Recording policy or acknowledgment changed.');
            abort_if($captured->lessThan(now()->subDays($policy->video_retention_days)), 422, 'Clip expired.');
            abort_if($info['duration_seconds'] > $policy->video_clip_seconds + 1, 422, 'Clip exceeds the configured duration.');
            $existing = VideoCapture::where('device_id', $device->id)->where('event_id', $data['event_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->sha256, $sha), 409, 'Event already contains a different clip.');

                return $existing;
            }
            $used = VideoCapture::whereHas('device', fn ($q) => $q->where('user_id', $device->user_id))->sum('bytes');
            abort_if($used + $file->getSize() > $policy->video_quota_mb * 1024 * 1024, 409, 'Employee video storage quota reached. Wait for retention cleanup.');
            $path = 'videos/'.$device->device_identifier.'/'.$data['event_id'].'.mp4';
            $vault->put($path, file_get_contents($file->getRealPath()));
            try {
                $record = VideoCapture::create([...$info, 'device_id' => $device->id, 'event_id' => $data['event_id'], 'captured_at' => $captured, 'path' => $path, 'storage' => config('tracker.storage'), 'bytes' => $file->getSize(), 'sha256' => $sha, 'notice_version' => $data['notice_version']]);
                Audit::record($request, 'video.received', 'video', $record->id);

                return $record;
            } catch (\Throwable $e) {
                $vault->delete($path);
                throw $e;
            }
        });

        return response()->json(['accepted' => true, 'id' => $video->id]);
    }

    public function videos(Request $request)
    {
        $request->validate(['user_id' => 'nullable|uuid', 'page' => 'sometimes|integer|min:1']);
        $query = VideoCapture::with('device.user')->orderByDesc('captured_at')->orderBy('id');
        $employee = $request->user()->role === 'employee';
        if ($employee) {
            $query->whereHas('device', fn ($q) => $q->where('user_id', $request->user()->id));
        } elseif ($request->filled('user_id')) {
            $query->whereHas('device', fn ($q) => $q->where('user_id', $request->input('user_id')));
        }
        Audit::record($request, 'videos.list');

        return response()->json($query->paginate(12)->through(fn ($v) => ['id' => $v->id, 'captured_at' => $v->captured_at, 'duration_seconds' => $v->duration_seconds, 'width' => $v->width, 'height' => $v->height, 'bytes' => $v->bytes, 'sha256' => $v->sha256, 'employee_name' => $v->device->user->name, 'device_name' => $v->device->name, 'file_url' => $employee ? '/local/my-video?id='.$v->id : route('video.file', $v->id, false)]));
    }

    public function video(Request $request, string $id, Vault $vault)
    {
        $video = VideoCapture::with('device')->findOrFail($id);
        if ($request->user()->role === 'employee') {
            abort_unless($video->device->user_id === $request->user()->id, 404);
        }
        try {
            $bytes = $vault->get($video->path, $video->storage);
        } catch (\Throwable) {
            abort(503, 'Video storage unavailable.');
        }Audit::record($request, 'video.viewed', 'video', $id);
        $length = strlen($bytes);
        $headers = ['Content-Type' => 'video/mp4', 'Accept-Ranges' => 'bytes', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($range = $request->header('Range')) {
            abort_unless(preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== ''), 416);
            $start = $m[1] === '' ? max(0, $length - (int) $m[2]) : (int) $m[1];
            $end = $m[1] === '' || $m[2] === '' ? $length - 1 : min((int) $m[2], $length - 1);
            abort_unless($start <= $end && $start < $length, 416);
            $headers['Content-Range'] = "bytes $start-$end/$length";

            return response(substr($bytes, $start, $end - $start + 1), 206, $headers);
        }

        return response($bytes, 200, $headers);
    }

    public function anomaly(Request $request)
    {
        $data = $request->validate(['event_id' => 'required|uuid', 'notice_version' => 'required|integer', 'started_at' => 'required|date', 'ended_at' => 'required|date|after:started_at|before_or_equal:'.now()->addMinutes(5)->toIso8601String(), 'interval_count' => 'required|integer|min:30|max:3600', 'regular_count' => 'required|integer|min:0|lte:interval_count', 'period_ms' => 'required|integer|min:2000|max:60000']);
        $start = CarbonImmutable::parse($data['started_at'])->utc();
        $end = CarbonImmutable::parse($data['ended_at'])->utc();
        $seconds = $start->diffInSeconds($end);
        abort_unless($seconds >= 600 && $seconds <= 1200 && $data['regular_count'] / $data['interval_count'] >= 0.9, 422, 'Evidence does not meet the disclosed regular-timing rule.');
        DB::transaction(function () use ($request, $data, $start, $end) {
            $device = app(AgentController::class)->lockedDevice($request);
            $policy = MonitoringPolicy::whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless(self::allowed($device, $policy, 'anomaly_enabled') && (int) $data['notice_version'] === (int) $policy->version, 409, 'Activity review is disabled or needs acknowledgment.');
            abort_if($start->lessThan(now()->subDays($policy->retention_days)), 422, 'Evidence expired.');
            if (DB::table('activity_anomalies')->where('device_id', $device->id)->where('event_id', $data['event_id'])->exists()) {
                return;
            }
            abort_if(DB::table('activity_anomalies')->where('device_id', $device->id)->where('started_at', '<', $end)->where('ended_at', '>', $start)->exists(), 409, 'An evidence window already overlaps this report.');
            $id = (string) Str::uuid();
            DB::table('activity_anomalies')->insert(['id' => $id, 'user_id' => $device->user_id, 'device_id' => $device->id, 'event_id' => $data['event_id'], 'started_at' => $start, 'ended_at' => $end, 'rule' => 'regular_input_timing_v1', 'evidence' => json_encode(['interval_count' => $data['interval_count'], 'regular_count' => $data['regular_count'], 'period_ms' => $data['period_ms'], 'threshold' => 'At least 30 observed intervals over 10 minutes; at least 90% within a 200 ms bucket, period 2–60 seconds.', 'limitations' => 'Rule-based, agent-reported observations. Sampling misses input events. Accessibility tools and legitimate automation can produce this pattern. This does not prove a mouse jiggler or misconduct.']), 'created_at' => now(), 'updated_at' => now()]);
            Audit::record($request, 'activity_review.created', 'anomaly', $id);
        });

        return response()->json(['accepted' => true]);
    }

    public function anomalies(Request $request)
    {
        $request->validate(['user_id' => 'nullable|uuid', 'status' => 'nullable|in:pending,explained,needs_discussion', 'page' => 'sometimes|integer|min:1']);
        $query = DB::table('activity_anomalies')->join('users', 'users.id', '=', 'activity_anomalies.user_id')->select('activity_anomalies.*', 'users.name as employee_name')->orderByDesc('started_at')->orderBy('activity_anomalies.id');
        if ($request->user()->role === 'employee') {
            $query->where('user_id', $request->user()->id);
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }Audit::record($request, 'activity_reviews.list');

        return response()->json($query->paginate(20)->through(fn ($r) => [...(array) $r, 'evidence' => json_decode($r->evidence, true)]));
    }

    public function annotate(Request $request, string $id)
    {
        $data = $request->validate(['revision' => 'required|integer|min:0', 'note' => 'required|string|min:10|max:2000', 'status' => 'sometimes|in:explained,needs_discussion']);
        DB::transaction(function () use ($request, $id, $data) {
            if ($request->user()->role === 'employee') {
                app(AgentController::class)->lockedDevice($request);
            } else {
                $admin = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_unless($admin->enabled && $admin->role === 'super_admin', 403);
            }$row = DB::table('activity_anomalies')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $employee = $request->user()->role === 'employee';
            if ($employee) {
                abort_unless($row->user_id === $request->user()->id, 404);
            }abort_unless($row->revision === $data['revision'], 409, 'This review changed. Refresh first.');
            $update = $employee ? ['employee_note' => $data['note']] : ['status' => $data['status'] ?? 'needs_discussion', 'review_note' => $data['note'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()];
            DB::table('activity_anomalies')->where('id', $id)->update([...$update, 'revision' => $row->revision + 1, 'updated_at' => now()]);
            Audit::record($request, $employee ? 'activity_review.employee_context' : 'activity_review.reviewed', 'anomaly', $id);
        });

        return response()->json(['success' => true]);
    }
}
