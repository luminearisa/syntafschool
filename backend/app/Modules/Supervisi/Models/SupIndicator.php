<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Modules\Supervisi\Domain\Enums\IndicatorDirection;
use App\Modules\Supervisi\Domain\Enums\IndicatorInputType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Indikator penilaian.
 *
 * `input_type` menentukan cara pengisian: pilihan skala rubrik (`SCALE`),
 * entri angka langsung (`NUMBER`), ya/tidak (`BOOLEAN`), pilihan ganda, atau
 * tidak diskor (`TEXT`, `DATE`, `FILE`).
 */
class SupIndicator extends BaseModel
{
    protected $table = 'sup_indicators';

    protected $casts = [
        'input_type' => IndicatorInputType::class,
        'direction' => IndicatorDirection::class,
        'score_min' => 'float',
        'score_max' => 'float',
        'score_step' => 'float',
        'score_decimals' => 'integer',
        'score_yes' => 'float',
        'score_no' => 'float',
        'score_partial' => 'float',
        'is_scored' => 'boolean',
        'is_required' => 'boolean',
        'allow_na' => 'boolean',
        'is_critical' => 'boolean',
        'weight' => 'float',
        'min_comment_below' => 'float',
        'sort_order' => 'integer',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(SupSection::class, 'section_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(SupIndicatorOption::class, 'indicator_id')->orderBy('sort_order');
    }

    /**
     * Skor maksimum indikator berdasarkan tipe input.
     *
     * - SCALE / MULTI_CHOICE : opsi ber-skor tertinggi
     * - NUMBER               : batas atas rentang
     * - BOOLEAN              : skor "ya" (atau nilai terbesar yang tersedia)
     * - teks/berkas          : 0 (tidak menyumbang skor)
     */
    public function maximumScore(): float
    {
        if (! $this->is_scored) {
            return 0.0;
        }

        return match ($this->input_type) {
            IndicatorInputType::SCALE => (float) ($this->options->max('score') ?? 0),
            IndicatorInputType::MULTI_CHOICE => (float) $this->options->sum('score'),
            IndicatorInputType::NUMBER => (float) ($this->score_max ?? 0),
            IndicatorInputType::BOOLEAN => (float) max(array_filter([
                $this->score_yes,
                $this->score_partial,
                $this->score_no,
            ], fn ($value) => $value !== null) ?: [0]),
            default => 0.0,
        };
    }

    /** Skor minimum baku indikator (untuk normalisasi rentang angka). */
    public function minimumScore(): float
    {
        return match ($this->input_type) {
            IndicatorInputType::SCALE, IndicatorInputType::MULTI_CHOICE => (float) ($this->options->min('score') ?? 0),
            IndicatorInputType::NUMBER => (float) ($this->score_min ?? 0),
            IndicatorInputType::BOOLEAN => (float) min(array_filter([
                $this->score_yes,
                $this->score_partial,
                $this->score_no,
            ], fn ($value) => $value !== null) ?: [0]),
            default => 0.0,
        };
    }

    public function requiresCommentFor(?float $score): bool
    {
        return $this->min_comment_below !== null
            && $score !== null
            && $score < $this->min_comment_below;
    }
}
