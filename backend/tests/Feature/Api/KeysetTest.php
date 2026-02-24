<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeysetTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_keyset_returns_active_signing_key(): void
    {
        $response = $this->getJson('/api/v1/device/keyset');

        $response->assertStatus(200)
            ->assertJsonPath('schema', 'dms.keyset.v1');

        $keys = $response->json('keys');
        $this->assertNotEmpty($keys);
        $this->assertSame('Ed25519', $keys[0]['alg']);
        $this->assertNotEmpty($keys[0]['kid']);
        $this->assertNotEmpty($keys[0]['public_key_base64']);
    }
}
