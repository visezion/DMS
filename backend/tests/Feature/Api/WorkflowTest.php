<?php

namespace Tests\Feature\Api;

use App\Models\ControlPlaneSetting;
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

    public function test_api_login_is_rate_limited_after_repeated_failures(): void
    {
        ControlPlaneSetting::query()->create([
            'key' => 'auth.max_login_attempts',
            'value' => ['value' => 2],
        ]);
        ControlPlaneSetting::query()->create([
            'key' => 'auth.lockout_minutes',
            'value' => ['value' => 15],
        ]);

        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after_seconds']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password123',
        ])->assertStatus(429);
    }

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
        $requestSigningKeypair = sodium_crypto_sign_keypair();
        $requestSigningSecretKey = sodium_crypto_sign_secretkey($requestSigningKeypair);
        $requestSigningPublicKey = sodium_crypto_sign_publickey($requestSigningKeypair);

        $enroll = $this->postJson('/api/v1/device/enroll', [
            'enrollment_token' => $enrollmentToken,
            'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----abc',
            'request_signing_public_key' => base64_encode($requestSigningPublicKey),
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
        ])->assertStatus(401);

        $this->signedJson('POST', '/api/v1/device/heartbeat', [
            'device_id' => $deviceId,
            'agent_version' => '1.0.1',
        ], $deviceId, $requestSigningSecretKey)->assertStatus(200);

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

        $checkin = $this->signedJson('POST', '/api/v1/device/checkin', ['device_id' => $deviceId], $deviceId, $requestSigningSecretKey);
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
        $sigBytes = base64_decode($signature['sig'], true);
        $publicKey = base64_decode($key['public_key_base64'], true);
        $canonical = $this->canonicalJson($envelope);
        $wire = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $verified = false;
        foreach ([
            hash('sha256', $canonical, true), // digest
            $canonical,                       // canonical
            hash('sha256', $wire, true),      // wire_digest
            $wire,                            // wire
        ] as $message) {
            if ($message === false || ! is_string($message)) {
                continue;
            }
            if (sodium_crypto_sign_verify_detached($sigBytes, $message, $publicKey)) {
                $verified = true;
                break;
            }
        }

        $this->assertTrue($verified, 'Signature verification failed for all supported signature modes.');

        $this->signedJson('POST', '/api/v1/device/job-ack', [
            'job_run_id' => $commandId,
            'device_id' => $deviceId,
        ], $deviceId, $requestSigningSecretKey)->assertStatus(200);

        $this->signedJson('POST', '/api/v1/device/job-result', [
            'job_run_id' => $commandId,
            'device_id' => $deviceId,
            'status' => 'success',
            'exit_code' => 0,
            'result_payload' => ['stdout' => 'ok'],
        ], $deviceId, $requestSigningSecretKey)->assertStatus(200);

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

    private function signedJson(string $method, string $uri, array $payload, string $deviceId, string $secretKey)
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->timestamp;
        $nonce = base64_encode(random_bytes(16));
        $bodyHash = hash('sha256', (string) $json);
        $message = implode("\n", [
            strtoupper($method),
            $uri,
            $timestamp,
            $nonce,
            $deviceId,
            $bodyHash,
        ]);
        $signature = base64_encode(sodium_crypto_sign_detached($message, $secretKey));

        return $this->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DMS_DEVICE_ID' => $deviceId,
            'HTTP_X_DMS_TIMESTAMP' => $timestamp,
            'HTTP_X_DMS_NONCE' => $nonce,
            'HTTP_X_DMS_SIGNATURE' => $signature,
        ], $json);
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
