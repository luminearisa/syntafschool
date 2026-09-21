<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageLocation extends BaseModel
{
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
