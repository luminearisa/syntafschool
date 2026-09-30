<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Catatan imunisasi (bukan pengganti registry resmi).
 *
 * Tabel: `clinic_vaccinations`
 */
class ClinicVaccination extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_vaccinations';

    protected $casts = [
        'given_at' => 'date',
        'next_due_at' => 'date',
        'dose' => 'integer',
        'consent_obtained' => 'boolean',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(ClinicLocation::class, 'clinic_id');
    }
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }}
