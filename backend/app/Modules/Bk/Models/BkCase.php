<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\CaseStatus;
use App\Modules\Bk\Domain\Enums\Priority;
use App\Modules\Bk\Domain\Enums\Severity;
use App\Modules\Bk\Domain\Enums\ReferralCategory;
use App\Modules\Bk\Domain\Enums\CaseType;
use App\Modules\Bk\Domain\Enums\EscalationLevel;
use App\Modules\Bk\Domain\Enums\ClosureReason;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kasus BK — simpul utama seluruh alur: asesmen, konseling, rencana,
intervensi, tindak lanjut, hingga penutupan.

Kasus memegang `confidentiality_level` yang menjadi **langit-langit** kerahasiaan
bagi seluruh turunannya (sesi, catatan, dokumen): turunan tidak boleh lebih
terbuka daripada kasusnya.
 *
 * Tabel: `bk_cases`
 */
class BkCase extends BkModel
{
    protected $table = 'bk_cases';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'opened_at' => 'datetime',
        'last_reviewed_at' => 'datetime',
        'escalated_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'target_review_date' => 'date',
        'next_review_date' => 'date',
        'risk_flag' => 'boolean',
        'status' => \App\Modules\Bk\Domain\Enums\CaseStatus::class,
        'priority' => \App\Modules\Bk\Domain\Enums\Priority::class,
        'severity' => \App\Modules\Bk\Domain\Enums\Severity::class,
        'category' => \App\Modules\Bk\Domain\Enums\ReferralCategory::class,
        'case_type' => \App\Modules\Bk\Domain\Enums\CaseType::class,
        'escalation_level' => \App\Modules\Bk\Domain\Enums\EscalationLevel::class,
        'closure_reason' => \App\Modules\Bk\Domain\Enums\ClosureReason::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function sourceReferral(): BelongsTo
    {
        return $this->belongsTo(BkReferral::class, 'source_referral_id');
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_counselor_id');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(BkCaseAssignment::class, 'case_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(BkCaseParticipant::class, 'case_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BkCaseEvent::class, 'case_id');
    }

    public function screenings(): HasMany
    {
        return $this->hasMany(BkScreening::class, 'case_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(BkAssessment::class, 'case_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(BkCounselingSession::class, 'case_id');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(BkCounselingPlan::class, 'case_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(BkGoal::class, 'case_id');
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(BkIntervention::class, 'case_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(BkFollowUp::class, 'case_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BkCaseDocument::class, 'case_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(BkCaseNote::class, 'case_id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(BkParentMeeting::class, 'case_id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(BkConsent::class, 'case_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(BkAppointment::class, 'case_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BkTag::class, 'bk_case_tags', 'case_id', 'tag_id');
    }

    /** Kasus yang masih berjalan (belum RESOLVED/CLOSED). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['RESOLVED', 'CLOSED']);
    }

    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_counselor_id', $userId);
    }

    /** Kasus yang tenggat tinjauannya sudah lewat (dasar pengingat & widget). */
    public function scopeReviewOverdue(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull('target_review_date')
            ->whereDate('target_review_date', '<', now()->toDateString());
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }

}
