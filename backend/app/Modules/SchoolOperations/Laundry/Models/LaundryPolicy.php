<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kebijakan jatah laundry gratis (§5). Isi kebijakan hidup di versi-versi yang tidak pernah diubah.
 */
class LaundryPolicy extends Model
{
    protected $table = 'laundry_policies';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(LaundryPolicyVersion::class)->latest('version');
    }
}
