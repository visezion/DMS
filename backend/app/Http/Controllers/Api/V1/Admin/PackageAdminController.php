<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PackageFile;
use App\Models\PackageModel;
use App\Models\PackageVersion;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PackageAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(PackageModel::query()->paginate(25));
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string'],
            'slug' => ['required', 'string'],
            'package_type' => ['required', 'string'],
            'publisher' => ['nullable', 'string'],
        ]);

        $package = PackageModel::query()->create(['id' => (string) Str::uuid(), ...$payload]);
        $auditLogger->log('package.create', 'package', $package->id, null, $package->toArray(), $request->user()?->id);

        return response()->json($package, 201);
    }

    public function addVersion(Request $request, string $packageId, AuditLogger $auditLogger): JsonResponse
    {
        $payload = $request->validate([
            'version' => ['required', 'string'],
            'channel' => ['nullable', 'string'],
            'install_args' => ['nullable', 'array'],
            'detection_rules' => ['required', 'array'],
            'file' => ['nullable', 'array'],
        ]);

        $version = PackageVersion::query()->create([
            'id' => (string) Str::uuid(),
            'package_id' => $packageId,
            'version' => $payload['version'],
            'channel' => $payload['channel'] ?? 'stable',
            'install_args' => $payload['install_args'] ?? null,
            'detection_rules' => $payload['detection_rules'],
        ]);

        if (! empty($payload['file'])) {
            PackageFile::query()->create([
                'id' => (string) Str::uuid(),
                'package_version_id' => $version->id,
                'file_name' => $payload['file']['file_name'],
                'source_uri' => $payload['file']['source_uri'],
                'size_bytes' => $payload['file']['size_bytes'],
                'sha256' => $payload['file']['sha256'],
                'signature_type' => $payload['file']['signature_type'] ?? null,
                'signature_metadata' => $payload['file']['signature_metadata'] ?? null,
            ]);
        }

        $auditLogger->log('package.version.create', 'package_version', $version->id, null, $version->toArray(), $request->user()?->id);
        return response()->json($version, 201);
    }
}
