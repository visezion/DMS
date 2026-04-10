<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssistantMessage extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public $timestamps = false;
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'citations' => 'array',
            'token_usage' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
