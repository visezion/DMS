<?php

namespace App\Domain\Remediation;

use App\Models\AutonomyPolicy;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AutonomyPolicyUpsertService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsert(array $payload): AutonomyPolicy
    {
        $scopeType = (string) $payload['scope_type'];
        $rawScopeId = trim((string) ($payload['scope_id'] ?? ''));
        $scopeId = $scopeType === 'global' ? 'global' : $rawScopeId;

        return AutonomyPolicy::query()->updateOrCreate(
            [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'active' => true,
            ],
            [
                'id' => AutonomyPolicy::query()
                    ->where('scope_type', $scopeType)
                    ->where('scope_id', $scopeId)
                    ->where('active', true)
                    ->value('id') ?? (string) Str::uuid(),
                'autonomy_level' => (string) $payload['autonomy_level'],
                'allowed_actions' => Arr::wrap($payload['allowed_actions'] ?? []),
                'blocked_conditions' => Arr::wrap($payload['blocked_conditions'] ?? []),
                'maintenance_windows' => Arr::wrap($payload['maintenance_windows'] ?? []),
                'max_parallel_actions' => (int) ($payload['max_parallel_actions'] ?? 5),
                'version' => 1,
            ]
        );
    }
}
