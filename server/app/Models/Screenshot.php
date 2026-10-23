<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Screenshot extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $hidden = ['path', 'thumbnail_path', 'placeholder_path'];

    protected function casts(): array
    {
        return ['captured_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime', 'deletion_cleanup_pending' => 'boolean'];
    }

    public function metadata(): array
    {
        return ['deleted' => (bool) $this->deleted_at, 'deleted_at' => $this->deleted_at, 'deleted_by' => $this->deleted_by, 'deleted_by_name' => $this->deleted_by ? $this->deletedBy?->name : null, 'deleted_reason' => $this->deleted_reason, 'deletion_cleanup_pending' => $this->deletion_cleanup_pending, 'event_id' => $this->event_id, 'device_id' => $this->device_id, 'received_at' => $this->created_at, 'width' => $this->width, 'height' => $this->height, 'bytes' => $this->bytes, 'sha256' => $this->sha256, 'mime_type' => 'image/png', 'capture_state' => $this->capture_state, 'idle_seconds' => $this->idle_seconds, 'idle_threshold_seconds' => $this->idle_threshold_seconds, 'agent_version' => $this->agent_version];
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
