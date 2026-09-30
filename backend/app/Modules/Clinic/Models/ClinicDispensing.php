<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Peristiwa penyerahan obat ke pasien.
 *
 * Tabel: `clinic_dispensings`
 */
class ClinicDispensing extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_dispensings';

    protected $casts = [
        'dispensed_at' => 'datetime',
    ];

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(ClinicPrescription::class, 'prescription_id');
    }
    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function dispensedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensed_by');
    }
    public function items(): HasMany
    {
        return $this->hasMany(ClinicDispensingItem::class, 'dispensing_id');
    }}
