<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonitoringPolicy extends Model
{
    use HasFactory;

    // The singleton's ID is assigned explicitly; MySQL must not replace it with lastInsertId().
    public $incrementing = false;

    protected $guarded = [];

    protected $appends = ['modules_notice'];

    public function getModulesNoticeAttribute(): string
    {
        return 'Optional modules: '.($this->video_enabled ? 'Silent screen video clips of '.$this->video_clip_seconds.' seconds with a gap of '.$this->video_interval_seconds.' seconds while active; retained for '.$this->video_retention_days.' days, up to '.$this->video_quota_mb.' MB per employee. ' : 'Video recording is off. ').($this->anomaly_enabled ? 'Rule-based input-timing review is on: a 10-minute window needs at least 30 observed intervals, with 90% in one 200 ms timing bucket between 2 and 60 seconds. Only aggregate counts and timing buckets are uploaded, not key contents or pointer positions. Regular timing may have legitimate explanations and is not proof of misconduct. ' : 'Input-timing review is off. ').'Employees can view clips and review evidence and add explanations. These modules never change performance scores. Acknowledgment is required before optional collection starts.';
    }

    protected function casts(): array
    {
        return ['monitoring_enabled' => 'boolean', 'browser_urls' => 'boolean', 'video_enabled' => 'boolean', 'anomaly_enabled' => 'boolean'];
    }

    public static function current(): self
    {
        $policy = static::firstOrCreate(['id' => 1], ['idle_threshold_seconds' => 120, 'notice' => 'This system records screenshots, foreground application names, window titles, and optional browser addresses while signed in. Idle time and application categories provide context; published review scores reflect manager-assessed task outcomes, not computer activity. Authorized administrators and reviewers can access the records. The default server retention period is 30 days. Contact your administrator for your organization’s capture policy.']);

        return $policy->wasRecentlyCreated ? $policy->refresh() : $policy;
    }
}
