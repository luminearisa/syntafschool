<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsGradebook;

/**
 * Nilai akhir sebuah course (§43): membungkus mesin perhitungan dengan skala
 * nilai sehingga hasilnya lengkap — mentah, ternormalisasi, persentase, huruf,
 * dan lulus/tidak.
 */
class FinalGradeService
{
    public function __construct(
        private readonly GradeCalculationService $calculation,
        private readonly GradebookService $gradebooks,
    ) {}

    /** @return array<string, mixed> */
    public function forStudent(LmsGradebook $gradebook, int $userId): array
    {
        $calculation = $this->calculation->calculate($gradebook, $userId);

        return $this->decorate($gradebook, $calculation);
    }

    /**
     * Nilai akhir + snapshot perhitungan untuk dirilis (§16).
     *
     * @return array<string, mixed>
     */
    public function snapshotFor(LmsGradebook $gradebook, int $userId): array
    {
        $calculation = $this->calculation->calculate($gradebook, $userId);
        $grade = $this->decorate($gradebook, $calculation);

        return [
            'grade' => $grade,
            'component_snapshot' => $this->componentSnapshot($calculation),
            'calculation_snapshot' => $this->calculationSnapshot($gradebook, $calculation),
            'config_hash' => $gradebook->configHash(),
        ];
    }

    /** Lengkapi hasil perhitungan dengan huruf & predikat (§5, §6, §43). */
    public function decorate(LmsGradebook $gradebook, array $calculation): array
    {
        $scale = $this->gradebooks->scaleFor($gradebook);
        $normalized = $calculation['normalized'];
        $entry = $normalized === null ? null : $scale?->entryFor((float) $normalized);

        return $calculation + [
            'letter' => $entry?->grade,
            'predicate' => $entry?->predicate,
            'gpa' => $entry?->gpa === null ? null : (float) $entry->gpa,
            'scale' => $scale === null ? null : [
                'id' => (int) $scale->id,
                'name' => $scale->name,
            ],
            'display' => $this->display($gradebook, $calculation, $entry),
        ];
    }

    /**
     * Snapshot per komponen (§15): "Assignments weight 30, score 80,
     * contribution 24" — supaya nilai akhir tidak pernah muncul tanpa asal-usul.
     *
     * @return list<array<string, mixed>>
     */
    public function componentSnapshot(array $calculation): array
    {
        $rows = [];

        foreach ($calculation['blocks'] as $block) {
            $rows[] = [
                'name' => $block['name'],
                'weight' => (float) $block['weight'],
                'score' => $block['value'] === null ? null : round((float) $block['value'], 2),
                'contribution' => $block['value'] === null
                    ? null
                    : round((float) $block['value'] * (float) $block['weight'] / 100, 2),
                'aggregation' => $block['aggregation'],
                'item_count' => count($block['items'] ?? []),
            ];
        }

        if (($calculation['extra_credit']['points'] ?? 0.0) > 0.0) {
            $rows[] = [
                'name' => 'Extra credit',
                'weight' => null,
                'score' => null,
                'contribution' => (float) $calculation['extra_credit']['points'],
                'aggregation' => 'EXTRA_CREDIT',
                'item_count' => count($calculation['extra_credit']['items'] ?? []),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function calculationSnapshot(LmsGradebook $gradebook, array $calculation): array
    {
        return [
            'gradebook' => [
                'id' => (int) $gradebook->id,
                'name' => $gradebook->name,
                'maximum_grade' => (float) $gradebook->maximum_grade,
                'passing_grade' => $gradebook->passingGrade(),
                'config_hash' => $gradebook->configHash(),
            ],
            'policies' => $calculation['policies'],
            'weighting' => $calculation['weighting'],
            'result' => [
                'normalized' => $calculation['normalized'],
                'final_score' => $calculation['final_score'],
                'letter' => $calculation['letter'] ?? null,
                'predicate' => $calculation['predicate'] ?? null,
                'passed' => $calculation['passed'],
            ],
            'components' => $this->componentSnapshot($calculation),
            'items' => array_map(fn (array $row) => [
                'grade_item_id' => $row['grade_item_id'],
                'name' => $row['name'],
                'weight' => $row['weight'],
                'normalized' => $row['normalized'],
                'status' => $row['status'],
                'is_missing' => $row['is_missing'],
                'is_exempt' => $row['is_exempt'],
                'missing_policy' => $row['missing_policy'],
            ], $calculation['items']),
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function display(LmsGradebook $gradebook, array $calculation, $entry): array
    {
        $normalized = $calculation['normalized'];

        return [
            'value' => $normalized,
            'text' => $normalized === null
                ? 'Belum ada nilai'
                : number_format((float) $normalized, 2).($gradebook->is_letter_visible && $entry !== null ? ' — '.$entry->grade : ''),
            'letter' => $gradebook->is_letter_visible ? $entry?->grade : null,
            'percentage' => $normalized === null ? null : round((float) $normalized, 2).'%',
            'points' => $calculation['final_score'] === null
                ? null
                : number_format((float) $calculation['final_score'], 2).'/'.number_format((float) $gradebook->maximum_grade, 0),
        ];
    }

    /** Rincian per item untuk siswa (§32) — termasuk yang belum dirilis. */
    public function itemBreakdown(LmsGradeItem $item, int $userId): array
    {
        return $this->calculation->itemBreakdown($item, $userId);
    }
}
