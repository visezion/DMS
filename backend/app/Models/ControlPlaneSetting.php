<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ControlPlaneSetting extends Model
{
    use HasFactory;

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
}
