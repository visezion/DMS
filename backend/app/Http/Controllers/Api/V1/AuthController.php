<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken($credentials['device_name'] ?? 'admin-api')->plainTextToken;
        $auditLogger->log('auth.login', 'user', (string) $user->id, null, ['email' => $user->email], (int) $user->id);

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->user();
        $request->user()->currentAccessToken()?->delete();
        $auditLogger->log('auth.logout', 'user', (string) $user->id, null, null, (int) $user->id);

        return response()->json(['message' => 'Logged out']);
    }
}
