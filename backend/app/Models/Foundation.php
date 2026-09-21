<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Foundation extends BaseModel
{
    protected $casts = ['is_active' => 'boolean'];

    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }
}
