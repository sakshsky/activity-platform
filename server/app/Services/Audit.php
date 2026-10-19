<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class Audit
{
    public static function record(?Request $request, string $action, ?string $type = null, ?string $id = null, array $metadata = []): void
    {
        AuditLog::create(['actor_id' => $request?->user()?->id, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'ip_address' => $request?->ip(), 'metadata' => $metadata, 'created_at' => now()]);
    }
}
