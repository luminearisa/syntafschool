<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Student;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\ScreeningResult;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hasil skrining kesehatan per siswa.
 *
 * Tabel: `clinic_screenings`
 */
class ClinicScreening extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_screenings';

    protected $casts = [
        'screened_at' => 'date',
        'height_cm' => 'decimal:2',
        'weight_kg' => 'decimal:2',
        'bmi' => 'decimal:2',
        'follow_up_required' => 'boolean',
        'parent_notified' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(ClinicScreeningProgram::class, 'program_id');
    }
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
    public function screenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'screened_by');
    }
    public function resultEnum(): ScreeningResult
    {
        return ScreeningResult::from($this->result);
    }
}
