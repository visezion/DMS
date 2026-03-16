<?php

namespace Tests\Feature\Web;

use App\Models\AgentRelease;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentDeliveryTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'http://localhost');
        URL::forceRootUrl('http://localhost');
    }

    public function test_agent_page_only_lists_current_tenant_releases(): void
    {
        [$tenantA, $tenantB] = $this->createTenants();
        $userA = $this->createTenantUser($tenantA, 'agent-a@example.com');

        $releaseA = $this->createTenantRelease($tenantA->id, '1.0.0-a');
        $releaseB = $this->createTenantRelease($tenantB->id, '1.0.0-b');

        $this->actingAs($userA)
            ->get('/admin/agent')
            ->assertOk()
            ->assertSee($releaseA->version)
            ->assertDontSee($releaseB->version);
    }

    public function test_tenant_user_cannot_activate_other_tenant_release(): void
    {
        [$tenantA, $tenantB] = $this->createTenants();
        $userA = $this->createTenantUser($tenantA, 'agent-activate-a@example.com');

        $releaseA = $this->createTenantRelease($tenantA->id, '2.0.0-a');
        $releaseB = $this->createTenantRelease($tenantB->id, '2.0.0-b');

        $this->actingAs($userA)
            ->withSession(['_token' => 'csrf-token'])
            ->post('/admin/agent/releases/'.$releaseB->id.'/activate', ['_token' => 'csrf-token'])
            ->assertNotFound();

        $releaseA = AgentRelease::query()->withoutGlobalScope('tenant')->findOrFail($releaseA->id);
        $releaseB = AgentRelease::query()->withoutGlobalScope('tenant')->findOrFail($releaseB->id);
        $this->assertFalse((bool) $releaseA->is_active);
        $this->assertFalse((bool) $releaseB->is_active);
    }

    public function test_signed_download_requires_matching_tenant_parameter_for_tenant_release(): void
    {
        [$tenantA, $tenantB] = $this->createTenants();
        $tenantRelease = $this->createTenantRelease($tenantA->id, '3.0.0-a', 'agent-releases/tenant-a.zip', 'tenant-a.zip');

        Storage::disk('local')->put($tenantRelease->storage_path, 'tenant artifact');

        $validUrl = URL::temporarySignedRoute('agent.release.download', now()->addMinutes(10), [
            'releaseId' => $tenantRelease->id,
            'tenant' => $tenantA->id,
        ]);
        $this->get($validUrl)->assertOk();

        $mismatchUrl = URL::temporarySignedRoute('agent.release.download', now()->addMinutes(10), [
            'releaseId' => $tenantRelease->id,
            'tenant' => $tenantB->id,
        ]);
        $this->get($mismatchUrl)->assertForbidden();

        $missingTenantUrl = URL::temporarySignedRoute('agent.release.download', now()->addMinutes(10), [
            'releaseId' => $tenantRelease->id,
        ]);
        $this->get($missingTenantUrl)->assertForbidden();
    }

    public function test_platform_release_download_keeps_backward_compatibility_without_tenant_parameter(): void
    {
        $platformRelease = $this->createTenantRelease(null, '4.0.0-platform', 'agent-releases/platform.zip', 'platform.zip');
        Storage::disk('local')->put($platformRelease->storage_path, 'platform artifact');

        $legacyUrl = URL::temporarySignedRoute('agent.release.download', now()->addMinutes(10), [
            'releaseId' => $platformRelease->id,
        ]);
        $this->get($legacyUrl)->assertOk();
    }

    /**
     * @return array{Tenant,Tenant}
     */
    private function createTenants(): array
    {
        $tenantA = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'status' => 'active',
        ]);
        $tenantB = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'status' => 'active',
        ]);

        return [$tenantA, $tenantB];
    }

    private function createTenantUser(Tenant $tenant, string $email): User
    {
        return User::query()->create([
            'name' => 'Tenant Agent Admin',
            'email' => $email,
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);
    }

    private function createTenantRelease(?string $tenantId, string $version, string $storagePath = 'agent-releases/release.zip', string $fileName = 'release.zip'): AgentRelease
    {
        return AgentRelease::query()
            ->withoutGlobalScope('tenant')
            ->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'version' => $version,
                'platform' => 'windows-x64',
                'file_name' => $fileName,
                'storage_path' => $storagePath,
                'size_bytes' => 16,
                'sha256' => hash('sha256', $version.$fileName),
                'notes' => null,
                'is_active' => false,
                'uploaded_by' => null,
            ]);
    }
}
