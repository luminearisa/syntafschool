<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne};
use App\Models\Student;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\Priority;
use App\Modules\Clinic\Domain\Enums\VisitSource;
use App\Modules\Clinic\Domain\Enums\VisitStatus;
use App\Modules\Clinic\Domain\Enums\VisitType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kunjungan klinis — simpul utama seluruh alur layanan kesehatan.
 *
 * Tabel: `clinic_visits`
 */
class ClinicVisit extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_visits';

    use SoftDeletes;

    protected $casts = [
        'visit_at' => 'datetime',
        'closed_at' => 'datetime',
        'is_emergency' => 'boolean',
        'requires_observation' => 'boolean',
        'parent_notified' => 'boolean',
        'parent_notified_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(ClinicLocation::class, 'clinic_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(ClinicAppointment::class, 'appointment_id');
    }
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(ClinicComplaint::class, 'complaint_id');
    }
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attended_by');
    }
    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
    public function triage(): HasOne
    {
        return $this->hasOne(ClinicTriage::class, 'visit_id');
    }
    public function queue(): HasOne
    {
        return $this->hasOne(ClinicQueueEntry::class, 'visit_id');
    }
    public function discharge(): HasOne
    {
        return $this->hasOne(ClinicDischarge::class, 'visit_id');
    }
    public function vitals(): HasMany
    {
        return $this->hasMany(ClinicVitalSign::class, 'visit_id');
    }
    public function assessments(): HasMany
    {
        return $this->hasMany(ClinicAssessment::class, 'visit_id');
    }
    public function diagnoses(): HasMany
    {
        return $this->hasMany(ClinicDiagnosis::class, 'visit_id');
    }
    public function procedures(): HasMany
    {
        return $this->hasMany(ClinicProcedure::class, 'visit_id');
    }
    public function observations(): HasMany
    {
        return $this->hasMany(ClinicObservation::class, 'visit_id');
    }
    public function prescriptions(): HasMany
    {
        return $this->hasMany(ClinicPrescription::class, 'visit_id');
    }
    public function administrations(): HasMany
    {
        return $this->hasMany(ClinicMedicationAdministration::class, 'visit_id');
    }
    public function referrals(): HasMany
    {
        return $this->hasMany(ClinicReferral::class, 'visit_id');
    }
    public function dispensings(): HasMany
    {
        return $this->hasMany(ClinicDispensing::class, 'visit_id');
    }
    public function followUps(): HasMany
    {
        return $this->hasMany(ClinicFollowUp::class, 'visit_id');
    }
    public function emergencies(): HasMany
    {
        return $this->hasMany(ClinicEmergency::class, 'visit_id');
    }
    public function injuries(): HasMany
    {
        return $this->hasMany(ClinicInjury::class, 'visit_id');
    }
    public function certificates(): HasMany
    {
        return $this->hasMany(ClinicHealthCertificate::class, 'visit_id');
    }
    public function statusEnum(): VisitStatus
    {
        return VisitStatus::from($this->status);
    }

    public function priorityEnum(): Priority
    {
        return Priority::from($this->priority);
    }

    public function typeEnum(): VisitType
    {
        return VisitType::from($this->visit_type);
    }

    public function sourceEnum(): VisitSource
    {
        return VisitSource::from($this->source);
    }

    public function isOpen(): bool
    {
        return $this->statusEnum()->isOpen();
    }

    /** Asesmen yang berlaku saat ini (versi terbaru). */
    public function currentAssessment(): HasOne
    {
        return $this->hasOne(ClinicAssessment::class, 'visit_id')->where('is_current', true)->latestOfMany('assessment_at');
    }

    public function primaryDiagnosis(): HasOne
    {
        return $this->hasOne(ClinicDiagnosis::class, 'visit_id')->where('type', 'PRIMARY')->latestOfMany('diagnosed_at');
    }

    public function activeObservation(): HasOne
    {
        return $this->hasOne(ClinicObservation::class, 'visit_id')->where('status', 'ACTIVE')->latestOfMany('started_at');
    }
}
