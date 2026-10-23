<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoCapture extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['path', 'storage'];

    protected function casts(): array
    {
        return ['captured_at' => 'immutable_datetime', 'duration_seconds' => 'float'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
