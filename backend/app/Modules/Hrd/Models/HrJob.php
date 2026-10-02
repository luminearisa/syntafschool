<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Jabatan/pekerjaan (§9) — sebutan generik yang dipakai berulang, berbeda dari
 * Position (kursi nyata). "Guru Matematika" adalah Job; "GURU-MTK-SMP-001" adalah
 * Position.
 */
class HrJob extends HrdModel
{
    use SoftDeletes;

    protected $casts = [
        'required_skills' => 'array',
        'required_certifications' => 'array',
        'is_teacher_role' => 'boolean',
        'required_experience_years' => 'integer',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(HrJobFamily::class, 'job_family_code', 'code');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(HrJobLevel::class, 'job_level_code', 'code');
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(HrJobGrade::class, 'job_grade_code', 'code');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(HrPosition::class, 'job_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
