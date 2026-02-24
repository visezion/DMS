<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PolicyVersion extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
}
