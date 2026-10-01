<?php

namespace App\Modules\Bk\Models;

use App\Models\User;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Peninjauan kemajuan pengembangan.
 *
 * Urutannya Baseline lalu Review 1..n sampai Final, dan riwayatnya tidak dapat
 * ditulis mundur: nomor peninjauan unik per sasaran.
 *
 * Tabel: `bk_development_reviews`
 */
class BkDevelopmentReview extends BkModel
{
    protected $table = 'bk_development_reviews';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'reviewed_on' => 'date',
        'next_review_date' => 'date',
        'progress_value' => 'decimal:4',
        'review_type' => \App\Modules\Bk\Domain\Enums\DevelopmentReviewType::class,
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BkDevelopmentPlan::class, 'plan_id');
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(BkDevelopmentGoal::class, 'goal_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
