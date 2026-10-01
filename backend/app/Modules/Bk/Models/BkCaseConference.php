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
 * Konferensi kasus: pertemuan lintas pihak untuk satu kasus.
 *
 * Konferensi bukan kanal berbagi catatan. Keputusan konferensi berdiri sendiri,
 * sedangkan catatan konseling tetap berada di balik kewenangan konseling.
 *
 * Tabel: `bk_case_conferences`
 */
class BkCaseConference extends BkModel
{
    protected $table = 'bk_case_conferences';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'agenda' => 'array',
        'follow_up_required' => 'boolean',
        'status' => \App\Modules\Bk\Domain\Enums\ConferenceStatus::class,
        'mode' => \App\Modules\Bk\Domain\Enums\SessionMode::class,
        'decision_outcome' => \App\Modules\Bk\Domain\Enums\ScreeningOutcome::class,
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

    public function participants(): HasMany
    {
        return $this->hasMany(BkCaseConferenceParticipant::class, 'conference_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(BkCaseConferenceAction::class, 'conference_id');
    }

    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitator_id');
    }

    public function minutes(): BelongsTo
    {
        return $this->belongsTo(BkCaseDocument::class, 'minutes_document_id');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('scheduled_start', '>=', now())->whereIn('status', ['SCHEDULED', 'RESCHEDULED']);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['DRAFT', 'SCHEDULED', 'IN_PROGRESS', 'RESCHEDULED']);
    }
}
