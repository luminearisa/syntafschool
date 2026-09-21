<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Level Tahfiz (Pemula, Tahsin, Juz 30, Juz 29, …).
 *
 * Admin dapat membuat level sendiri, baik yang terikat program maupun level
 * umum yang dipakai banyak program (`program_id` kosong).
 */
class TahfizLevel extends BaseModel
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $casts = [
        'order' => 'integer',
        'juz_start' => 'integer',
        'juz_end' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order')->orderBy('id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(TahfizProgram::class, 'program_id');
    }

    public function halaqahs(): HasMany
    {
        return $this->hasMany(TahfizHalaqah::class, 'level_id');
    }
}
