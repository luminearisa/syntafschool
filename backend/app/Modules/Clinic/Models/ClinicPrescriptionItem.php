<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Baris resep: obat, dosis, frekuensi, jumlah.
 *
 * Tabel: `clinic_prescription_items`
 */
class ClinicPrescriptionItem extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_prescription_items';

    protected $casts = [
        'quantity' => 'decimal:2',
        'dispensed_quantity' => 'decimal:2',
    ];

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(ClinicPrescription::class, 'prescription_id');
    }
    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClinicMedication::class, 'medication_id');
    }
    public function remainingQuantity(): float
    {
        return max(0.0, round((float) $this->quantity - (float) $this->dispensed_quantity, 2));
    }

    public function isFullyDispensed(): bool
    {
        return $this->remainingQuantity() <= 0.0001;
    }
}
