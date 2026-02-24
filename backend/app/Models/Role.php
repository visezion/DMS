<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }
}
