<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\AdministrationStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pemberian obat (Medication Administration Record). Koreksi non-destruktif lewat versi.
 *
 * Tabel: `clinic_medication_administrations`
 */
class ClinicMedicationAdministration extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_medication_administrations';

    protected $casts = [
        'administered_at' => 'datetime',
        'version' => 'integer',
        'is_current' => 'boolean',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function prescriptionItem(): BelongsTo
    {
        return $this->belongsTo(ClinicPrescriptionItem::class, 'prescription_item_id');
    }
    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClinicMedication::class, 'medication_id');
    }
    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administered_by');
    }
    public function correctedFrom(): BelongsTo
    {
        return $this->belongsTo(ClinicMedicationAdministration::class, 'corrected_from_id');
    }
    public function statusEnum(): AdministrationStatus
    {
        return AdministrationStatus::from($this->status);
    }
}
