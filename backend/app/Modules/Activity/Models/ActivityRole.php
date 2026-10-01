<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Katalog peran anggota aktivitas (ketua, sekretaris, bendahara, mentor).
 *
 * Tabel: `activity_roles`
 */
class ActivityRole extends ActivityModel
{
    protected $table = 'activity_roles';

    protected $casts = [
        'can_lead' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
