<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DmsJob extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'jobs';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'not_before' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
