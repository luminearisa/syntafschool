<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Curriculum extends BaseModel
{
    protected $casts = ['is_active' => 'boolean'];

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }
}
