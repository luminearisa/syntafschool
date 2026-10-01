<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\ChargeStatus;
use App\Modules\Activity\Domain\Enums\ChargeType;

/**
 * Permintaan tagihan ke modul Keuangan — modul ini tidak menghitung pembayaran sendiri.
 *
 * Tabel: `activity_charges`
 */
class ActivityCharge extends ActivityModel
{
    protected $table = 'activity_charges';

    protected $casts = [
        'type' => ChargeType::class,
        'status' => ChargeStatus::class,
        'amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'settled_at' => 'datetime',
        'metadata' => 'array',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function registration(): BelongsTo
    {
        return $this->belongsTo(ActivityRegistration::class, 'registration_id');
    }
    public function membership(): BelongsTo
    {
        return $this->belongsTo(ActivityMembership::class, 'membership_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function requester(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }
}
