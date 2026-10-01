<?php

namespace App\Modules\Bk\Models;

use App\Models\User;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tindak lanjut hasil konferensi.
 *
 * Setiap keputusan wajib punya penanggung jawab dan tenggat; tanpa keduanya,
 * konferensi hanya menjadi catatan rapat.
 *
 * Tabel: `bk_case_conference_actions`
 */
class BkCaseConferenceAction extends BkModel
{
    protected $table = 'bk_case_conference_actions';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'status' => \App\Modules\Bk\Domain\Enums\ConferenceActionStatus::class,
        'priority' => \App\Modules\Bk\Domain\Enums\Priority::class,
    ];

    public function conference(): BelongsTo
    {
        return $this->belongsTo(BkCaseConference::class, 'conference_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(BkFollowUp::class, 'follow_up_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['PENDING', 'IN_PROGRESS', 'OVERDUE']);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_date')->whereDate('due_date', '<', today());
    }
}
