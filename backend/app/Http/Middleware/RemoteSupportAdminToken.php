<?php

namespace App\Http\Middleware;

use App\Models\RemoteSupportSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RemoteSupportAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            return $next($request);
        }

        $token = trim((string) ($request->header('X-Remote-Support-Token')
            ?? $request->query('token')
            ?? $request->input('token')
            ?? ''));

        if ($token === '') {
            return response()->json([
                'message' => 'Remote support token required.',
            ], 401);
        }

        $hash = hash('sha256', $token);
        $record = Cache::get($this->tokenCacheKey($hash));
        if (! is_array($record)) {
            return response()->json([
                'message' => 'Remote support token expired. Reload the page and sign in again.',
            ], 401);
        }

        $sessionId = (string) ($request->route('sessionId') ?? '');
        $recordSessionId = (string) ($record['session_id'] ?? '');
        if ($sessionId !== '' && $recordSessionId !== '' && $sessionId !== $recordSessionId) {
            return response()->json([
                'message' => 'Remote support token does not match the session.',
            ], 401);
        }

        if ($sessionId !== '') {
            $session = RemoteSupportSession::query()->find($sessionId);
            if (! $session || $session->status === 'closed') {
                return response()->json([
                    'message' => 'Remote support session is no longer active.',
                ], 401);
            }
        }

        return $next($request);
    }

    private function tokenCacheKey(string $hash): string
    {
        return 'remote_support:admin_token:'.$hash;
    }
}
