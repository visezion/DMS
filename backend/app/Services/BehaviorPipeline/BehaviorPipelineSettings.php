<?php

namespace App\Services\BehaviorPipeline;

use App\Models\ControlPlaneSetting;
use Illuminate\Support\Facades\Storage;

class BehaviorPipelineSettings
{
    /**
     * @return array<string,mixed>
     */
    public function adaptiveModel(): array
    {
        $path = 'behavior_models/adaptive-learning.json';
        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        $decoded = json_decode((string) Storage::disk('local')->get($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function settingString(string $key, string $default): string
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        $value = $setting->value['value'] ?? $default;
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    public function settingFloat(string $key, float $default): float
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        $value = $setting->value['value'] ?? $default;
        return is_numeric($value) ? (float) $value : $default;
    }

    public function settingBool(string $key, bool $default): bool
    {
        $setting = ControlPlaneSetting::query()->find($key);
        if (! $setting || ! is_array($setting->value)) {
            return $default;
        }

        $value = $setting->value['value'] ?? $default;
        if (is_bool($value)) {
            return $value;
        }

        return filter_var((string) $value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
