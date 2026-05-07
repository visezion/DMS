<?php

namespace App\Http\Middleware;

use App\Models\ControlPlaneSetting;
use App\Support\LocaleManager;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPreferredLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = LocaleManager::normalize((string) config('app.locale', 'en'));

        $sessionLocale = $request->session()->get('locale');
        if (is_string($sessionLocale) && LocaleManager::isSupported($sessionLocale)) {
            $locale = LocaleManager::normalize($sessionLocale);
        } elseif ($request->user()) {
            $profileSetting = ControlPlaneSetting::query()->find('users.profile.'.$request->user()->id);
            $profile = is_array($profileSetting?->value ?? null) ? ($profileSetting->value['value'] ?? []) : [];
            $preferredLocale = is_array($profile) ? ($profile['locale'] ?? null) : null;

            if (is_string($preferredLocale) && $preferredLocale !== '' && LocaleManager::isSupported($preferredLocale)) {
                $locale = LocaleManager::normalize($preferredLocale);
            }

            $request->session()->put('locale', $locale);
        }

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
