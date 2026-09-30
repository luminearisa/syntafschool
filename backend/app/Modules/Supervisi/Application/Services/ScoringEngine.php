<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Modules\Supervisi\Domain\Enums\IndicatorDirection;
use App\Modules\Supervisi\Domain\Enums\IndicatorInputType;
use App\Modules\Supervisi\Models\SupIndicator;
use App\Modules\Supervisi\Models\SupInstrumentVersion;
use App\Modules\Supervisi\Models\SupVisit;

/**
 * Mesin penskoran supervisi.
 *
 * Prinsip:
 *  1. Skor **selalu** dihitung di server dari jawaban + definisi instrumen.
 *  2. Indikator tidak diskor (teks/tanggal/berkas) tidak menyumbang pembilang
 *     maupun penyebut.
 *  3. Indikator bertanda `NA` dikeluarkan dari keduanya (bukan dianggap nol).
 *  4. `NUMBER` dengan arah `LOWER_BETTER` dibalik lebih dulu sehingga “makin
 *     kecil makin baik” (mis. jumlah pelanggaran) tetap bernilai tinggi.
 */
class ScoringEngine
{
    /** Hasil netral saat instrumen belum ditentukan. */
    public const EMPTY_RESULT = [
        'raw' => null, 'max' => null, 'final' => null, 'sections' => [],
        'answered' => 0, 'scored_total' => 0, 'excluded' => 0, 'missing_required' => [],
    ];

    /**
     * Skor efektif satu jawaban atas satu indikator.
     *
     * @param  array<string,mixed>|null  $answer  ['option_id' => int, 'score' => float, 'value_number' => float, 'value_text' => string, 'value_json' => array, 'is_na' => bool]
     */
    public function indicatorScore(SupIndicator $indicator, ?array $answer): ?float
    {
        if (! $indicator->is_scored || $answer === null || ($answer['is_na'] ?? false) === true) {
            return null;
        }

        return match ($indicator->input_type) {
            IndicatorInputType::SCALE => $this->scaleScore($indicator, $answer),
            IndicatorInputType::MULTI_CHOICE => $this->multiChoiceScore($indicator, $answer),
            IndicatorInputType::NUMBER => $this->numberScore($indicator, $answer),
            IndicatorInputType::BOOLEAN => $this->booleanScore($indicator, $answer),
            default => null,
        };
    }

    /** Skor maksimum satu indikator. */
    public function indicatorMaxScore(SupIndicator $indicator): float
    {
        return $indicator->maximumScore();
    }

    /** Skor maksimum seluruh versi instrumen (dipakai saat publikasi). */
    public function versionMaxScore(SupInstrumentVersion $version): float
    {
        $version->loadMissing('sections.indicators.options');
        $total = 0.0;

        foreach ($version->sections as $section) {
            foreach ($section->indicators as $indicator) {
                $total += $this->indicatorMaxScore($indicator) * (float) $indicator->weight;
            }
        }

        return round($total, 2);
    }

    /**
     * Hitung seluruh skor sebuah kegiatan supervisi.
     *
     * @return array{
     *     raw: float|null, max: float|null, final: float|null,
     *     sections: list<array{section_id:int, raw:float, max:float, percent:float|null, weight:float}>,
     *     answered: int, scored_total: int, excluded: int, missing_required: list<int>
     * }
     */
    public function scoreVisit(SupVisit $visit, ?SupInstrumentVersion $version = null): array
    {
        $version ??= $visit->instrumentVersion;
        $answers = $visit->relationLoaded('answers') ? $visit->answers : $visit->answers()->get();

        if ($version === null) {
            return self::EMPTY_RESULT;
        }

        $mapped = [];

        foreach ($answers as $answerModel) {
            $mapped[(int) $answerModel->indicator_id] = [
                'option_id' => $answerModel->option_id,
                'score' => $answerModel->score,
                'value_number' => $answerModel->value_number,
                'value_text' => $answerModel->value_text,
                'value_long_text' => $answerModel->value_long_text,
                'value_date' => $answerModel->value_date,
                'value_json' => $answerModel->value_json,
                'is_na' => (bool) $answerModel->is_na,
            ];
        }

        return $this->scoreAnswers($version, $mapped);
    }

    /**
     * Hitung skor dari jawaban berbentuk array (tanpa model Eloquent).
     *
     * Dipakai bersama kegiatan supervisi (`sup_visit_answers`) dan penilaian
     * kinerja (`sup_appraisal_scores`) supaya rumus konversi 100, pembobotan
     * bagian, dan perlakuan indikator NA hanya hidup di satu tempat.
     *
     * @param  array<int,array<string,mixed>>  $answersByIndicator
     * @return array{
     *     raw: float|null, max: float|null, final: float|null,
     *     sections: list<array{section_id:int, raw:float, max:float, percent:float|null, weight:float}>,
     *     answered: int, scored_total: int, excluded: int, missing_required: list<int>
     * }
     */
    public function scoreAnswers(SupInstrumentVersion $version, array $answersByIndicator): array
    {
        $version->loadMissing('sections.indicators.options');

        $rawTotal = 0.0;
        $maxTotal = 0.0;
        $weightTotal = 0.0;
        $weightedPercentTotal = 0.0;
        $scoredTotal = 0;
        $excluded = 0;
        $missing = [];
        $sections = [];

        foreach ($version->sections as $section) {
            $sectionRaw = 0.0;
            $sectionMax = 0.0;

            foreach ($section->indicators as $indicator) {
                $answer = $answersByIndicator[(int) $indicator->id] ?? null;

                if ($indicator->is_scored) {
                    $scoredTotal++;
                }

                if ($answer === null) {
                    if ($indicator->is_required && $indicator->is_scored) {
                        $missing[] = (int) $indicator->id;
                    }

                    continue;
                }

                if (($answer['is_na'] ?? false) === true) {
                    $excluded++;

                    continue;
                }

                $score = $this->indicatorScore($indicator, $answer);

                // Indikator non-skor (teks/tanggal/berkas) wajib diisi isinya,
                // tetapi tidak menyumbang angka apa pun.
                if (! $indicator->is_scored) {
                    if ($indicator->is_required && ! $this->hasContent($answer)) {
                        $missing[] = (int) $indicator->id;
                    }

                    continue;
                }

                if ($score === null) {
                    continue;
                }

                $weight = (float) $indicator->weight ?: 1.0;
                $sectionRaw += $score * $weight;
                $sectionMax += $this->indicatorMaxScore($indicator) * $weight;
            }

            $percent = $sectionMax > 0 ? round($sectionRaw / $sectionMax * 100, 2) : null;

            $sections[] = [
                'section_id' => (int) $section->id,
                'raw' => round($sectionRaw, 2),
                'max' => round($sectionMax, 2),
                'percent' => $percent,
                'weight' => (float) $section->weight,
            ];

            $rawTotal += $sectionRaw;
            $maxTotal += $sectionMax;

            if ($percent !== null) {
                $weightedPercentTotal += $percent * ((float) $section->weight ?: 1.0);
                $weightTotal += (float) $section->weight ?: 1.0;
            }
        }

        $final = $this->finalScore($version, $rawTotal, $maxTotal, $weightedPercentTotal, $weightTotal, $sections);

        return [
            'raw' => round($rawTotal, 2),
            'max' => round($maxTotal, 2),
            'final' => $final,
            'sections' => $sections,
            'answered' => count($answersByIndicator),
            'scored_total' => $scoredTotal,
            'excluded' => $excluded,
            'missing_required' => array_values(array_unique($missing)),
        ];
    }

    /** Predikat/band untuk nilai akhir versi instrumen. */
    public function bandForVersion(SupInstrumentVersion $version, ?float $final): ?array
    {
        if ($final === null) {
            return null;
        }

        $scale = $version->gradeScale;
        if ($scale === null) {
            return null;
        }

        return $scale->bandFor($final, $scale->scale_type);
    }

    // ------------------------------------------------------------------
    // Perhitungan per tipe input
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $answer */
    protected function scaleScore(SupIndicator $indicator, array $answer): ?float
    {
        if ($answer['score'] !== null) {
            return (float) $answer['score'];
        }

        $optionId = $answer['option_id'] ?? null;
        if ($optionId === null) {
            return null;
        }

        $option = $indicator->options->firstWhere('id', (int) $optionId);

        return $option === null ? null : (float) $option->score;
    }

    /** @param array<string,mixed> $answer */
    protected function multiChoiceScore(SupIndicator $indicator, array $answer): ?float
    {
        $ids = $answer['value_json']['option_ids'] ?? [];
        if (! is_array($ids) || $ids === []) {
            return $answer['score'] === null ? null : (float) $answer['score'];
        }

        return (float) $indicator->options
            ->whereIn('id', array_map('intval', $ids))
            ->sum('score');
    }

    /** @param array<string,mixed> $answer */
    protected function numberScore(SupIndicator $indicator, array $answer): ?float
    {
        $value = $answer['value_number'] ?? $answer['score'] ?? null;
        if ($value === null) {
            return null;
        }

        $value = (float) $value;
        $min = $indicator->score_min === null ? null : (float) $indicator->score_min;
        $max = $indicator->score_max === null ? null : (float) $indicator->score_max;

        if ($min !== null) {
            $value = max($min, $value);
        }
        if ($max !== null) {
            $value = min($max, $value);
        }

        if ($indicator->direction === IndicatorDirection::LOWER_BETTER && $min !== null && $max !== null) {
            // Dibalik: nilai terendah mendapat skor tertinggi.
            return round(($max + $min) - $value, (int) $indicator->score_decimals);
        }

        return $value;
    }

    /** @param array<string,mixed> $answer */
    protected function booleanScore(SupIndicator $indicator, array $answer): ?float
    {
        if ($answer['score'] !== null) {
            return (float) $answer['score'];
        }

        $value = strtoupper((string) ($answer['value_text'] ?? ''));

        return match ($value) {
            'YES', 'YA', '1', 'TRUE' => (float) ($indicator->score_yes ?? 1),
            'PARTIAL', 'SEBAGIAN' => $indicator->score_partial === null ? null : (float) $indicator->score_partial,
            'NO', 'TIDAK', '0', 'FALSE' => $indicator->score_no === null ? null : (float) $indicator->score_no,
            default => null,
        };
    }

    /** @param array<string,mixed> $answer */
    protected function hasContent(array $answer): bool
    {
        return trim((string) ($answer['value_text'] ?? '')) !== ''
            || trim((string) ($answer['value_long_text'] ?? '')) !== ''
            || $answer['value_number'] !== null
            || $answer['value_date'] !== null
            || ($answer['value_json'] ?? null) !== null
            || $answer['option_id'] !== null;
    }

    /**
     * Nilai akhir sesuai `scoring_mode` versi.
     *
     * @param  list<array{section_id:int, raw:float, max:float, percent:float|null, weight:float}>  $sections
     */
    protected function finalScore(
        SupInstrumentVersion $version,
        float $rawTotal,
        float $maxTotal,
        float $weightedPercentTotal,
        float $weightTotal,
        array $sections,
    ): ?float {
        $mode = $version->scoring_mode->value;

        if ($mode === 'NARRATIVE') {
            return null;
        }

        $value = match ($mode) {
            'SUM' => round($rawTotal, 2),
            'AVERAGE' => $this->averageFromSections($sections), // rata-rata persentase bagian
            'WEIGHTED' => $weightTotal > 0 ? round($weightedPercentTotal / $weightTotal, 2) : null,
            default => $maxTotal > 0 ? round($rawTotal / $maxTotal * 100, 2) : null,
        };

        return $value;
    }

    /** @param list<array{percent:float|null, weight:float}> $sections */
    protected function averageFromSections(array $sections): ?float
    {
        $percents = array_values(array_filter(
            array_map(fn (array $section) => $section['percent'], $sections),
            fn ($percent) => $percent !== null,
        ));

        if ($percents === []) {
            return null;
        }

        return round(array_sum($percents) / count($percents), 2);
    }
}
