<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public function log(string $action, string $entityType, string $entityId, ?array $before = null, ?array $after = null, ?int $actorUserId = null, ?string $actorDeviceId = null): void
    {
        $prev = AuditLog::query()->latest('id')->first();
        $prevHash = $prev?->row_hash;

        $payload = [
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'prev_hash' => $prevHash,
            'request_id' => request()->header('X-Request-ID'),
            'ip_address' => Request::ip(),
            'user_agent' => request()->userAgent(),
            'at' => now()->toIso8601String(),
        ];

        AuditLog::query()->create([
            'actor_user_id' => $actorUserId,
            'actor_device_id' => $actorDeviceId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'request_id' => request()->header('X-Request-ID'),
            'ip_address' => Request::ip(),
            'user_agent' => request()->userAgent(),
            'prev_hash' => $prevHash,
            'row_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ]);
    }
}
