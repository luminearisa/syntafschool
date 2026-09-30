<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\AllergySeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Alergi pasien; dasar peringatan saat peresepan dan pemberian obat.
 *
 * Tabel: `clinic_allergies`
 */
class ClinicAllergy extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_allergies';

    use SoftDeletes;

    protected $casts = [
        'identified_at' => 'date',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClinicMedication::class, 'medication_id');
    }
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
    public function isCritical(): bool
    {
        return $this->severity === 'SEVERE';
    }

    public function severityEnum(): AllergySeverity
    {
        return AllergySeverity::from($this->severity);
    }

    /** @param Builder<self> $query */
    public function scopeMedication(Builder $query): void
    {
        $query->where('category', 'MEDICATION');
    }
}
