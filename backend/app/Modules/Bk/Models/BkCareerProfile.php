<?php

namespace App\Modules\Bk\Models;

use App\Models\Student;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Profil karier siswa.
 *
 * Hasil asesmen disimpan sebagai salah satu masukan, bukan kesimpulan. Siswa dapat
 * berpindah arah kapan saja tanpa hambatan administratif, karena modul ini tidak
 * pernah mengunci pilihan.
 *
 * Tabel: `bk_career_profiles`
 */
class BkCareerProfile extends BkModel
{
    protected $table = 'bk_career_profiles';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'interests' => 'array',
        'strengths' => 'array',
        'skills' => 'array',
        'preferred_subjects' => 'array',
        'assessment_inputs' => 'array',
        'alternative_options' => 'array',
        'last_reviewed_at' => 'datetime',
        'exploration_status' => \App\Modules\Bk\Domain\Enums\CareerExplorationStatus::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(BkCareerGuidanceSession::class, 'student_id', 'student_id');
    }

    public function scopeExploring(Builder $query): Builder
    {
        return $query->whereIn('exploration_status', ['NOT_STARTED', 'EXPLORING', 'NARROWED', 'REVIEWING']);
    }
}
