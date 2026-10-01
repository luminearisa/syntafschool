<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\TransferStatus;

/**
 * Pindah aktivitas — keanggotaan lama ditutup, bukan ditimpa; keanggotaan baru dibuat terpisah.
 *
 * Tabel: `activity_transfers`
 */
class ActivityTransfer extends ActivityModel
{
    protected $table = 'activity_transfers';

    protected $casts = [
        'status' => TransferStatus::class,
        'conflict_snapshot' => 'array',
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
    ];
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function fromMembership(): BelongsTo
    {
        return $this->belongsTo(ActivityMembership::class, 'from_membership_id');
    }
    public function toProgram(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'to_program_id');
    }
    public function toMembership(): BelongsTo
    {
        return $this->belongsTo(ActivityMembership::class, 'to_membership_id');
    }
    public function requester(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }
}
