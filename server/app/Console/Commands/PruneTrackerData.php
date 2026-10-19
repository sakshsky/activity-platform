<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\MonitoringPolicy;
use App\Models\Screenshot;
use App\Services\Vault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneTrackerData extends Command
{
    protected $signature = 'tracker:prune';

    protected $description = 'Delete expired screenshots, activity and audit logs according to server policy';

    public function handle(Vault $vault): int
    {
        $policy = MonitoringPolicy::current();
        $failed = 0;
        Screenshot::where('captured_at', '<', now()->subDays($policy->retention_days))->chunkById(100, function ($shots) use ($vault, &$failed): void {
            foreach ($shots as $shot) {
                try {
                    DB::transaction(function () use ($shot, $vault): void {
                        // Reload after locking so a just-published thumbnail is included.
                        $current = Screenshot::whereKey($shot->id)->lockForUpdate()->first();
                        if (! $current) {
                            return;
                        }
                        if ($current->thumbnail_path) {
                            $vault->delete($current->thumbnail_path, $current->storage);
                        }
                        $vault->delete($current->path, $current->storage);
                        $current->delete();
                    });
                } catch (\Throwable $error) {
                    report($error);
                    $failed++;
                }
            }
        });
        $activity = ActivityLog::where('captured_at', '<', now()->subDays($policy->retention_days))->delete();
        $audit = AuditLog::where('created_at', '<', now()->subDays($policy->audit_retention_days))->delete();
        $this->info("Pruned activity: $activity; audit entries: $audit; screenshot failures: $failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
