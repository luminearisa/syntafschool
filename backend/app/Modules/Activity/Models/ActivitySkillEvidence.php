<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\RubricLevel;
use App\Modules\Activity\Domain\Enums\SkillEvidenceSource;

/**
 * Bukti keterampilan siswa yang berasal dari penilaian, prestasi, proyek, atau kepemimpinan.
 *
 * Tabel: `activity_skill_evidence`
 */
class ActivitySkillEvidence extends ActivityModel
{
    protected $table = 'activity_skill_evidence';

    protected $casts = [
        'source' => SkillEvidenceSource::class,
        'level' => RubricLevel::class,
        'score' => 'decimal:2',
        'recorded_at' => 'datetime',
    ];
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function skill(): BelongsTo
    {
        return $this->belongsTo(ActivitySkill::class, 'skill_id');
    }
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function membership(): BelongsTo
    {
        return $this->belongsTo(ActivityMembership::class, 'membership_id');
    }
}
