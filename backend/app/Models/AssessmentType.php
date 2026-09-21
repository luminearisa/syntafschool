<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Jenis penilaian (kuis, tugas, PTS, praktik, ...).
 *
 * Jenis adalah data, bukan skema: sekolah boleh menambah atau menonaktifkan
 * jenis tanpa mengubah kode. Bobot per jenis disimpan pada `assessment_weights`.
 */
class AssessmentType extends BaseModel
{
    use SoftDeletes;

    public const CATEGORY_PENGETAHUAN = 'pengetahuan';
    public const CATEGORY_KETERAMPILAN = 'keterampilan';
    public const CATEGORY_SIKAP = 'sikap';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_PENGETAHUAN,
        self::CATEGORY_KETERAMPILAN,
        self::CATEGORY_SIKAP,
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function weights(): HasMany
    {
        return $this->hasMany(AssessmentWeight::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
