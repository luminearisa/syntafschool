<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class School extends BaseModel
{
    protected $casts = ['is_active' => 'boolean'];

    public function foundation(): BelongsTo
    {
        return $this->belongsTo(Foundation::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }
}
