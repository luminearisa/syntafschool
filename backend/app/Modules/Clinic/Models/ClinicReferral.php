<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\ReferralStatus;
use App\Modules\Clinic\Domain\Enums\ReferralUrgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rujukan ke fasilitas kesehatan eksternal.
 *
 * Tabel: `clinic_referrals`
 */
class ClinicReferral extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_referrals';

    use SoftDeletes;

    protected $casts = [
        'referral_date' => 'datetime',
        'arrived_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function emergency(): BelongsTo
    {
        return $this->belongsTo(ClinicEmergency::class, 'emergency_id');
    }
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function followUps(): HasMany
    {
        return $this->hasMany(ClinicFollowUp::class, 'referral_id');
    }
    public function statusEnum(): ReferralStatus
    {
        return ReferralStatus::from($this->status);
    }

    public function urgencyEnum(): ReferralUrgency
    {
        return ReferralUrgency::from($this->urgency);
    }
}
