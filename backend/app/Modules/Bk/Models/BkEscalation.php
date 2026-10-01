<?php

namespace App\Modules\Bk\Models;

use App\Models\User;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eskalasi berjenjang: konselor, koordinator BK, lalu manajemen.
 *
 * Eskalasi memiliki akhir yang jelas: diakui lalu diselesaikan oleh orang, bukan
 * menghilang setelah dibaca.
 *
 * Tabel: `bk_escalations`
 */
class BkEscalation extends BkModel
{
    protected $table = 'bk_escalations';

    use BelongsToSchool;

    protected $casts = [
        'escalated_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'automatic' => 'boolean',
        'triggers' => 'array',
        'subject_type' => \App\Modules\Bk\Domain\Enums\SlaSubjectType::class,
        'from_level' => \App\Modules\Bk\Domain\Enums\EscalationLevel::class,
        'to_level' => \App\Modules\Bk\Domain\Enums\EscalationLevel::class,
        'status' => \App\Modules\Bk\Domain\Enums\EscalationStatus::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function slaEvent(): BelongsTo
    {
        return $this->belongsTo(BkSlaEvent::class, 'sla_event_id');
    }

    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['PENDING', 'ACKNOWLEDGED']);
    }
}
