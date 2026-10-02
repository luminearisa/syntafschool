<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Posisi — kursi nyata dalam organisasi (§8, §49, §50).
 *
 * `filled_headcount` dihitung ulang dari penugasan aktif oleh PositionService;
 * `vacancy_status` adalah turunannya. Karena itu laporan lowongan tidak pernah
 * berbeda dari daftar pegawai.
 */
class HrPosition extends HrdModel
{
    use SoftDeletes;

    protected $casts = [
        'authorized_headcount' => 'integer',
        'planned_headcount' => 'integer',
        'filled_headcount' => 'integer',
        'is_critical' => 'boolean',
        'is_approved' => 'boolean',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'target_fill_date' => 'date:Y-m-d',
        'version' => 'integer',
    ];

    public const VACANT = 'vacant';
    public const PARTIALLY_FILLED = 'partially_filled';
    public const FULLY_FILLED = 'fully_filled';
    public const FROZEN = 'frozen';
    public const CLOSED = 'closed';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FROZEN = 'frozen';
    public const STATUS_ABOLISHED = 'abolished';
    public const STATUS_ARCHIVED = 'archived';

    public function job(): BelongsTo
    {
        return $this->belongsTo(HrJob::class, 'job_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(HrWorkLocation::class, 'location_id');
    }

    public function parentPosition(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_position_id');
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_position_id');
    }

    public function childPositions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_position_id');
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(HrJobGrade::class, 'grade_code', 'code');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(HrEmployeeAssignment::class, 'position_id');
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->where('status', 'active');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(HrPositionHistory::class, 'position_id');
    }

    /** Jumlah kursi kosong = diizinkan − terisi (tidak pernah negatif). */
    public function vacantCount(): int
    {
        return max(0, (int) $this->authorized_headcount - (int) $this->filled_headcount);
    }

    public function hasVacancy(): bool
    {
        return $this->vacantCount() > 0 && $this->vacancy_status !== self::FROZEN;
    }

    public function scopeOpen($query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_FROZEN]);
    }

    public function scopeWithVacancy($query): Builder
    {
        return $query->whereIn('vacancy_status', [self::VACANT, self::PARTIALLY_FILLED]);
    }
}
