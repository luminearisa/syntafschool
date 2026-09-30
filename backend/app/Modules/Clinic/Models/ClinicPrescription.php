<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\PrescriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Resep obat yang diterbitkan penyedia layanan.
 *
 * Tabel: `clinic_prescriptions`
 */
class ClinicPrescription extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_prescriptions';

    use SoftDeletes;

    protected $casts = [
        'prescribed_at' => 'datetime',
        'allergy_alerts' => 'array',
        'allergy_override' => 'boolean',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
    public function items(): HasMany
    {
        return $this->hasMany(ClinicPrescriptionItem::class, 'prescription_id');
    }
    public function dispensings(): HasMany
    {
        return $this->hasMany(ClinicDispensing::class, 'prescription_id');
    }
    public function statusEnum(): PrescriptionStatus
    {
        return PrescriptionStatus::from($this->status);
    }

    public function isDispensable(): bool
    {
        return $this->statusEnum()->isDispensable();
    }
}
