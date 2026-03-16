<?php

namespace App\Models;

use App\Models\Builders\ControlPlaneSettingBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ControlPlaneSetting extends Model
{
    use HasFactory;

    private const TENANT_KEY_PREFIX = 'tenant:';

    protected $table = 'control_plane_settings';
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function newEloquentBuilder($query): ControlPlaneSettingBuilder
    {
        return new ControlPlaneSettingBuilder($query);
    }

    public static function scopedKey(string $key, ?string $tenantId): string
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            return $trimmed;
        }

        if (str_starts_with($trimmed, self::TENANT_KEY_PREFIX)) {
            return $trimmed;
        }

        $tenant = is_string($tenantId) ? trim($tenantId) : '';
        if ($tenant === '') {
            return $trimmed;
        }

        return self::TENANT_KEY_PREFIX.$tenant.':'.$trimmed;
    }
}
