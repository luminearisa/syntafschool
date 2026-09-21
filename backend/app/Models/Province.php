<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends BaseModel
{
    public $incrementing = false;
    protected $keyType = 'string';

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }
}
