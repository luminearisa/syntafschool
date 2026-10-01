<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\AssessmentStatus;
use App\Modules\Bk\Domain\Enums\AssessmentTarget;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Asesmen yang diberikan kepada siswa/orang tua/guru/konselor.

Asesmen **bukan diagnosis otomatis**: skor dan ringkasan hanya alat bantu
pertimbangan konselor.
 *
 * Tabel: `bk_assessments`
 */
class BkAssessment extends BkModel
{
    protected $table = 'bk_assessments';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'assigned_at' => 'datetime',
        'due_date' => 'date',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'status' => \App\Modules\Bk\Domain\Enums\AssessmentStatus::class,
        'target' => \App\Modules\Bk\Domain\Enums\AssessmentTarget::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
        'consent_required' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(BkAssessmentTemplate::class, 'template_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(BkReferral::class, 'referral_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondent_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(BkAssessmentResponse::class, 'assessment_id');
    }

    /** Asesmen yang masih menunggu jawaban responden. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['DRAFT', 'SENT', 'IN_PROGRESS']);
    }
}
