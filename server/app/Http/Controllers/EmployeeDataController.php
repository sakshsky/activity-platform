<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\Audit;
use App\Services\CaptureDeletion;
use App\Services\EmployeeRecord;
use App\Services\Vault;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EmployeeDataController extends Controller
{
    public function records(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => 'required|in:screenshots,activity', 'page' => 'sometimes|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''), 'timezone' => 'sometimes|timezone']);
        $timezone = $data['timezone'] ?? 'UTC';
        $query = ($data['kind'] === 'screenshots' ? Screenshot::query() : ActivityLog::query())
            ->whereHas('device', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('device:id,name')->orderByDesc('captured_at')->orderBy('id');
        if (! empty($data['from'])) {
            $query->where('captured_at', '>=', CarbonImmutable::parse($data['from'], $timezone)->startOfDay()->utc());
        }
        if (! empty($data['to'])) {
            $query->where('captured_at', '<', CarbonImmutable::parse($data['to'], $timezone)->addDay()->startOfDay()->utc());
        }
        Audit::record($request, 'employee.data_viewed', $data['kind']);

        return response()->json($query->paginate($data['kind'] === 'screenshots' ? 12 : 30)->through(fn ($item) => [
            ...($item instanceof Screenshot ? $item->metadata() : ['duration_seconds' => $item->duration_seconds, 'app_switches' => $item->app_switches]), 'employee_id' => $request->user()->id, 'user_name' => $request->user()->name, 'id' => $item->id, 'captured_at' => $item->captured_at, 'received_at' => $item->created_at,
            'device_name' => $item->device->name, 'app_name' => $item->app_name, 'window_title' => $item->window_title, 'url' => $item->url,
        ]));
    }

    public function image(Request $request, string $id, Vault $vault): Response
    {
        $request->validate(['thumbnail' => 'sometimes|boolean']);
        $shot = Screenshot::whereKey($id)->whereHas('device', fn ($query) => $query->where('user_id', $request->user()->id))->firstOrFail();
        $thumbnail = $request->boolean('thumbnail') && $shot->thumbnail_path;
        try {
            [$bytes,$mime] = app(CaptureDeletion::class)->content($shot->id, (bool) $thumbnail, $vault);
        } catch (\Throwable $error) {
            report($error);
            abort(503, 'Screenshot storage is unavailable.');
        }
        Audit::record($request, 'employee.screenshot_viewed', 'screenshot', $shot->id);

        return response($bytes, 200, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function people(Request $request): JsonResponse
    {
        $request->validate(['search' => 'nullable|string|max:120']);
        $query = User::where('role', 'employee')->orderBy('name')->orderBy('id');
        if ($request->filled('search')) {
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $request->input('search'));
            $query->where(function ($query) use ($term): void {
                $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$term.'%'])->orWhereRaw("email LIKE ? ESCAPE '!'", ['%'.$term.'%']);
            });
        }

        return response()->json($query->paginate(20, ['id', 'name', 'email', 'enabled']));
    }

    public function person(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === 'employee', 404);
        Audit::record($request, 'personnel.viewed', 'user', $user->id);

        return response()->json(['profile' => EmployeeRecord::present($user)]);
    }

    public function employment(Request $request, User $user): JsonResponse
    {
        $rules = ['employment' => 'required|array', 'mark_reviewed' => 'sometimes|boolean', 'expected_revision' => 'required|integer|min:0'];
        foreach (EmployeeRecord::employmentRules() as $key => $rule) {
            $rules['employment.'.$key] = $rule;
        }
        $data = $request->validate($rules);
        $fields = array_intersect_key($data['employment'], EmployeeRecord::employmentRules());
        DB::transaction(function () use ($request, $user, $data, $fields): void {
            $admin = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($admin->enabled && $admin->role === 'super_admin', 403);
            $employee = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($employee->role === 'employee', 404);
            $row = DB::table('employee_profiles')->where('user_id', $user->id)->first();
            abort_unless(($row->profile_revision ?? 0) === $data['expected_revision'], 409, 'This record changed. Reload it before saving or reviewing.');
            $existing = $row?->employment_details ? json_decode($row->employment_details, true) : [];
            $update = ['employment_details' => json_encode(array_replace($existing, $fields), JSON_THROW_ON_ERROR), 'created_at' => $row->created_at ?? now(), 'updated_at' => now()];
            $update['profile_revision'] = ($row->profile_revision ?? 0) + 1;
            $update['reviewed_at'] = null;
            $update['reviewed_by'] = null;
            if ($data['mark_reviewed'] ?? false) {
                $update['reviewed_at'] = now();
                $update['reviewed_by'] = $admin->id;
            }
            DB::table('employee_profiles')->updateOrInsert(['user_id' => $user->id], $update);
        });
        Audit::record($request, 'personnel.employment_updated', 'user', $user->id, ['fields' => array_keys($fields), 'reviewed' => $data['mark_reviewed'] ?? false]);

        return response()->json(['profile' => EmployeeRecord::present($user)]);
    }
}
