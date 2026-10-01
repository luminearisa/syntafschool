<?php

namespace App\Modules\Bk\Models;

use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rencana pengembangan siswa.
 *
 * Dipakai untuk menumbuhkan, bukan hanya menangani masalah, sehingga rencana ini
 * tidak mensyaratkan adanya kasus: seorang siswa boleh punya rencana kemandirian
 * tanpa pernah memiliki catatan masalah.
 *
 * Tabel: `bk_development_plans`
 */
class BkDevelopmentPlan extends BkModel
{
    protected $table = 'bk_development_plans';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'start_date' => 'date',
        'target_end_date' => 'date',
        'next_review_date' => 'date',
        'completed_at' => 'datetime',
        'domain' => \App\Modules\Bk\Domain\Enums\DevelopmentDomain::class,
        'status' => \App\Modules\Bk\Domain\Enums\PlanStatus::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(BkDevelopmentGoal::class, 'plan_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(BkDevelopmentActivity::class, 'plan_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(BkDevelopmentReview::class, 'plan_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['ACTIVE', 'IN_PROGRESS']);
    }

    public function scopeReviewDue(Builder $query): Builder
    {
        return $query->active()->whereNotNull('next_review_date')->whereDate('next_review_date', '<=', today());
    }
}
