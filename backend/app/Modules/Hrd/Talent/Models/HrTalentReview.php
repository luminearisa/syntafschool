<?php

namespace App\Modules\Hrd\Talent\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Talent\Models\HrTalentReviewResult;

/**
 * Phase 2 — tabel `hr_talent_reviews` (domain Talent).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrTalentReview extends HrdModel
{
    protected $table = 'hr_talent_reviews';

    protected $guarded = [];
    protected $casts = [
        'scheduled_at' => 'datetime',
        'participants' => 'array',
        'meta' => 'array',
    ];

    public function results(): HasMany
    {
        return $this->hasMany(HrTalentReviewResult::class, 'review_id');
    }
}
