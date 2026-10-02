<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catatan masa kerja (§17).
 *
 * Riwayat hubungan kerja, bukan salinan kolom pegawai: Ahmad yang berhenti 2024
 * dan kembali 2026 punya dua baris di sini, dan total masa kerja tetap dapat
 * dihitung.
 */
class HrEmploymentRecord extends HrdModel
{
    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ENDED = 'ended';

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
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

    public function probations(): HasMany
    {
        return $this->hasMany(HrProbation::class, 'employment_record_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(HrContract::class, 'employment_record_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
