<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat posisi (§8): headcount, pembekuan, pemansuhan, perubahan atasan. */
class HrPositionHistory extends HrdModel
{
    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'effective_from' => 'date:Y-m-d',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
