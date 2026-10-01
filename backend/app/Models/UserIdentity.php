<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIdentity extends Model
{
    protected $fillable = ['user_id', 'identity_type', 'identity_id', 'linked_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
