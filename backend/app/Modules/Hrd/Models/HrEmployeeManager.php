<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hubungan atasan bawahan (§21).
 *
 * Empat jenis hubungan hidup berdampingan: atasan langsung, atasan fungsional,
 * garis putus-putus, dan pejabat sementara. Satu kolom `manager_id` tidak dapat
 * mewakili struktur ini — dan justru itu sumber kesalahan resolusi approver.
 */
class HrEmployeeManager extends HrdModel
{
    protected $casts = [
        'is_primary' => 'boolean',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public const TYPE_DIRECT = 'direct';
    public const TYPE_FUNCTIONAL = 'functional';
    public const TYPE_DOTTED = 'dotted_line';
    public const TYPE_ACTING = 'acting';
    public const TYPE_PRIMARY = 'primary';

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'manager_employee_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(HrOrganization::class, 'organization_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeEffectiveOn(Builder $query, mixed $date): Builder
    {
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;

        return $query->where('start_date', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date));
    }
}
