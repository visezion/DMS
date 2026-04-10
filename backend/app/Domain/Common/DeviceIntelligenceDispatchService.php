<?php

namespace App\Domain\Common;

use App\Jobs\BuildDeviceIntelligenceJob;
use Illuminate\Support\Facades\Cache;

class DeviceIntelligenceDispatchService
{
    public function dispatch(string $deviceId, bool $immediate = false): bool
    {
        if ($immediate) {
            BuildDeviceIntelligenceJob::dispatchSync($deviceId);
            $this->markRecentlyQueued($deviceId);

            return true;
        }

        $debounceSeconds = max(5, (int) config('services.endpoint_intelligence.dispatch_debounce_seconds', 45));
        $key = $this->cacheKey($deviceId);

        if (! Cache::add($key, now()->toIso8601String(), $debounceSeconds)) {
            return false;
        }

        BuildDeviceIntelligenceJob::dispatch($deviceId);

        return true;
    }

    public function markRecentlyQueued(string $deviceId): void
    {
        $debounceSeconds = max(5, (int) config('services.endpoint_intelligence.dispatch_debounce_seconds', 45));
        Cache::put($this->cacheKey($deviceId), now()->toIso8601String(), $debounceSeconds);
    }

    private function cacheKey(string $deviceId): string
    {
        return 'endpoint-intelligence:dispatch:'.$deviceId;
    }
}

