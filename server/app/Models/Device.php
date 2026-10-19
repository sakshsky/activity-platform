<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $hidden = ['token_id'];

    protected $appends = ['online'];

    protected function casts(): array
    {
        return ['paused' => 'boolean', 'disabled_at' => 'datetime', 'last_seen_at' => 'datetime', 'last_upload_at' => 'datetime', 'notice_accepted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getOnlineAttribute(): bool
    {
        return ! $this->disabled_at && $this->last_seen_at?->greaterThan(now()->subMinutes(2));
    }
}
