<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobRun extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'acked_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'result_payload' => 'array',
        ];
    }
}
