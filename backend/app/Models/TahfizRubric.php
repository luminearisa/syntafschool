<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rubrik penilaian yang dapat dikonfigurasi admin.
 *
 * Satu rubrik berlaku untuk satu ranah (`scope`): tahsin, tilawah, hafalan,
 * placement, exam, atau tasmi. Tiap kriteria (Makhraj, Tajwid, Kelancaran …)
 * adalah `TahfizRubricItem` dengan bobot dan deskripsi per tingkat skor.
 */
class TahfizRubric extends BaseModel
{
    use SoftDeletes;

    public const SCOPE_TAHSIN = 'tahsin';
    public const SCOPE_TILAWAH = 'tilawah';
    public const SCOPE_HAFALAN = 'hafalan';
    public const SCOPE_PLACEMENT = 'placement';
    public const SCOPE_EXAM = 'exam';
    public const SCOPE_TASMI = 'tasmi';

    /** @var list<string> */
    public const SCOPES = [
        self::SCOPE_TAHSIN, self::SCOPE_TILAWAH, self::SCOPE_HAFALAN,
        self::SCOPE_PLACEMENT, self::SCOPE_EXAM, self::SCOPE_TASMI,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $casts = [
        'max_score' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TahfizRubricItem::class, 'rubric_id')->orderBy('order');
    }

    public function tahsinRecords(): HasMany
    {
        return $this->hasMany(TahfizTahsinRecord::class, 'rubric_id');
    }
}
