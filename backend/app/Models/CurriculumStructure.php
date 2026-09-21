<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Struktur kurikulum berversi per (kurikulum, jenjang).
 *
 * Versi memungkinkan perubahan struktur antar tahun ajaran tanpa mengubah
 * kelas yang sudah berjalan: kelas menunjuk versi yang dipakai saat diterapkan.
 */
class CurriculumStructure extends BaseModel
{
    use SoftDeletes;

    protected $casts = [
        'version' => 'integer',
        'is_active' => 'boolean',
    ];

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CurriculumStructureItem::class)->orderBy('semester')->orderBy('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForLevel(Builder $query, int $educationLevelId): Builder
    {
        return $query->where('education_level_id', $educationLevelId);
    }
}
