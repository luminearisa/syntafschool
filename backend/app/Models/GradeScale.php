<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Skala konversi nilai angka ke huruf + predikat.
 *
 * Range huruf disimpan sebagai baris terpisah (`grade_scale_items`) supaya
 * sekolah bisa mengubah rentang tanpa menyentuh kode.
 */
class GradeScale extends BaseModel
{
    use SoftDeletes;

    protected $casts = [
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

    public function items(): HasMany
    {
        return $this->hasMany(GradeScaleItem::class)->orderByDesc('min_score');
    }

    /**
     * Skala yang berlaku untuk sebuah jenjang.
     *
     * Skala khusus jenjang lebih didahulukan daripada skala umum; bila keduanya
     * tidak ada, skala aktif pertama dipakai agar konversi huruf tidak gagal.
     */
    public static function resolveFor(?int $educationLevelId = null): ?self
    {
        $query = static::query()->where('is_active', true);

        if ($educationLevelId !== null) {
            $specific = (clone $query)->where('education_level_id', $educationLevelId)->first();

            if ($specific) {
                return $specific;
            }
        }

        return (clone $query)->whereNull('education_level_id')->orderBy('id')->first()
            ?? $query->orderBy('id')->first();
    }

    /**
     * Huruf & predikat untuk sebuah nilai angka.
     *
     * @return array{grade: string, predicate: string|null}|null
     */
    public function convert(null|int|float|string $score): ?array
    {
        if ($score === null || $score === '') {
            return null;
        }

        if ($this->items->isEmpty()) {
            $this->load('items');
        }

        $matched = $this->items->first(
            fn (GradeScaleItem $item) => $item->covers((float) $score),
        );

        if ($matched === null) {
            return null;
        }

        return [
            'grade' => $matched->grade,
            'predicate' => $matched->predicate,
        ];
    }
}
