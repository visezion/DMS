<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobEvent extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
