<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Umpan balik siswa dan orang tua.
 *
 * Umpan balik yang sudah FINAL tidak dapat diubah siapa pun, termasuk konselor:
 * yang boleh dilakukan konselor hanyalah menandai sudah dibaca. Aturan itu dijaga
 * layanan, bukan sekadar disembunyikan dari layar.
 *
 * Tabel: `bk_feedback`
 */
class BkFeedback extends BkModel
{
    protected $table = 'bk_feedback';

    use BelongsToSchool;

    protected $casts = [
        'is_anonymous' => 'boolean',
        'submitted_at' => 'datetime',
        'finalized_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'visible_to_student' => 'boolean',
        'visible_to_counselor' => 'boolean',
        'context' => 'array',
        'subject_type' => \App\Modules\Bk\Domain\Enums\FeedbackSubjectType::class,
        'submitted_by_type' => \App\Modules\Bk\Domain\Enums\ConferenceParticipantType::class,
        'status' => \App\Modules\Bk\Domain\Enums\FeedbackStatus::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'guardian_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', '!=', 'WITHDRAWN');
    }

    public function scopeForSubject(Builder $query, string $subjectType, int $subjectId): Builder
    {
        return $query->where('subject_type', $subjectType)->where('subject_id', $subjectId);
    }
}
