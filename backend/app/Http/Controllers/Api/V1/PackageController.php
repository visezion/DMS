<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PackageFile;
use App\Models\PackageVersion;
use Illuminate\Http\JsonResponse;

class PackageController extends Controller
{
    public function downloadMeta(string $packageVersionId): JsonResponse
    {
        $version = PackageVersion::query()->findOrFail($packageVersionId);
        $file = PackageFile::query()->where('package_version_id', $version->id)->first();

        return response()->json([
            'package_version_id' => $version->id,
            'version' => $version->version,
            'file' => $file ? [
                'file_name' => $file->file_name,
                'source_uri' => $file->source_uri,
                'size_bytes' => $file->size_bytes,
                'sha256' => $file->sha256,
                'signature_type' => $file->signature_type,
                'signature_metadata' => $file->signature_metadata,
            ] : null,
            'install_args' => $version->install_args,
            'detection_rules' => $version->detection_rules,
        ]);
    }
}
