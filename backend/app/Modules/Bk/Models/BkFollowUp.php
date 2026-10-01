<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\FollowUpType;
use App\Modules\Bk\Domain\Enums\FollowUpStatus;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tindak lanjut terjadwal — pendorong ritme pemantauan kasus.

`is_overdue` adalah penanda yang diisi perintah terjadwal `bk:flag-overdue-follow-ups`,
bukan kolom yang dihitung ulang setiap permintaan.
 *
 * Tabel: `bk_follow_ups`
 */
class BkFollowUp extends BkModel
{
    protected $table = 'bk_follow_ups';

    use SoftDeletes;

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'rescheduled_to' => 'date',
        'is_overdue' => 'boolean',
        'type' => \App\Modules\Bk\Domain\Enums\FollowUpType::class,
        'status' => \App\Modules\Bk\Domain\Enums\FollowUpStatus::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function intervention(): BelongsTo
    {
        return $this->belongsTo(BkIntervention::class, 'intervention_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['PENDING', 'MISSED', 'RESCHEDULED']);
    }

    public function scopeDueOnOrBefore(Builder $query, string $date): Builder
    {
        return $query->open()->whereDate('due_date', '<=', $date);
    }
}
