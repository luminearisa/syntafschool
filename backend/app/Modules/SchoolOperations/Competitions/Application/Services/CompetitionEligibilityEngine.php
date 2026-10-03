<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\StudentProviderInterface;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCategory;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCategoryEligibilityRule;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEligibilityEvaluation;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEligibilityRuleResult;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRegistration;
use Illuminate\Support\Facades\DB;

/** Configurable, evidence-producing eligibility evaluator. */
class CompetitionEligibilityEngine
{
    public function __construct(private readonly StudentProviderInterface $students) {}

    /** @return array{result:string, reasons:list<array<string,mixed>>, evaluation?:CompetitionEligibilityEvaluation} */
    public function evaluate(CompetitionRegistration $registration, ?User $actor = null, bool $persist = true): array
    {
        $category = CompetitionCategory::query()->findOrFail($registration->category_id);
        $snapshot = null;
        if ($registration->participant_type === 'STUDENT' && $registration->student_id !== null) {
            $snapshot = $this->students->snapshot((int) $registration->student_id);
        }

        $context = array_merge($snapshot ?? [], (array) ($registration->metadata ?? []));
        $checks = [];

        if ($registration->participant_type === 'STUDENT') {
            $checks[] = [
                'rule_code' => 'STUDENT_ACTIVE',
                'rule_type' => 'STUDENT_STATUS',
                'actual' => $snapshot['active'] ?? null,
                'operator' => 'EQ',
                'expected' => true,
                'required' => true,
                'message' => 'Peserta harus siswa aktif.',
            ];
        }

        if ($category->min_grade_level !== null || $category->max_grade_level !== null) {
            $checks[] = [
                'rule_code' => 'CATEGORY_GRADE_RANGE',
                'rule_type' => 'GRADE',
                'actual' => $this->grade($context['grade_level'] ?? $context['class_level'] ?? null),
                'operator' => 'BETWEEN',
                'expected' => [$this->grade($category->min_grade_level), $this->grade($category->max_grade_level)],
                'required' => true,
                'message' => 'Tingkat kelas tidak memenuhi rentang kategori.',
            ];
        }

        if ($category->gender_restriction !== null && strtoupper($category->gender_restriction) !== 'ANY') {
            $checks[] = [
                'rule_code' => 'CATEGORY_GENDER',
                'rule_type' => 'GENDER',
                'actual' => $this->gender($context['gender'] ?? null),
                'operator' => 'EQ',
                'expected' => $this->gender($category->gender_restriction),
                'required' => true,
                'message' => 'Jenis kelamin tidak memenuhi kategori.',
            ];
        }

        if ($category->min_age !== null || $category->max_age !== null) {
            $checks[] = [
                'rule_code' => 'CATEGORY_AGE_RANGE',
                'rule_type' => 'AGE',
                'actual' => $context['age_years'] ?? null,
                'operator' => 'BETWEEN',
                'expected' => [$category->min_age, $category->max_age],
                'required' => true,
                'message' => 'Usia tidak memenuhi rentang kategori.',
            ];
        }

        if ($category->program_restriction !== null) {
            $checks[] = [
                'rule_code' => 'CATEGORY_PROGRAM',
                'rule_type' => 'PROGRAM',
                'actual' => $context['program'] ?? $context['program_id'] ?? null,
                'operator' => 'EQ',
                'expected' => $category->program_restriction,
                'required' => true,
                'message' => 'Program siswa tidak memenuhi kategori.',
            ];
        }

        foreach (CompetitionCategoryEligibilityRule::query()
            ->where('category_id', $category->id)
            ->where('status', 'ACTIVE')
            ->orderBy('sort_order')->orderBy('id')->get() as $rule) {
            $parameters = (array) ($rule->parameters ?? []);
            $field = $parameters['field'] ?? $this->fieldForType((string) $rule->rule_type);
            $checks[] = [
                'rule_id' => $rule->id,
                'rule_code' => $rule->rule_code,
                'rule_type' => $rule->rule_type,
                'actual' => $field === null ? null : data_get($context, $field),
                'operator' => strtoupper((string) $rule->operator),
                'expected' => array_key_exists('value', $parameters) ? $parameters['value'] : $this->decodeExpected($rule->expected_value),
                'required' => (bool) $rule->is_required,
                'weight' => $rule->weight,
                'message' => $rule->failure_message ?: 'Syarat '.$rule->name.' belum terpenuhi.',
            ];
        }

        $results = [];
        $failed = false;
        $needsReview = false;

        foreach ($checks as $check) {
            $actual = $check['actual'];
            $expected = $check['expected'];
            $known = $actual !== null;
            $passed = $known && $this->compare($actual, $expected, $check['operator']);

            if (! $known && ! $check['required']) {
                $passed = true;
            } elseif (! $known) {
                $needsReview = true;
            } elseif (! $passed && $check['required']) {
                $failed = true;
            }

            $results[] = [
                ...$check,
                'passed' => $passed,
                'known' => $known,
                'actual_value' => is_scalar($actual) ? (string) $actual : json_encode($actual),
                'expected_value' => is_scalar($expected) ? (string) $expected : json_encode($expected),
            ];
        }

        $result = $failed ? 'NOT_ELIGIBLE' : ($needsReview ? 'NEEDS_REVIEW' : 'ELIGIBLE');
        $reasons = array_values(array_map(
            fn (array $row): array => [
                'rule_code' => $row['rule_code'],
                'message' => $row['passed'] ? null : $row['message'],
                'status' => ! $row['known'] ? 'UNKNOWN' : ($row['passed'] ? 'PASSED' : 'FAILED'),
            ],
            array_filter($results, fn (array $row): bool => ! $row['passed']),
        ));

        if (! $persist) {
            return ['result' => $result, 'reasons' => $reasons];
        }

        return DB::transaction(function () use ($registration, $actor, $category, $result, $reasons, $results, $snapshot): array {
            $evaluation = CompetitionEligibilityEvaluation::query()->create([
                'registration_id' => $registration->id,
                'participant_id' => null,
                'category_id' => $category->id,
                'result' => $result,
                'rules_evaluated' => count($results),
                'rules_passed' => count(array_filter($results, fn (array $row): bool => $row['passed'])),
                'rules_failed' => count(array_filter($results, fn (array $row): bool => $row['known'] && ! $row['passed'])),
                'reasons' => $reasons,
                'evidence' => ['student' => $snapshot, 'evaluated_at' => now()->toIso8601String()],
                'engine_version' => '1.0',
                'evaluated_by' => $actor?->id,
                'evaluated_at' => now(),
            ]);

            foreach ($results as $row) {
                CompetitionEligibilityRuleResult::query()->create([
                    'evaluation_id' => $evaluation->id,
                    'rule_id' => $row['rule_id'] ?? null,
                    'rule_code' => $row['rule_code'],
                    'rule_type' => $row['rule_type'],
                    'passed' => (bool) $row['passed'],
                    'actual_value' => $row['actual_value'],
                    'expected_value' => $row['expected_value'],
                    'message' => $row['passed'] ? null : $row['message'],
                    'weight' => $row['weight'] ?? null,
                    'contributed_score' => null,
                    'evidence' => ['known' => $row['known']],
                ]);
            }

            $registration->forceFill(['eligibility_status' => $result])->save();

            return ['result' => $result, 'reasons' => $reasons, 'evaluation' => $evaluation->load('competitionEligibilityRuleResults')];
        });
    }

    private function fieldForType(string $type): ?string
    {
        return match (strtoupper($type)) {
            'STUDENT_STATUS', 'STATUS' => 'active',
            'GRADE', 'GRADE_LEVEL' => 'grade_level',
            'CLASS' => 'class_id',
            'PROGRAM', 'MAJOR' => 'program',
            'CAMPUS' => 'campus_id',
            'AGE' => 'age_years',
            'GENDER' => 'gender',
            'ATTENDANCE', 'MIN_ATTENDANCE' => 'attendance_percentage',
            'SKILL' => 'skills',
            'MEMBERSHIP' => 'membership',
            'PREVIOUS_QUALIFICATION' => 'previous_qualification',
            default => null,
        };
    }

    private function compare(mixed $actual, mixed $expected, string $operator): bool
    {
        $operator = strtoupper($operator);
        if ($operator === 'BETWEEN') {
            if (! is_array($expected) || count($expected) < 2) {
                return false;
            }
            $min = $expected[0] === null ? null : (float) $expected[0];
            $max = $expected[1] === null ? null : (float) $expected[1];
            $actualNumber = is_numeric($actual) ? (float) $actual : null;

            return $actualNumber !== null
                && ($min === null || $actualNumber >= $min)
                && ($max === null || $actualNumber <= $max);
        }

        if (in_array($operator, ['IN', 'NOT_IN'], true)) {
            $set = is_array($expected) ? $expected : explode(',', (string) $expected);
            $contains = in_array((string) $actual, array_map('strval', $set), true);

            return $operator === 'IN' ? $contains : ! $contains;
        }

        if (in_array($operator, ['GTE', 'LTE', 'GT', 'LT'], true)) {
            if (! is_numeric($actual) || ! is_numeric($expected)) {
                return false;
            }

            return match ($operator) {
                'GTE' => (float) $actual >= (float) $expected,
                'LTE' => (float) $actual <= (float) $expected,
                'GT' => (float) $actual > (float) $expected,
                'LT' => (float) $actual < (float) $expected,
            };
        }

        $equals = is_bool($actual) || is_bool($expected)
            ? (bool) $actual === (bool) $expected
            : mb_strtolower((string) $actual) === mb_strtolower((string) $expected);

        return match ($operator) {
            'NEQ', '!=' => ! $equals,
            'CONTAINS' => is_string($actual) && str_contains(mb_strtolower($actual), mb_strtolower((string) $expected)),
            'EXISTS' => $actual !== null,
            default => $equals,
        };
    }

    private function decodeExpected(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function grade(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (! is_string($value)) {
            return null;
        }

        return match (strtoupper(trim($value))) {
            'VII', 'SD' => 7,
            'VIII', 'SMP' => 8,
            'IX' => 9,
            'X', 'SMA' => 10,
            'XI' => 11,
            'XII' => 12,
            default => preg_match('/\d+/', $value, $matches) ? (int) $matches[0] : null,
        };
    }

    private function gender(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = mb_strtolower(trim((string) $value));

        return match (true) {
            in_array($value, ['male', 'm', 'laki-laki', 'laki laki', 'putra', 'pria'], true) => 'MALE',
            in_array($value, ['female', 'f', 'perempuan', 'putri', 'wanita'], true) => 'FEMALE',
            in_array($value, ['any', 'all', 'semua'], true) => 'ANY',
            default => mb_strtoupper($value),
        };
    }
}
