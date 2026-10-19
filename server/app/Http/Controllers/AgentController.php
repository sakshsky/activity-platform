<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateThumbnail;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\MonitoringPolicy;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\Audit;
use App\Services\Vault;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AgentController extends Controller
{
    public function info(): JsonResponse
    {
        return response()->json(['policy' => MonitoringPolicy::current()]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'device_identifier' => 'required|uuid', 'device_name' => 'required|string|max:120', 'os' => 'required|string|max:80', 'notice_accepted' => 'required|accepted']);
        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! $user->enabled || $user->role !== 'employee' || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid employee credentials or disabled account.']);
        }
        $policy = MonitoringPolicy::current();
        $result = DB::transaction(function () use ($user, $data, $policy): array {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->enabled, 403, 'Account disabled.');
            $device = Device::where('device_identifier', $data['device_identifier'])->lockForUpdate()->first();
            abort_if($device && ($device->user_id !== $user->id || $device->disabled_at), 403, 'This device is disabled or belongs to another employee.');
            $device ??= new Device(['device_identifier' => $data['device_identifier'], 'user_id' => $user->id]);
            if ($device->token_id) {
                $user->tokens()->whereKey($device->token_id)->delete();
            }
            $expiry = now()->addDays(config('tracker.token_days'));
            $token = $user->createToken('device:'.$data['device_identifier'], ['device:collect'], $expiry);
            $device->fill(['name' => $data['device_name'], 'os' => $data['os'], 'token_id' => $token->accessToken->id, 'last_seen_at' => now(), 'notice_version' => $policy->version, 'notice_accepted_at' => now()])->save();

            return ['user' => $user, 'device' => $device, 'token' => $token->plainTextToken, 'expires_at' => $expiry, 'policy' => $this->policyFor($device, $policy)];
        });
        Audit::record(null, 'device.enrolled', 'device', $result['device']->id, ['user_id' => $user->id, 'ip' => $request->ip()]);

        return response()->json($result);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $device->update(['last_seen_at' => now()]);

        return response()->json(['device' => $device, 'policy' => $this->policyFor($device)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $request->user()->currentAccessToken()->delete();
        $device->update(['token_id' => null]);
        Audit::record($request, 'device.logout', 'device', $device->id);

        return response()->json(['success' => true]);
    }

    public function screenshot(Request $request, Vault $vault): JsonResponse
    {
        $data = $request->validate(['event_id' => 'required|uuid', 'captured_at' => 'required|date|before_or_equal:'.now()->addMinutes(5)->toIso8601String(), 'file' => 'required|file|mimetypes:image/png|max:20480', 'app_name' => 'nullable|string|max:160', 'window_title' => 'nullable|string|max:1000', 'url' => 'nullable|string|max:2048']);
        $device = $request->attributes->get('device');
        $policy = MonitoringPolicy::current();
        abort_if($device->paused || ! $policy->monitoring_enabled, 409, 'Monitoring is paused by the administrator.');
        $captured = CarbonImmutable::parse($data['captured_at'])->utc();
        if ($captured->lessThan(now()->subDays($policy->retention_days))) {
            return response()->json(['accepted' => true, 'discarded' => true]);
        }
        $file = $request->file('file');
        abort_unless(file_get_contents($file->getRealPath(), false, null, 0, 8) === "\x89PNG\r\n\x1a\n", 422, 'Screenshot must be a PNG image.');
        $size = getimagesize($file->getRealPath());
        abort_unless($size && $size[0] * $size[1] <= 64000000, 422, 'Screenshot dimensions are too large.');
        $sha = hash_file('sha256', $file->getRealPath());
        $created = false;
        $shot = DB::transaction(function () use ($device, $data, $policy, $captured, $file, $sha, $vault, &$created): Screenshot {
            Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $existing = Screenshot::where('device_id', $device->id)->where('event_id', $data['event_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->sha256, $sha), 409, 'This event ID already belongs to a different screenshot.');

                return $existing;
            }
            $path = 'screenshots/'.$device->device_identifier.'/'.$captured->format('Y-m-d').'/'.$data['event_id'].'.png';
            $vault->put($path, file_get_contents($file->getRealPath()));
            try {
                $shot = Screenshot::create(['device_id' => $device->id, 'event_id' => $data['event_id'], 'captured_at' => $captured, 'path' => $path, 'storage' => config('tracker.storage'), 'sha256' => $sha, 'bytes' => $file->getSize(), 'app_name' => $data['app_name'] ?? '', 'window_title' => $data['window_title'] ?? '', 'url' => $policy->browser_urls ? $this->cleanURL($data['url'] ?? '') : null]);
                $device->update(['last_upload_at' => now(), 'last_seen_at' => now()]);
                $created = true;

                return $shot;
            } catch (\Throwable $error) {
                $vault->delete($path);
                throw $error;
            }
        });
        if ($created) {
            GenerateThumbnail::dispatch($shot->id);
        }

        return response()->json(['accepted' => true, 'id' => $shot->id, 'sha256' => $sha], $created ? 201 : 200);
    }

    public function activity(Request $request): JsonResponse
    {
        $data = $request->validate(['events' => 'required|array|min:1|max:100', 'events.*.event_id' => 'required|uuid', 'events.*.captured_at' => 'required|date|before_or_equal:'.now()->addMinutes(5)->toIso8601String(), 'events.*.app_name' => 'required|string|max:160', 'events.*.window_title' => 'nullable|string|max:1000', 'events.*.url' => 'nullable|string|max:2048']);
        $device = $request->attributes->get('device');
        $policy = MonitoringPolicy::current();
        abort_if($device->paused || ! $policy->monitoring_enabled, 409, 'Monitoring is paused by the administrator.');
        $accepted = [];
        DB::transaction(function () use ($data, $device, $policy, &$accepted): void {
            Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            foreach ($data['events'] as $event) {
                $accepted[] = $event['event_id'];
                $captured = CarbonImmutable::parse($event['captured_at'])->utc();
                if ($captured->lessThan(now()->subDays($policy->retention_days))) {
                    continue;
                }
                ActivityLog::firstOrCreate(['device_id' => $device->id, 'event_id' => $event['event_id']], ['captured_at' => $captured, 'app_name' => $event['app_name'], 'window_title' => $event['window_title'] ?? '', 'url' => $policy->browser_urls ? $this->cleanURL($event['url'] ?? '') : null, 'created_at' => now()]);
            }
            $device->update(['last_seen_at' => now(), 'last_upload_at' => now()]);
        });

        return response()->json(['accepted_ids' => $accepted]);
    }

    private function policyFor(Device $device, ?MonitoringPolicy $policy = null): array
    {
        $policy ??= MonitoringPolicy::current();

        return [...$policy->toArray(), 'monitoring_enabled' => $policy->monitoring_enabled && ! $device->paused, 'paused' => $device->paused];
    }

    private function cleanURL(string $value): ?string
    {
        if (! $value) {
            return null;
        }
        $hasScheme = preg_match('~^https?://~i', $value);
        $parts = parse_url($hasScheme ? $value : 'https://'.$value);
        if (! $parts || empty($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        return ($hasScheme ? $parts['scheme'].'://' : '').$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }
}
