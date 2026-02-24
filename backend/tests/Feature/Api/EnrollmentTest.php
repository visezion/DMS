<?php

namespace Tests\Feature\Api;

use App\Models\EnrollmentToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_can_enroll_with_valid_token_once(): void
    {
        $rawToken = Str::random(32);
        EnrollmentToken::query()->create([
            'id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addHour(),
        ]);

        $payload = [
            'enrollment_token' => $rawToken,
            'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----abc',
            'device_facts' => [
                'hostname' => 'PC-001',
                'os_name' => 'Windows 11',
                'os_version' => '23H2',
                'serial_number' => 'XYZ',
                'agent_version' => '1.0.0',
            ],
        ];

        $first = $this->postJson('/api/v1/device/enroll', $payload);
        $first->assertStatus(201)->assertJsonStructure(['device_id']);

        $second = $this->postJson('/api/v1/device/enroll', $payload);
        $second->assertStatus(422);
    }
}
