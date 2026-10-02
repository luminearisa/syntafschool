<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat perubahan struktur organisasi (§5: history perubahan struktur). */
class HrOrganizationHistory extends HrdModel
{
    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'effective_from' => 'date:Y-m-d',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
