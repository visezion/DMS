<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ControlPlaneSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function login(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string'],
        ]);

        $email = strtolower(trim((string) $credentials['email']));
        $policy = $this->authPolicy();
        $throttleKey = $this->loginThrottleKey($email, (string) $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, $policy['max_login_attempts'])) {
            $retryAfter = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => 'Too many login attempts. Try again later.',
                'retry_after_seconds' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user || ! (bool) $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, $policy['lockout_minutes'] * 60);

            if (RateLimiter::tooManyAttempts($throttleKey, $policy['max_login_attempts'])) {
                $retryAfter = RateLimiter::availableIn($throttleKey);

                return response()->json([
                    'message' => 'Too many login attempts. Try again later.',
                    'retry_after_seconds' => $retryAfter,
                ], 429)->header('Retry-After', (string) $retryAfter);
            }

            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        RateLimiter::clear($throttleKey);

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

    /**
     * @return array{max_login_attempts:int,lockout_minutes:int}
     */
    private function authPolicy(): array
    {
        return [
            'max_login_attempts' => max(1, $this->settingInt('auth.max_login_attempts', 5)),
            'lockout_minutes' => max(1, $this->settingInt('auth.lockout_minutes', 15)),
        ];
    }

    private function loginThrottleKey(string $email, string $ip): string
    {
        return 'api.auth.login:'.sha1($email.'|'.$ip);
    }

    private function settingInt(string $key, int $default): int
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        return (int) ($setting->value['value'] ?? $default);
    }
}
