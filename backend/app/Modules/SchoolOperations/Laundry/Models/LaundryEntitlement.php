<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jatah gratis satu santri untuk satu periode (§6/§7).
 * Sisa = allocated + carry_in + adjusted - used. Kolom used_quota hanya
 * bergerak lewat LaundryEntitlementService (baris dikunci, ledger tercatat).
 * Baris historis tidak pernah diubah oleh perubahan policy (Rule 22).
 */
class LaundryEntitlement extends Model
{
    protected $table = 'laundry_entitlements';

    protected $guarded = ['id'];

    protected $casts = [
        'allocated_quota' => 'decimal:3',
        'carry_in_quota' => 'decimal:3',
        'adjusted_quota' => 'decimal:3',
        'used_quota' => 'decimal:3',
        'period_start' => 'date',
        'period_end' => 'date',
        'expired_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(LaundryPolicyVersion::class, 'laundry_policy_version_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LaundryEntitlementTransaction::class)->orderBy('id');
    }

    /** Sisa jatah (tidak pernah negatif — Rule 6). */
    public function remaining(): float
    {
        return max(0.0, round(
            (float) $this->allocated_quota + (float) $this->carry_in_quota
            + (float) $this->adjusted_quota - (float) $this->used_quota,
            3
        ));
    }
}
