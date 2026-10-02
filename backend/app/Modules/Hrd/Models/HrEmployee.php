<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pegawai — inti sistem pencatatan SDM (§12).
 *
 * Satu baris = satu hubungan kerja dengan yayasan. Data pribadi ada di
 * `hr_people`; penugasan resmi ada di `hr_employee_assignments`; kolom
 * penempatan di sini (`organization_id`, `department_id`, `position_id`,
 * `location_id`, `manager_employee_id`) adalah **ringkasan** yang diperbarui oleh
 * mesin transaksi — bukan yang diketik di formulir. Dengan begitu tidak ada dua
 * sumber kebenaran yang bisa berbeda.
 */
class HrEmployee extends HrdModel
{
    use HasFactory;
    use SoftDeletes;

    protected $casts = [
        'hire_date' => 'date:Y-m-d',
        'probation_end_date' => 'date:Y-m-d',
        'confirmation_date' => 'date:Y-m-d',
        'termination_date' => 'date:Y-m-d',
        'is_active' => 'boolean',
        'archived_at' => 'datetime',
        'version' => 'integer',
    ];

    public const LIFECYCLE_DRAFT = 'draft';
    public const LIFECYCLE_PREBOARDING = 'preboarding';
    public const LIFECYCLE_ACTIVE = 'active';
    public const LIFECYCLE_PROBATION = 'probation';
    public const LIFECYCLE_CONFIRMED = 'confirmed';
    public const LIFECYCLE_LEAVE = 'leave';
    public const LIFECYCLE_SUSPENDED = 'suspended';
    public const LIFECYCLE_OFFBOARDING = 'offboarding';
    public const LIFECYCLE_INACTIVE = 'inactive';
    public const LIFECYCLE_ARCHIVED = 'archived';

    public function person(): BelongsTo
    {
        return $this->belongsTo(HrPerson::class, 'person_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(HrWorkLocation::class, 'location_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_employee_id');
    }

    /** Pegawai yang melapor langsung kepada pegawai ini. */
    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_employee_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(HrEmployeeAssignment::class, 'employee_id');
    }

    public function primaryAssignment(): HasMany
    {
        return $this->assignments()->where('is_primary', true)->where('status', 'active');
    }

    public function employmentRecords(): HasMany
    {
        return $this->hasMany(HrEmploymentRecord::class, 'employee_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(HrContract::class, 'employee_id');
    }

    public function probations(): HasMany
    {
        return $this->hasMany(HrProbation::class, 'employee_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(HrEmployeeDocument::class, 'employee_id');
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(HrEmployeeDependent::class, 'employee_id');
    }

    public function educations(): HasMany
    {
        return $this->hasMany(HrEmployeeEducation::class, 'employee_id');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(HrEmployeeSkill::class, 'employee_id');
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(HrEmployeeCertification::class, 'employee_id');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(HrEmployeeIdentifier::class, 'employee_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(HrEmployeeContact::class, 'employee_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(HrEmployeeAddress::class, 'employee_id');
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(HrEmergencyContact::class, 'employee_id');
    }

    public function managerRelations(): HasMany
    {
        return $this->hasMany(HrEmployeeManager::class, 'employee_id');
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(HrEmployeeTimelineEvent::class, 'employee_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(HrEmployeeStatusHistory::class, 'employee_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(HrTransaction::class, 'employee_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(HrRequest::class, 'employee_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(HrNote::class, 'employee_id');
    }

    /** Nama yang ditampilkan; selalu lewat relasi orang, bukan kolom duplikat. */
    public function displayName(): string
    {
        return (string) ($this->person?->full_name ?? $this->employee_number);
    }

    /** Pegawai yang masih berjalan (belum berhenti/arsip). */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNotIn('lifecycle_state', [
            self::LIFECYCLE_INACTIVE, self::LIFECYCLE_ARCHIVED,
        ]);
    }

    public function scopeArchived($query)
    {
        return $query->where('lifecycle_state', self::LIFECYCLE_ARCHIVED);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active && ! in_array($this->lifecycle_state, [self::LIFECYCLE_INACTIVE, self::LIFECYCLE_ARCHIVED], true);
    }

    /** Manajer langsung dari penugasan utama (satu sumber, bukan tebakan). */
    public function currentManagerId(): ?int
    {
        $assignment = $this->assignments()
            ->where('status', 'active')
            ->where('is_primary', true)
            ->orderByDesc('start_date')
            ->first();

        return $assignment?->manager_employee_id
            ? (int) $assignment->manager_employee_id
            : ($this->manager_employee_id ? (int) $this->manager_employee_id : null);
    }
}
