<?php

namespace Tests\Unit;

use App\Domain\Autonomy\AutonomousResponsePolicyResolver;
use App\Models\AutonomousResponsePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutonomousResponsePolicyResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_scope_policy_overrides_tenant_and_global_policy(): void
    {
        $tenantId = (string) Str::uuid();
        $deviceId = (string) Str::uuid();

        AutonomousResponsePolicy::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'name' => 'Global',
            'scope_type' => 'global',
            'scope_id' => null,
            'trigger_type' => 'malware_detected',
            'autonomy_mode' => 'recommend_only',
            'minimum_confidence' => 60,
            'enabled' => true,
        ]);
        AutonomousResponsePolicy::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'name' => 'Tenant',
            'scope_type' => 'tenant',
            'scope_id' => $tenantId,
            'trigger_type' => 'malware_detected',
            'autonomy_mode' => 'approval_required',
            'minimum_confidence' => 70,
            'enabled' => true,
        ]);
        $devicePolicy = AutonomousResponsePolicy::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'name' => 'Device',
            'scope_type' => 'device',
            'scope_id' => $deviceId,
            'trigger_type' => 'malware_detected',
            'autonomy_mode' => 'auto_execute',
            'minimum_confidence' => 88,
            'enabled' => true,
        ]);

        $resolved = app(AutonomousResponsePolicyResolver::class)->resolve([
            'tenant_id' => $tenantId,
            'device_id' => $deviceId,
            'trigger_type' => 'malware_detected',
            'device_group_ids' => [],
        ]);

        $this->assertSame($devicePolicy->id, $resolved['policy']?->id);
        $this->assertSame('auto_execute', $resolved['autonomy_mode']);
        $this->assertSame(88.0, (float) $resolved['minimum_confidence']);
    }
}
