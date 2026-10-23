<?php

namespace App\Http\Controllers;

use App\Jobs\CleanupDeletedCapture;
use App\Models\Screenshot;
use App\Services\Audit;
use App\Services\CaptureDeletion;
use App\Services\Vault;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CaptureDeletionController extends Controller
{
    public function destroy(Request $request, string $id, CaptureDeletion $deletion, Vault $vault): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);
        $deletion->placeholder();
        DB::transaction(function () use ($request, $id, $data) {
            app(AgentController::class)->lockedDevice($request);
            $shot = Screenshot::whereKey($id)->whereHas('device', fn ($q) => $q->where('user_id', $request->user()->id))->lockForUpdate()->firstOrFail();
            abort_if($shot->deleted_at, 409, 'This screenshot has already been deleted.');
            $when = now();
            $reason = $data['reason'] ?? null;
            $shot->update(['deleted_at' => $when, 'deleted_by' => $request->user()->id, 'deleted_reason' => $reason, 'placeholder_path' => CaptureDeletion::PLACEHOLDER, 'deletion_cleanup_pending' => true]);
            DB::table('deletion_events')->insert(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'employee_name' => $request->user()->name, 'device_id' => $shot->device_id, 'capture_type' => 'screenshot', 'capture_id' => $id, 'deleted_at' => $when, 'reason' => $reason, 'ip_address' => $request->ip(), 'flagged' => false, 'flag_reason' => null]);
            Audit::record($request, 'capture.deleted', 'screenshot', $id, ['policy' => 'neutral']);
        });
        $pending = false;
        try {
            $deletion->cleanup($id, $vault);
        } catch (\Throwable $error) {
            report($error);
            $pending = true;
            try {
                CleanupDeletedCapture::dispatch($id);
            } catch (\Throwable $queueError) {
                report($queueError);
            }
        }

        return response()->json(['deleted' => true, 'cleanup_pending' => $pending, 'message' => $pending ? 'Screenshot hidden. Storage cleanup is pending and will retry.' : 'Screenshot deleted. No flags or score changes.'], $pending ? 202 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['user_id' => 'nullable|uuid', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''), 'flagged' => 'nullable|boolean', 'page' => 'sometimes|integer|min:1']);
        $q = DB::table('deletion_events')->orderByDesc('deleted_at')->orderBy('id');
        if (! empty($d['user_id'])) {
            $q->where('user_id', $d['user_id']);
        }
        if (! empty($d['from'])) {
            $q->where('deleted_at', '>=', $d['from'].' 00:00:00');
        }
        if (! empty($d['to'])) {
            $q->where('deleted_at', '<', CarbonImmutable::parse($d['to'], 'UTC')->addDay());
        }
        if (isset($d['flagged'])) {
            $q->where('flagged', $d['flagged']);
        }
        Audit::record($request, 'deletions.list');

        return response()->json($q->paginate(25));
    }
}
