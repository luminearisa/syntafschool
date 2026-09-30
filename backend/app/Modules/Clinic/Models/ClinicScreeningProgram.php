<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use App\Models\AcademicYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Program skrining berkala (tahunan, gigi, mata, gizi).
 *
 * Tabel: `clinic_screening_programs`
 */
class ClinicScreeningProgram extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_screening_programs';

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'notify_parent' => 'boolean',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function screenings(): HasMany
    {
        return $this->hasMany(ClinicScreening::class, 'program_id');
    }}
