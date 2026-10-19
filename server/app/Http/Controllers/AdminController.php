<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\MonitoringPolicy;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\Audit;
use App\Services\Vault;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

class AdminController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        Audit::record($request, 'overview.view');

        return response()->json(['devices' => Device::count(), 'online' => Device::whereNull('disabled_at')->where('last_seen_at', '>=', now()->subMinutes(2))->count(), 'employees' => User::where('role', 'employee')->where('enabled', true)->count(), 'screenshots_today' => Screenshot::where('captured_at', '>=', now()->startOfDay())->count(), 'recent_devices' => Device::with('user:id,name')->orderByDesc('last_seen_at')->limit(6)->get(), 'apps' => ActivityLog::where('captured_at', '>=', now()->startOfDay())->select('app_name')->selectRaw('COUNT(*) AS samples')->groupBy('app_name')->orderByDesc('samples')->limit(6)->get()]);
    }

    public function options(): JsonResponse
    {
        return response()->json(['users' => User::where('role', 'employee')->orderBy('name')->get(['id', 'name']), 'devices' => Device::orderBy('name')->get(['id', 'name'])]);
    }

    public function users(Request $request): JsonResponse
    {
        Audit::record($request, 'users.list');

        return response()->json(User::orderByDesc('created_at')->paginate(20));
    }

    public function createUser(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:255|unique:users,email', 'role' => ['required', Rule::in(['super_admin', 'reviewer', 'employee'])], 'password' => ['required', 'string', Password::min(12)]]);
        $data['email'] = strtolower($data['email']);
        $user = User::create($data);
        Audit::record($request, 'user.created', 'user', $user->id, ['role' => $user->role]);

        return response()->json($user, 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        abort_if($user->id === $request->user()->id && ! $data['enabled'], 422, 'You cannot disable your own account.');
        DB::transaction(function () use ($user, $data): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);
            if (! $data['enabled']) {
                $locked->tokens()->delete();
                $locked->devices()->update(['disabled_at' => now(), 'token_id' => null]);
                DB::table('sessions')->where('user_id', $locked->id)->delete();
            }
        });
        Audit::record($request, $data['enabled'] ? 'user.enabled' : 'user.disabled', 'user', $user->id);

        return response()->json($user->fresh());
    }

    public function devices(Request $request): JsonResponse
    {
        Audit::record($request, 'devices.list');

        return response()->json(Device::with('user:id,name,email')->orderByDesc('created_at')->paginate(20));
    }

    public function updateDevice(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate(['enabled' => 'sometimes|boolean', 'paused' => 'sometimes|boolean']);
        abort_if(! $data, 422, 'No change supplied.');
        DB::transaction(function () use ($device, $data): void {
            $locked = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('enabled', $data)) {
                $locked->disabled_at = $data['enabled'] ? null : now();
                if (! $data['enabled']) {
                    $locked->user->tokens()->whereKey($locked->token_id)->delete();
                    $locked->token_id = null;
                }
            }
            if (array_key_exists('paused', $data)) {
                $locked->paused = $data['paused'];
            }
            $locked->save();
        });
        Audit::record($request, 'device.updated', 'device', $device->id, $data);

        return response()->json($device->fresh());
    }

    public function policy(): JsonResponse
    {
        return response()->json(['policy' => MonitoringPolicy::current(), 'storage' => config('tracker.storage')]);
    }

    public function savePolicy(Request $request): JsonResponse
    {
        $data = $request->validate(['monitoring_enabled' => 'required|boolean', 'screenshot_interval_seconds' => 'required|integer|min:30|max:3600', 'activity_interval_seconds' => 'required|integer|min:5|max:300', 'browser_urls' => 'required|boolean', 'retention_days' => 'required|integer|min:1|max:365', 'audit_retention_days' => 'required|integer|min:30|max:3650', 'offline_queue_max_mb' => 'required|integer|min:50|max:10240', 'offline_retention_days' => 'required|integer|min:1|max:30', 'notice' => 'required|string|min:20|max:4000']);
        $policy = DB::transaction(function () use ($data): MonitoringPolicy {
            $policy = MonitoringPolicy::whereKey(MonitoringPolicy::current()->id)->lockForUpdate()->firstOrFail();
            $policy->fill($data);
            $policy->version++;
            $policy->save();

            return $policy;
        });
        Audit::record($request, 'policy.updated', 'policy', '1', ['version' => $policy->version, 'changed_fields' => array_keys($data)]);

        return response()->json(['policy' => $policy]);
    }

    public function storageCheck(Request $request, Vault $vault): JsonResponse
    {
        try {
            $vault->check();
        } catch (\Throwable $exception) {
            report($exception);
            Audit::record($request, 'storage.check_failed');

            return response()->json(['message' => 'Storage check failed. Inspect server credentials, certificate trust, connectivity and folder permissions.'], 503);
        }
        Audit::record($request, 'storage.checked');

        return response()->json(['message' => 'Storage write, read and delete checks passed.']);
    }

    public function screenshots(Request $request): JsonResponse
    {
        $query = $this->filtered(Screenshot::query(), $request)->with('device.user')->orderByDesc('captured_at')->orderBy('id');
        Audit::record($request, 'screenshots.list', null, null, $request->only('user_id', 'device_id', 'from', 'to', 'app'));

        return response()->json($query->paginate(24)->through(fn (Screenshot $shot) => [
            'id' => $shot->id, 'captured_at' => $shot->captured_at, 'app_name' => $shot->app_name, 'window_title' => $shot->window_title,
            'device_name' => $shot->device->name, 'user_name' => $shot->device->user->name, 'image_url' => route('screenshot.file', $shot, false),
            'thumbnail_url' => route('screenshot.thumbnail', $shot, false),
        ]));
    }

    public function image(Request $request, Screenshot $screenshot, Vault $vault): Response
    {
        $thumb = $request->routeIs('screenshot.thumbnail') && $screenshot->thumbnail_path;
        Audit::record($request, $thumb ? 'screenshot.thumbnail_viewed' : 'screenshot.viewed', 'screenshot', $screenshot->id, ['device_id' => $screenshot->device_id]);
        try {
            $bytes = $vault->get($thumb ? $screenshot->thumbnail_path : $screenshot->path, $screenshot->storage);
        } catch (\Throwable $error) {
            report($error);
            abort(503, 'Screenshot storage is unavailable.');
        }

        return response($bytes, 200, ['Content-Type' => $thumb ? 'image/jpeg' : 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="screenshot.'.($thumb ? 'jpg' : 'png').'"']);
    }

    public function activity(Request $request): JsonResponse
    {
        Audit::record($request, 'activity.list', null, null, $request->only('user_id', 'device_id', 'from', 'to', 'app'));

        return response()->json($this->filtered(ActivityLog::query(), $request)->with('device.user')->orderByDesc('captured_at')->orderBy('id')->paginate(50)->through(fn (ActivityLog $event) => [
            'id' => $event->id, 'captured_at' => $event->captured_at, 'app_name' => $event->app_name, 'window_title' => $event->window_title, 'url' => $event->url,
            'device_name' => $event->device->name, 'user_name' => $event->device->user->name,
        ]));
    }

    public function export(Request $request): Response
    {
        if (! $request->filled('from')) {
            $request->merge(['from' => now()->toDateString()]);
        }
        if (! $request->filled('to')) {
            $request->merge(['to' => $request->input('from')]);
        }
        $query = $this->filtered(ActivityLog::query(), $request)->with('device.user')->orderBy('captured_at');
        abort_if(CarbonImmutable::parse($request->input('from'))->diffInDays(CarbonImmutable::parse($request->input('to'))) > 31, 422, 'Export at most 31 days at a time.');
        abort_if($query->count() > 100000, 422, 'Narrow this export to fewer than 100,000 records.');
        Audit::record($request, 'activity.exported', null, null, $request->only('user_id', 'device_id', 'from', 'to', 'app'));

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Captured at (UTC)', 'Employee', 'Device', 'Application', 'Window title', 'Browser address'], ',', '"', '');
            foreach ($query->lazy(500) as $event) {
                $values = [$event->captured_at->toIso8601String(), $event->device->user->name, $event->device->name, $event->app_name, $event->window_title, $event->url ?? ''];
                fputcsv($stream, array_map(static fn (string $value) => preg_match('/^[=+\-@\t\r\n]/', $value) ? "'".$value : $value, $values), ',', '"', '');
            }
            fclose($stream);
        }, 'activity.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function audit(Request $request): JsonResponse
    {
        Audit::record($request, 'audit.list');

        return response()->json(AuditLog::with('actor:id,name')->orderByDesc('id')->paginate(50));
    }

    private function filtered(Builder $query, Request $request): Builder
    {
        $filters = $request->validate(['user_id' => 'nullable|uuid', 'device_id' => 'nullable|uuid', 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')], 'app' => 'nullable|string|max:160', 'search' => 'nullable|string|max:200']);
        if (! empty($filters['user_id'])) {
            $query->whereHas('device', fn (Builder $device) => $device->where('user_id', $filters['user_id']));
        }
        if (! empty($filters['device_id'])) {
            $query->where('device_id', $filters['device_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('captured_at', '>=', CarbonImmutable::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('captured_at', '<=', CarbonImmutable::parse($filters['to'])->endOfDay());
        }
        if (! empty($filters['app'])) {
            $query->whereRaw("app_name LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['app']).'%']);
        }
        if (! empty($filters['search'])) {
            $query->whereRaw("window_title LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%']);
        }

        return $query;
    }
}
