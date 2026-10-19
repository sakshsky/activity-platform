<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonitoringPolicy extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['monitoring_enabled' => 'boolean', 'browser_urls' => 'boolean'];
    }

    public static function current(): self
    {
        $policy = static::firstOrCreate(['id' => 1], ['notice' => 'This organization records desktop screenshots and foreground application activity while this agent is signed in. Authorized administrators and reviewers can access the records. The default server retention period is 30 days. Contact your administrator for your organization’s monitoring policy.']);

        return $policy->wasRecentlyCreated ? $policy->refresh() : $policy;
    }
}
