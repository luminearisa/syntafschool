<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne};
use App\Models\Student;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\PatientType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rekam medis induk. TIDAK menduplikasi master siswa: nama/kelas/program selalu diambil dari StudentProviderInterface.
 *
 * Tabel: `clinic_patients`
 */
class ClinicPatient extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_patients';

    use SoftDeletes;

    protected $casts = [
        'allergy_alert' => 'boolean',
        'special_care_alert' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function visits(): HasMany
    {
        return $this->hasMany(ClinicVisit::class, 'patient_id');
    }
    public function vitals(): HasMany
    {
        return $this->hasMany(ClinicVitalSign::class, 'patient_id');
    }
    public function allergies(): HasMany
    {
        return $this->hasMany(ClinicAllergy::class, 'patient_id');
    }
    public function specialConditions(): HasMany
    {
        return $this->hasMany(ClinicSpecialCondition::class, 'patient_id');
    }
    public function medicalHistories(): HasMany
    {
        return $this->hasMany(ClinicMedicalHistory::class, 'patient_id');
    }
    public function screenings(): HasMany
    {
        return $this->hasMany(ClinicScreening::class, 'patient_id');
    }
    public function vaccinations(): HasMany
    {
        return $this->hasMany(ClinicVaccination::class, 'patient_id');
    }
    public function prescriptions(): HasMany
    {
        return $this->hasMany(ClinicPrescription::class, 'patient_id');
    }
    public function dispensings(): HasMany
    {
        return $this->hasMany(ClinicDispensing::class, 'patient_id');
    }
    public function administrations(): HasMany
    {
        return $this->hasMany(ClinicMedicationAdministration::class, 'patient_id');
    }
    public function followUps(): HasMany
    {
        return $this->hasMany(ClinicFollowUp::class, 'patient_id');
    }
    public function certificates(): HasMany
    {
        return $this->hasMany(ClinicHealthCertificate::class, 'patient_id');
    }
    public function documents(): HasMany
    {
        return $this->hasMany(ClinicDocument::class, 'patient_id');
    }
    public function observations(): HasMany
    {
        return $this->hasMany(ClinicObservation::class, 'patient_id');
    }
    public function typeEnum(): PatientType
    {
        return PatientType::from($this->patient_type);
    }

    /** Alergi aktif — dasar peringatan peresepan. */
    public function activeAllergies(): HasMany
    {
        return $this->hasMany(ClinicAllergy::class, 'patient_id')->where('status', 'ACTIVE');
    }

    /** Kondisi khusus aktif (pantangan makan, pembatasan aktivitas, instruksi darurat). */
    public function activeSpecialConditions(): HasMany
    {
        return $this->hasMany(ClinicSpecialCondition::class, 'patient_id')->where('status', 'ACTIVE');
    }

    public function latestVisit(): HasOne
    {
        return $this->hasOne(ClinicVisit::class, 'patient_id')->latestOfMany('visit_at');
    }

    public function hasSevereAllergy(): bool
    {
        return $this->activeAllergies()->where('severity', 'SEVERE')->exists();
    }
}
