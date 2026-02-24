<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CommandEnvelopeSigner;
use Illuminate\Http\JsonResponse;

class KeysetController extends Controller
{
    public function index(CommandEnvelopeSigner $signer): JsonResponse
    {
        $signer->ensureActiveKey();

        return response()->json([
            'schema' => 'dms.keyset.v1',
            'generated_at' => now()->toIso8601String(),
            'keys' => $signer->keyset(),
        ]);
    }
}
