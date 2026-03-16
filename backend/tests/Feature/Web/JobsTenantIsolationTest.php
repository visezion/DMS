<?php

namespace Tests\Feature\Web;

use App\Models\Device;
use App\Models\DmsJob;
use App\Models\JobRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_jobs_dashboard_metrics_are_scoped_to_current_tenant(): void
    {
        [$tenantA, $tenantB] = $this->createTenants();
        $userA = $this->createTenantUser($tenantA, 'tenant-a-admin@example.com');

        $tenantAJob = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantA->id,
            'job_type' => 'run_command',
            'status' => 'queued',
            'priority' => 100,
            'payload' => ['script' => 'whoami'],
            'target_type' => 'device',
            'target_id' => (string) Str::uuid(),
            'created_by' => null,
        ]);
        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $tenantAJob->id,
            'device_id' => (string) Str::uuid(),
            'status' => 'success',
        ]);

        $tenantBJob = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantB->id,
            'job_type' => 'run_command',
            'status' => 'queued',
            'priority' => 100,
            'payload' => ['script' => 'hostname'],
            'target_type' => 'device',
            'target_id' => (string) Str::uuid(),
            'created_by' => null,
        ]);
        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $tenantBJob->id,
            'device_id' => (string) Str::uuid(),
            'status' => 'failed',
        ]);

        $response = $this->actingAs($userA)->get(route('admin.jobs'));

        $response->assertOk();
        $response->assertViewHas('job_summary', function (array $summary): bool {
            return ($summary['total'] ?? null) === 1
                && ($summary['active'] ?? null) === 0
                && ($summary['completed'] ?? null) === 1
                && ($summary['failed'] ?? null) === 0;
        });
        $response->assertViewHas('jobs', function ($jobs) use ($tenantAJob, $tenantBJob): bool {
            $ids = $jobs->getCollection()->pluck('id');
            return $ids->contains($tenantAJob->id) && ! $ids->contains($tenantBJob->id);
        });
    }

    public function test_store_and_clear_jobs_only_affects_current_tenant(): void
    {
        [$tenantA, $tenantB] = $this->createTenants();
        $userA = $this->createTenantUser($tenantA, 'tenant-a-clear@example.com');

        $tenantAJob = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantA->id,
            'job_type' => 'run_command',
            'status' => 'queued',
            'priority' => 100,
            'payload' => ['script' => 'whoami'],
            'target_type' => 'device',
            'target_id' => (string) Str::uuid(),
            'created_by' => null,
        ]);
        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $tenantAJob->id,
            'device_id' => (string) Str::uuid(),
            'status' => 'success',
        ]);

        $tenantBJob = DmsJob::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantB->id,
            'job_type' => 'run_command',
            'status' => 'queued',
            'priority' => 100,
            'payload' => ['script' => 'hostname'],
            'target_type' => 'device',
            'target_id' => (string) Str::uuid(),
            'created_by' => null,
        ]);
        JobRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_id' => $tenantBJob->id,
            'device_id' => (string) Str::uuid(),
            'status' => 'success',
        ]);

        $this->actingAs($userA)
            ->post(route('admin.jobs.store-clear'), [
                'scope' => 'all',
                'store_snapshot' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('jobs', ['id' => $tenantAJob->id]);
        $this->assertDatabaseHas('jobs', ['id' => $tenantBJob->id]);
    }

    public function test_tenant_user_cannot_queue_job_against_other_tenant_device(): void
    {
        [$tenantA, $tenantB] = $this->createTenants();
        $userA = $this->createTenantUser($tenantA, 'tenant-a-queue@example.com');
        $tenantBDevice = Device::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantB->id,
            'hostname' => 'tenant-b-device',
            'os_name' => 'Windows',
            'agent_version' => '1.0.0',
            'status' => 'online',
        ]);

        $this->actingAs($userA)
            ->from(route('admin.jobs'))
            ->post(route('admin.jobs.create'), [
                'job_type' => 'run_command',
                'target_type' => 'device',
                'target_id' => $tenantBDevice->id,
                'priority' => 100,
                'stagger_seconds' => 0,
                'payload_json' => json_encode(['script' => 'whoami'], JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('admin.jobs'))
            ->assertSessionHasErrors('target_id');

        $this->assertDatabaseCount('jobs', 0);
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
            'name' => 'Tenant Admin',
            'email' => $email,
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ]);
    }
}
