<?php

namespace App\Modules\Bk\Models;

use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pemantauan SLA satu pekerjaan.
 *
 * Setiap baris memuat cuplikan kebijakan yang berlaku saat itu, sehingga perubahan
 * kebijakan di kemudian hari tidak menulis ulang sejarah.
 *
 * Tabel: `bk_sla_events`
 */
class BkSlaEvent extends BkModel
{
    protected $table = 'bk_sla_events';

    use BelongsToSchool;

    protected $casts = [
        'started_at' => 'datetime',
        'due_at' => 'datetime',
        'warn_at' => 'datetime',
        'completed_at' => 'datetime',
        'breached_at' => 'datetime',
        'warning_sent' => 'boolean',
        'policy_snapshot' => 'array',
        'context' => 'array',
        'subject_type' => \App\Modules\Bk\Domain\Enums\SlaSubjectType::class,
        'status' => \App\Modules\Bk\Domain\Enums\SlaStatus::class,
        'current_level' => \App\Modules\Bk\Domain\Enums\EscalationLevel::class,
        'priority' => \App\Modules\Bk\Domain\Enums\Priority::class,
        'severity' => \App\Modules\Bk\Domain\Enums\Severity::class,
        'case_type' => \App\Modules\Bk\Domain\Enums\CaseType::class,
        'case_category' => \App\Modules\Bk\Domain\Enums\ReferralCategory::class,
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(BkSlaPolicy::class, 'policy_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(BkReferral::class, 'referral_id');
    }

    public function escalation(): HasOne
    {
        return $this->hasOne(BkEscalation::class, 'sla_event_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['RUNNING', 'WARNING_SENT']);
    }

    public function scopeBreached(Builder $query): Builder
    {
        return $query->whereIn('status', ['BREACHED', 'ESCALATED']);
    }
}
