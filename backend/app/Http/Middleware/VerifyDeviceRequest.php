<?php

namespace App\Http\Middleware;

use App\Domain\Common\DeviceRequestAuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifyDeviceRequest
{
    public function __construct(
        private readonly DeviceRequestAuthService $deviceAuth,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $result = $this->deviceAuth->authorize($request);

        if (! $result['allowed']) {
            return response()->json([
                'message' => 'Device request authentication failed.',
                'reason' => $result['reason'],
            ], 401);
        }

        $this->logLegacyAcceptance($request, $result);

        $request->attributes->set('device_request_auth', $result);
        if ($result['device']) {
            $request->attributes->set('authenticated_device', $result['device']);
        }

        return $next($request);
    }

    /**
     * @param array{should_log_legacy:bool,device:mixed,reason:string} $result
     */
    private function logLegacyAcceptance(Request $request, array $result): void
    {
        if (! $result['should_log_legacy']) {
            return;
        }

        try {
            $route = (string) optional($request->route())->uri();
            $deviceId = (string) optional($result['device'])->id;
            $identity = $deviceId !== '' ? $deviceId : ((string) $request->ip() !== '' ? (string) $request->ip() : 'unknown');
            $intervalSeconds = max(60, (int) config('services.endpoint_intelligence.legacy_request_log_interval_seconds', 900));
            $cacheKey = sprintf(
                'device-auth-legacy-log:%s:%s:%s',
                sha1($route),
                sha1((string) $result['reason']),
                sha1($identity)
            );

            if (! Cache::add($cacheKey, now()->timestamp, now()->addSeconds($intervalSeconds))) {
                return;
            }

            Log::warning('Legacy unsigned device request accepted.', [
                'route' => $route,
                'device_id' => $deviceId !== '' ? $deviceId : null,
                'reason' => $result['reason'],
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'log_interval_seconds' => $intervalSeconds,
            ]);
        } catch (Throwable) {
            // Logging is best-effort only and must never block device traffic.
        }
    }
}
