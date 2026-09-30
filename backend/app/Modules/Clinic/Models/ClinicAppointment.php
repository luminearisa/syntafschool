<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Janji temu pasien dengan penyedia layanan.
 *
 * Tabel: `clinic_appointments`
 */
class ClinicAppointment extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_appointments';

    protected $casts = [
        'appointment_date' => 'date',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(ClinicLocation::class, 'clinic_id');
    }
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    /** @param Builder<self> $query */
    public function scopeForDate(Builder $query, string $date): void
    {
        $query->whereDate('appointment_date', $date);
    }
}
