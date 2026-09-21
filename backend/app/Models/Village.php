<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Village extends BaseModel
{
    public $incrementing = false;
    protected $keyType = 'string';

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
