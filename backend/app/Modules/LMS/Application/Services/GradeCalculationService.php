<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\AssessmentResultStatus;
use App\Modules\LMS\Domain\Enums\AggregationMethod;
use App\Modules\LMS\Domain\Enums\ExtraCreditPolicy;
use App\Modules\LMS\Domain\Enums\MissingGradePolicy;
use App\Modules\LMS\Domain\Enums\WeightPolicy;
use App\Modules\LMS\Models\LmsAssessmentResult;
use App\Modules\LMS\Models\LmsGradeCategory;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsGradebook;
use App\Modules\LMS\Models\LmsRubricAssessment;
use Illuminate\Support\Collection;

/**
 * Mesin perhitungan nilai (§14–§16, §43).
 *
 * Seluruh aturan penilaian hidup di kelas ini: pengumpulan grade item, aturan
 * kategori, bobot, kebijakan nilai kosong, pembebasan, extra credit, agregasi,
 * sampai rincian kontribusi tiap komponen. Controller **tidak** menghitung apa
 * pun; ia hanya memanggil service ini.
 */
class GradeCalculationService
{
    public function __construct(private readonly GradebookService $gradebooks) {}

    /**
     * Hitung nilai satu siswa beserta rincian lengkapnya (§15).
     *
     * @return array<string, mixed>
     */
    public function calculate(LmsGradebook $gradebook, int $userId, bool $persist = false): array
    {
        $gradebook->loadMissing(['categories', 'items.category']);

        $items = $gradebook->items
            ->filter(fn (LmsGradeItem $item) => $item->countsTowardFinal() || $item->is_extra_credit)
            ->values();

        $results = LmsAssessmentResult::query()
            ->whereIn('grade_item_id', $items->pluck('id'))
            ->where('user_id', $userId)
            ->get()
            ->keyBy('grade_item_id');

        $rows = $items->map(fn (LmsGradeItem $item) => $this->rowFor($item, $results->get($item->id), $userId))->all();

        $gradebookPolicy = $gradebook->missing_grade_policy;
        $categories = $this->aggregateCategories($gradebook, $rows, $gradebookPolicy);

        $scored = array_values(array_filter($rows, fn (array $row) => ! $row['is_extra_credit']));
        $extraRows = array_values(array_filter($rows, fn (array $row) => $row['is_extra_credit']));

        $uncategorised = array_values(array_filter($scored, fn (array $row) => $row['category_id'] === null));
        $uncategorisedBlock = $this->aggregateBlock(
            $uncategorised,
            $gradebook->aggregation_method,
            $gradebookPolicy,
            'Tanpa kategori',
        );

        $blocks = array_values(array_filter(
            array_map(fn (array $category) => [
                'id' => $category['id'],
                'parent_id' => $category['parent_id'],
                'name' => $category['name'],
                'weight' => $category['weight'],
                'aggregation' => $category['aggregation'],
                'value' => $category['value'],
                'counted' => $category['counted'],
                'missing' => $category['missing'],
                'is_extra_credit' => $category['is_extra_credit'],
                'include_in_final' => $category['include_in_final'],
                'items' => $category['items'],
            ], $categories),
            fn (array $block) => $block['include_in_final'] && $block['value'] !== null,
        ));

        if ($uncategorisedBlock['value'] !== null) {
            $blocks[] = [
                'id' => null,
                'parent_id' => null,
                'name' => 'Tanpa kategori',
                'weight' => 0.0,
                'aggregation' => $gradebook->aggregation_method->value,
                'value' => $uncategorisedBlock['value'],
                'counted' => $uncategorisedBlock['counted'],
                'missing' => $uncategorisedBlock['missing'],
                'is_extra_credit' => false,
                'include_in_final' => true,
                'items' => $uncategorisedBlock['items'],
            ];
        }

        $base = $this->weightedTotal($blocks, $gradebook->weight_policy);
        $extra = $this->extraCreditTotal($extraRows, $gradebook);

        $normalized = $base['value'] === null ? null : round($base['value'] + $extra['points'], 4);
        $cap = (float) $gradebook->maximum_grade;

        // §11: batas nilai maksimum hanya relevan ketika ada poin tambahan —
        // tanpa extra credit, nilai dasar (termasuk agregasi SUM) dibiarkan apa adanya.
        if ($normalized !== null && $extra['points'] > 0 && $gradebook->extra_credit_policy === ExtraCreditPolicy::CAP) {
            $normalized = min($normalized, 100.0);
        }

        $finalScore = $normalized === null ? null : round($normalized / 100 * $cap, 4);

        $missingItems = array_values(array_filter($rows, fn (array $row) => $row['is_missing']));
        $pendingMissing = array_values(array_filter(
            $missingItems,
            fn (array $row) => $row['missing_policy'] === MissingGradePolicy::PENDING->value
        ));

        return $this->applyScale($gradebook, [
            'gradebook_id' => (int) $gradebook->id,
            'course_id' => (int) $gradebook->course_id,
            'user_id' => $userId,
            'normalized' => $normalized,
            'final_score' => $finalScore,
            'percentage' => $normalized,
            'maximum_grade' => $cap,
            'passing_grade' => $gradebook->passingGrade(),
            'passed' => $normalized === null ? null : $normalized >= $gradebook->passingGrade(),
            'blocks' => $blocks,
            'items' => $rows,
            'extra_credit' => $extra,
            'weighting' => [
                'policy' => $gradebook->weight_policy->value,
                'total_weight' => $base['total_weight'],
                'normalized_weight' => $base['normalised_weight'],
                'category_weight_total' => round((float) $gradebook->categories->whereNull('parent_id')->sum('weight'), 2),
            ],
            'policies' => [
                'aggregation_method' => $gradebook->aggregation_method->value,
                'missing_grade_policy' => $gradebookPolicy->value,
                'weight_policy' => $gradebook->weight_policy->value,
                'extra_credit_policy' => $gradebook->extra_credit_policy->value,
            ],
            'missing_count' => count($missingItems),
            'pending_missing_count' => count($pendingMissing),
            'exempt_count' => count(array_filter($rows, fn (array $row) => $row['is_exempt'])),
            'counted_items' => count(array_filter($rows, fn (array $row) => $row['counted'])),
            'total_items' => count($rows),
            'is_incomplete' => count($pendingMissing) > 0,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Terapkan skala nilai (§5, §6, §14, §43).
     *
     * Perhitungan internal selalu numerik; huruf/predikat hanyalah tampilan
     * hasil skala yang berlaku (course → tahun ajaran → sekolah).
     *
     * @param  array<string, mixed>  $calculation
     * @return array<string, mixed>
     */
    private function applyScale(LmsGradebook $gradebook, array $calculation): array
    {
        $scale = $this->gradebooks->scaleFor($gradebook);
        $normalized = $calculation['normalized'];
        $entry = $normalized === null || $scale === null ? null : $scale->entryFor((float) $normalized);

        return $calculation + [
            'letter' => $entry?->grade,
            'predicate' => $entry?->predicate,
            'gpa' => $entry?->gpa === null ? null : (float) $entry->gpa,
            'scale' => $scale === null ? null : ['id' => (int) $scale->id, 'name' => $scale->name],
        ];
    }

    /**
     * Hitung seluruh siswa pada gradebook (§34–§36).
     *
     * @param  list<int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    public function calculateMany(LmsGradebook $gradebook, array $userIds): array
    {
        $out = [];

        foreach ($userIds as $userId) {
            $out[$userId] = $this->calculate($gradebook, $userId);
        }

        return $out;
    }

    /**
     * Rincian satu grade item untuk seorang siswa (dipakai UI breakdown §32).
     *
     * @return array<string, mixed>
     */
    public function itemBreakdown(LmsGradeItem $item, int $userId): array
    {
        $result = $item->resultFor($userId);

        return $this->rowFor($item, $result, $userId);
    }

    // ------------------------------------------------------------------ internal

    /**
     * Satu baris item: nilai, kebijakan yang berlaku, dan kontribusinya.
     *
     * @return array<string, mixed>
     */
    private function rowFor(LmsGradeItem $item, ?LmsAssessmentResult $result, int $userId): array
    {
        $category = $item->relationLoaded('category') ? $item->category : $item->category()->first();
        $gradebook = $item->gradebook ?? $item->gradebook()->first();

        $policy = $result?->isExempt() === true
            ? MissingGradePolicy::EXCLUDE
            : ($category?->missing_grade_policy
                ?? $gradebook?->missing_grade_policy
                ?? MissingGradePolicy::PENDING);

        $isExempt = $result?->isExempt() ?? false;
        $hasScore = $result !== null && $result->status->hasScore() && ! $isExempt;
        $score = $hasScore ? $result->normalized() : null;

        // Penilaian rubric (bila ada) menjadi sumber nilai akhir item tersebut.
        $rubricAssessment = null;
        if ($hasScore && $result->final_score !== null) {
            $rubricAssessment = LmsRubricAssessment::query()
                ->where('grade_item_id', $item->id)
                ->where('user_id', $userId)
                ->first();
        }

        $value = match (true) {
            $isExempt => null,
            $hasScore => $score,
            $policy === MissingGradePolicy::MISSING_AS_ZERO => 0.0,
            default => null,
        };

        $counted = ! $isExempt
            && ($value !== null || $policy === MissingGradePolicy::MISSING_AS_ZERO)
            && ! $item->is_extra_credit;

        return [
            'grade_item_id' => (int) $item->id,
            'name' => $item->name,
            'source' => $item->source->value,
            'source_label' => $item->source->label(),
            'activity_id' => $item->activity_id === null ? null : (int) $item->activity_id,
            'category_id' => $item->category_id === null ? null : (int) $item->category_id,
            'category_name' => $category?->name,
            'weight' => (float) $item->weight,
            'maximum_grade' => (float) $item->maximum_grade,
            'raw_score' => $result?->raw_score === null ? null : (float) $result->raw_score,
            'final_score' => $result?->final_score === null ? null : (float) $result->final_score,
            'normalized' => $value === null ? null : round($value, 4),
            'percent' => $value === null ? null : round($value, 2),
            'status' => $result?->status->value ?? AssessmentResultStatus::PENDING->value,
            'status_label' => $result?->status->label() ?? 'Belum ada nilai',
            'feedback' => $result?->feedback,
            'graded_at' => $result?->graded_at?->toIso8601String(),
            'released_at' => $result?->released_at?->toIso8601String(),
            'rubric_assessment_id' => $rubricAssessment?->id === null ? null : (int) $rubricAssessment->id,
            'is_exempt' => $isExempt,
            'exemption_reason' => $result?->exemption_reason,
            'is_extra_credit' => (bool) $item->is_extra_credit,
            'is_missing' => ! $hasScore && ! $isExempt,
            'missing_policy' => $policy->value,
            'missing_policy_label' => $policy->label(),
            'counted' => $counted,
            'passed' => $value === null ? null : $value >= ($item->passingGrade() / max((float) $item->maximum_grade, 0.01) * 100),
        ];
    }

    /**
     * Agregasi kategori (mendukung bersarang & kategori extra credit).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function aggregateCategories(LmsGradebook $gradebook, array $rows, MissingGradePolicy $fallbackPolicy): array
    {
        $byCategory = [];

        foreach ($rows as $row) {
            if ($row['category_id'] === null) {
                continue;
            }

            $byCategory[$row['category_id']][] = $row;
        }

        $out = [];

        foreach ($gradebook->categories->sortBy('sort_order') as $category) {
            $own = $byCategory[$category->id] ?? [];
            $childBlocks = [];

            foreach ($category->children ?? [] as $child) {
                if (($byCategory[$child->id] ?? []) === []) {
                    continue;
                }

                $childAggregate = $this->aggregateBlock(
                    $byCategory[$child->id],
                    $child->effectiveAggregationMethod($gradebook->aggregation_method),
                    $child->effectiveMissingPolicy($fallbackPolicy),
                    $child->name,
                    (int) $child->drop_lowest,
                );

                $childBlocks[] = [
                    'id' => (int) $child->id,
                    'parent_id' => (int) $category->id,
                    'name' => $child->name,
                    'weight' => (float) $child->weight,
                    'aggregation' => $child->effectiveAggregationMethod($gradebook->aggregation_method)->value,
                    'value' => $childAggregate['value'],
                    'counted' => $childAggregate['counted'],
                    'missing' => $childAggregate['missing'],
                    'is_extra_credit' => (bool) $child->is_extra_credit,
                    'include_in_final' => (bool) $child->include_in_final,
                    'items' => $childAggregate['items'],
                ];
            }

            $aggregate = $this->aggregateBlock(
                $own,
                $category->effectiveAggregationMethod($gradebook->aggregation_method),
                $category->effectiveMissingPolicy($fallbackPolicy),
                $category->name,
                (int) $category->drop_lowest,
            );

            $value = $aggregate['value'];

            // Kategori bersarang: gabungkan nilai anak-anak sebagai "item".
            if ($childBlocks !== []) {
                $childRows = array_map(fn (array $block) => [
                    'grade_item_id' => null,
                    'name' => $block['name'],
                    'weight' => $block['weight'],
                    'normalized' => $block['value'],
                    'is_extra_credit' => $block['is_extra_credit'],
                    'is_exempt' => false,
                    'is_missing' => $block['value'] === null,
                    'missing_policy' => $category->effectiveMissingPolicy($fallbackPolicy)->value,
                    'counted' => $block['value'] !== null,
                    'raw_score' => null,
                    'category_id' => $category->id,
                ], $childBlocks);

                $combined = array_merge($childRows, $own);
                $aggregate = $this->aggregateBlock(
                    $combined,
                    $category->effectiveAggregationMethod($gradebook->aggregation_method),
                    $category->effectiveMissingPolicy($fallbackPolicy),
                    $category->name,
                    (int) $category->drop_lowest,
                );
                $value = $aggregate['value'];
            }

            $out[] = [
                'id' => (int) $category->id,
                'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                'name' => $category->name,
                'weight' => (float) $category->weight,
                'aggregation' => $category->effectiveAggregationMethod($gradebook->aggregation_method)->value,
                'aggregation_label' => $category->effectiveAggregationMethod($gradebook->aggregation_method)->label(),
                'value' => $value,
                'counted' => $aggregate['counted'],
                'missing' => $aggregate['missing'],
                'is_extra_credit' => (bool) $category->is_extra_credit,
                'include_in_final' => (bool) $category->include_in_final,
                'children' => $childBlocks,
                'items' => array_values(array_map(fn (array $row) => [
                    'grade_item_id' => $row['grade_item_id'],
                    'name' => $row['name'],
                    'weight' => $row['weight'],
                    'normalized' => $row['normalized'],
                    'is_missing' => $row['is_missing'],
                    'is_exempt' => $row['is_exempt'],
                    'source_label' => $row['source_label'] ?? null,
                ], $own)),
            ];
        }

        return $out;
    }

    /**
     * Agregasi satu blok item sesuai metode (§8).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{value: float|null, counted: int, missing: int, items: list<array<string, mixed>>}
     */
    private function aggregateBlock(
        array $rows,
        AggregationMethod $method,
        MissingGradePolicy $policy,
        string $label,
        int $dropLowest = 0,
    ): array {
        $rows = array_values(array_filter($rows, fn (array $row) => ! $row['is_extra_credit']));

        $missing = count(array_filter($rows, fn (array $row) => ($row['is_missing'] ?? false) === true));

        $counted = array_values(array_filter($rows, fn (array $row) => ($row['normalized'] ?? null) !== null));

        // Kebijakan kategori bisa menimpa kebijakan gradebook (§9).
        if ($policy === MissingGradePolicy::MISSING_AS_ZERO) {
            $counted = array_values(array_filter($rows, fn (array $row) => ($row['is_exempt'] ?? false) === false));
        }

        if ($dropLowest > 0 && count($counted) > $dropLowest) {
            $sorted = $counted;
            usort($sorted, fn (array $a, array $b) => ((float) $a['normalized']) <=> ((float) $b['normalized']));
            $dropped = array_slice($sorted, 0, $dropLowest);
            $droppedIds = array_map(fn (array $row) => $row['grade_item_id'].'|'.$row['name'], $dropped);
            $counted = array_values(array_filter(
                $counted,
                fn (array $row) => ! in_array($row['grade_item_id'].'|'.$row['name'], $droppedIds, true)
            ));
        }

        if ($counted === []) {
            return ['value' => null, 'counted' => 0, 'missing' => $missing, 'items' => $rows, 'label' => $label];
        }

        $values = array_map(fn (array $row) => (float) ($row['normalized'] ?? 0.0), $counted);
        $weights = array_map(fn (array $row) => max((float) ($row['weight'] ?? 0.0), 0.0), $counted);

        $value = match ($method) {
            AggregationMethod::SUM => array_sum($values),
            AggregationMethod::SIMPLE_MEAN => array_sum($values) / count($values),
            AggregationMethod::HIGHEST => max($values),
            AggregationMethod::LOWEST => min($values),
            AggregationMethod::MEDIAN => $this->median($values),
            AggregationMethod::WEIGHTED_MEAN => $this->weightedMean($values, $weights),
        };

        return [
            'value' => round($value, 4),
            'counted' => count($counted),
            'missing' => $missing,
            'items' => $rows,
            'label' => $label,
        ];
    }

    /**
     * Gabungkan blok kategori menjadi nilai dasar 0–100 dengan bobotnya (§7).
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array{value: float|null, total_weight: float, normalised_weight: string}
     */
    private function weightedTotal(array $blocks, WeightPolicy $policy): array
    {
        $blocks = array_values(array_filter($blocks, fn (array $block) => $block['value'] !== null));

        if ($blocks === []) {
            return ['value' => null, 'total_weight' => 0.0, 'normalised_weight' => $policy->label()];
        }

        $explicitWeights = array_sum(array_map(fn (array $block) => (float) $block['weight'], $blocks));

        // Tanpa bobot eksplisit → rata-rata sederhana antar kategori.
        if ($explicitWeights <= 0.0) {
            $values = array_map(fn (array $block) => (float) $block['value'], $blocks);

            return [
                'value' => array_sum($values) / count($values),
                'total_weight' => 0.0,
                'normalised_weight' => 'rata-rata sederhana (bobot belum diisi)',
            ];
        }

        $uncategorised = array_values(array_filter($blocks, fn (array $block) => $block['id'] === null));
        $categorised = array_values(array_filter($blocks, fn (array $block) => $block['id'] !== null));

        $categorisedWeight = array_sum(array_map(fn (array $block) => (float) $block['weight'], $categorised));
        $remainder = max(100.0 - $categorisedWeight, 0.0);

        $numerator = 0.0;
        $denominator = 0.0;

        foreach ($categorised as $block) {
            $weight = (float) $block['weight'];

            if ($weight <= 0.0) {
                continue;
            }

            $numerator += (float) $block['value'] * $weight;
            $denominator += $weight;
        }

        if ($uncategorised !== []) {
            $uncategorisedValue = array_sum(array_map(fn (array $block) => (float) $block['value'], $uncategorised))
                / count($uncategorised);
            $weight = $remainder > 0.0 ? $remainder : (float) ($uncategorised[0]['weight'] ?: 0.0);

            if ($weight <= 0.0) {
                $weight = 100.0;
            }

            $numerator += $uncategorisedValue * $weight;
            $denominator += $weight;
        }

        if ($denominator <= 0.0) {
            $values = array_map(fn (array $block) => (float) $block['value'], $blocks);

            return [
                'value' => array_sum($values) / count($values),
                'total_weight' => $explicitWeights,
                'normalised_weight' => 'rata-rata sederhana (bobot tidak valid)',
            ];
        }

        // Nilai item sudah dalam skala 0–100, jadi cukup dibagi total bobot.
        $value = $numerator / $denominator;
        $exact = abs($denominator - 100.0) < 0.01;

        return [
            'value' => $value,
            'total_weight' => round($explicitWeights, 2),
            'normalised_weight' => $policy === WeightPolicy::STRICT
                ? ($exact ? 'total bobot tepat 100%' : 'total bobot belum 100%')
                : 'dinormalisasi dari total '.number_format($denominator, 2).'%',
        ];
    }

    /**
     * Extra credit (§11): poin tambahan di luar nilai dasar.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{points: float, capped: bool, items: list<array<string, mixed>>, policy: string}
     */
    private function extraCreditTotal(array $rows, LmsGradebook $gradebook): array
    {
        $policy = $gradebook->extra_credit_policy;
        $points = 0.0;
        $items = [];

        foreach ($rows as $row) {
            $value = $row['normalized'] ?? null;

            if ($value === null) {
                continue;
            }

            $contribution = round((float) $value / 100 * max((float) $row['weight'], 0.0), 4);

            if ($policy === ExtraCreditPolicy::IGNORE) {
                $contribution = 0.0;
            }

            $items[] = [
                'grade_item_id' => $row['grade_item_id'],
                'name' => $row['name'],
                'normalized' => round((float) $value, 2),
                'points' => $contribution,
            ];

            $points += $contribution;
        }

        return [
            'points' => round($points, 4),
            'capped' => $policy === ExtraCreditPolicy::CAP,
            'items' => $items,
            'policy' => $policy->value,
        ];
    }

    /** @param list<float> $values */
    private function weightedMean(array $values, array $weights): float
    {
        $weightSum = array_sum($weights);

        if ($weightSum <= 0.0) {
            return array_sum($values) / count($values);
        }

        $numerator = 0.0;

        foreach ($values as $index => $value) {
            $weight = $weights[$index] > 0.0 ? $weights[$index] : 1.0;
            $numerator += $value * $weight;
        }

        return $numerator / array_sum(array_map(fn (float $weight) => $weight > 0.0 ? $weight : 1.0, $weights));
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];
    }
}
