<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\WithdrawalStatus;

/**
 * Permohonan berhenti dari aktivitas beserta alasan dan keputusannya.
 *
 * Tabel: `activity_withdrawals`
 */
class ActivityWithdrawal extends ActivityModel
{
    protected $table = 'activity_withdrawals';

    protected $casts = [
        'status' => WithdrawalStatus::class,
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
        'effective_immediately' => 'boolean',
    ];
    public function membership(): BelongsTo
    {
        return $this->belongsTo(ActivityMembership::class, 'membership_id');
    }
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function requester(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }
    public function decider(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'decided_by');
    }
}
