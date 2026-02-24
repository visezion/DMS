<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_end_to_end_enroll_checkin_and_job_result_flow(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'is_active' => true,
        ]);

        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Admin Role',
            'slug' => 'admin-role',
        ]);

        $permissionSlugs = ['devices.write', 'jobs.write', 'devices.read'];
        $permissionIds = [];
        foreach ($permissionSlugs as $slug) {
            $permissionIds[] = Permission::query()->create([
                'id' => (string) Str::uuid(),
                'name' => $slug,
                'slug' => $slug,
            ])->id;
        }

        $role->permissions()->sync($permissionIds);
        $admin->roles()->sync([$role->id]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password123',
            'device_name' => 'phpunit',
        ]);

        $login->assertStatus(200);
        $token = $login->json('token');

        $tokenResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/enrollment-tokens', []);

        $tokenResponse->assertStatus(201);
        $enrollmentToken = $tokenResponse->json('token');

        $enroll = $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $enrollmentToken,
            'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----abc',
            'device_facts' => [
                'hostname' => 'PC-100',
                'os_name' => 'Windows 11',
                'os_version' => '23H2',
                'serial_number' => 'SER-100',
                'agent_version' => '1.0.0',
            ],
        ]);

        $enroll->assertStatus(201);
        $deviceId = $enroll->json('device_id');

        $this->postJson('/api/v1/device/heartbeat', [
            'device_id' => $deviceId,
            'agent_version' => '1.0.1',
        ])->assertStatus(200);

        $job = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/admin/jobs', [
                'job_type' => 'run_command',
                'payload' => ['command' => 'whoami'],
                'target_type' => 'device',
                'target_id' => $deviceId,
                'priority' => 10,
            ]);

        $job->assertStatus(201);

        $checkin = $this->postJson('/api/v1/device/checkin', ['device_id' => $deviceId]);
        $checkin->assertStatus(200);
        $commandId = $checkin->json('commands.0.envelope.command_id');
        $this->assertNotEmpty($commandId);
        $signature = $checkin->json('commands.0.signature');
        $envelope = $checkin->json('commands.0.envelope');

        $keyset = $this->getJson('/api/v1/device/keyset');
        $keyset->assertStatus(200);
        $keys = $keyset->json('keys');
        $this->assertNotEmpty($keys);

        $key = collect($keys)->firstWhere('kid', $signature['kid']);
        $this->assertNotNull($key);
        $this->assertSame('Ed25519', $signature['alg']);
        $this->assertTrue(
            sodium_crypto_sign_verify_detached(
                base64_decode($signature['sig'], true),
                hash('sha256', $this->canonicalJson($envelope), true),
                base64_decode($key['public_key_base64'], true)
            )
        );

        $this->postJson('/api/v1/device/job-ack', [
            'job_run_id' => $commandId,
            'device_id' => $deviceId,
        ])->assertStatus(200);

        $this->postJson('/api/v1/device/job-result', [
            'job_run_id' => $commandId,
            'device_id' => $deviceId,
            'status' => 'success',
            'exit_code' => 0,
            'result_payload' => ['stdout' => 'ok'],
        ])->assertStatus(200);

        $this->assertDatabaseHas('devices', [
            'id' => $deviceId,
            'agent_version' => '1.0.1',
            'status' => 'online',
        ]);

        $this->assertDatabaseHas('job_runs', [
            'id' => $commandId,
            'device_id' => $deviceId,
            'status' => 'success',
            'exit_code' => 0,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'device.enroll',
            'entity_id' => $deviceId,
        ]);
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if ($this->isListArray($value)) {
                return '['.implode(',', array_map(fn ($v) => $this->canonicalJson($v), $value)).']';
            }

            $normalized = [];
            foreach ($value as $k => $v) {
                $normalized[(string) $k] = $v;
            }
            ksort($normalized, SORT_STRING);

            $pairs = [];
            foreach ($normalized as $k => $v) {
                $pairs[] = $this->encodeJsonString((string) $k).':'.$this->canonicalJson($v);
            }

            return '{'.implode(',', $pairs).'}';
        }

        if (is_object($value)) {
            return $this->canonicalJson((array) $value);
        }

        if (is_string($value)) {
            return $this->encodeJsonString($value);
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function isListArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private function encodeJsonString(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return str_replace(
            ['+', '<', '>', '&', "'"],
            ['\\u002B', '\\u003C', '\\u003E', '\\u0026', '\\u0027'],
            $encoded
        );
    }
}
