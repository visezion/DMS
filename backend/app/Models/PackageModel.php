<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PackageModel extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'packages';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
}
