<?php

namespace Tests\Feature\Web;

use App\Models\AgentRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentInstallerBundleTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_installer_bundle_routes_are_accessible(): void
    {
        $user = User::factory()->create();
        $release = $this->createAgentRelease();
        $publicBaseUrl = 'http://dms.test';
        $apiBaseUrl = $publicBaseUrl.'/api/v1';

        $bundle = $this->actingAs($user)
            ->postJson('/admin/agent/releases/generate-json', [
                'release_id' => $release->id,
                'expires_hours' => 24,
                'api_base_url' => $apiBaseUrl,
                'public_base_url' => $publicBaseUrl,
            ])
            ->assertOk()
            ->json('bundle');

        $this->assertIsArray($bundle);
        $this->assertArrayHasKey('script_url', $bundle);
        $this->assertArrayHasKey('launcher_url', $bundle);
        $this->assertArrayHasKey('download_url', $bundle);
        $this->assertArrayHasKey('cmd_script', $bundle);

        $scriptResponse = $this->get($bundle['script_url']);
        $scriptResponse->assertOk();
        $script = $scriptResponse->getContent();
        $this->assertStringContainsString('DMS_ENROLLMENT_TOKEN', $script);
        $this->assertMatchesRegularExpression('/\$DownloadUrl = "([^"]+)"/', $script);

        preg_match('/\$DownloadUrl = "([^"]+)"/', $script, $downloadMatches);
        $embeddedDownloadUrl = $downloadMatches[1] ?? '';
        $this->assertNotSame('', $embeddedDownloadUrl);

        $this->get($embeddedDownloadUrl)->assertOk();

        $launcherResponse = $this->get($bundle['launcher_url']);
        $launcherResponse->assertOk();
        $launcher = $launcherResponse->getContent();
        $this->assertStringContainsString('set "SCRIPT_URL=', $launcher);
        $this->assertStringContainsString('Invoke-WebRequest -UseBasicParsing', $launcher);
        $this->assertStringContainsString('%%2F', $launcher);
    }

    public function test_generated_installer_bundle_signatures_are_valid_for_subdirectory_public_base_url(): void
    {
        $user = User::factory()->create();
        $release = $this->createAgentRelease();
        $publicBaseUrl = 'http://example.test/DMS/backend/public';
        $apiBaseUrl = $publicBaseUrl.'/api/v1';

        $bundle = $this->actingAs($user)
            ->postJson('/admin/agent/releases/generate-json', [
                'release_id' => $release->id,
                'expires_hours' => 24,
                'api_base_url' => $apiBaseUrl,
                'public_base_url' => $publicBaseUrl,
            ])
            ->assertOk()
            ->json('bundle');

        $this->assertTrue(URL::hasValidSignature(Request::create($bundle['script_url'], 'GET')));
        $this->assertTrue(URL::hasValidSignature(Request::create($bundle['launcher_url'], 'GET')));
        $this->assertTrue(URL::hasValidSignature(Request::create($bundle['download_url'], 'GET')));
        $this->assertStringContainsString('/DMS/backend/public/agent/releases/', $bundle['script_url']);
    }

    private function createAgentRelease(): AgentRelease
    {
        $fileName = 'dms-agent-1.0.0-win-x64-abcd1234.zip';
        $storagePath = 'agent-releases/test-agent.zip';
        $contents = 'fake-agent-bundle';

        Storage::disk('local')->put($storagePath, $contents);

        return AgentRelease::query()->create([
            'id' => (string) Str::uuid(),
            'version' => '1.0.0',
            'platform' => 'win-x64',
            'file_name' => $fileName,
            'storage_path' => $storagePath,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'notes' => null,
            'is_active' => true,
            'uploaded_by' => null,
        ]);
    }
}
