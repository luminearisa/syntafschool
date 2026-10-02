<?php

namespace App\Modules\Hrd\Core\Services;

use App\Modules\Hrd\Core\Models\HrRule;
use App\Modules\Hrd\Core\Models\HrRuleCondition;
use App\Modules\Hrd\Core\Models\HrRuleEvaluation;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrSkill;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Mesin aturan generik (§106).
 *
 * Aturan bisnis yang sering berubah — kelayakan benefit, pelatihan wajib,
 * syarat cuti, kesiapan karier — disimpan sebagai data (`hr_rules` +
 * `hr_rule_conditions`) dengan versi berlaku, bukan ditulis sebagai `if` di
 * dalam kode. Setiap penilaian meninggalkan jejak (`hr_rule_evaluations`)
 * sehingga pertanyaan "mengapa pegawai ini dianggap layak?" dapat dijawab
 * dari data, bukan dari ingatan pengembang.
 */
class RuleEngine
{
    /** @param array<string, mixed> $extra */
    public function context(HrEmployee $employee, array $extra = []): array
    {
        $employee->loadMissing(['person', 'position', 'organization', 'department']);

        $hireDate = $employee->hire_date !== null ? Carbon::parse($employee->hire_date) : null;
        $birthDate = $employee->person?->birth_date !== null ? Carbon::parse($employee->person->birth_date) : null;

        $context = [
            'employee_id' => (int) $employee->getKey(),
            'worker_type' => $employee->worker_type,
            'employment_type' => $employee->employment_type,
            'employment_status' => $employee->employment_status,
            'lifecycle_state' => $employee->lifecycle_state,
            'is_active' => $employee->lifecycle_state === 'active',
            'organization_id' => $employee->organization_id,
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'location_id' => $employee->location_id,
            'job_id' => $employee->position?->job_id,
            'job_family_id' => $employee->position?->job_family_id,
            'grade_code' => $employee->position?->grade_code,
            'level_code' => $employee->position?->level_code,
            'position_type' => $employee->position?->position_type,
            'gender' => $employee->person?->gender,
            'age' => $birthDate?->age,
            'tenure_months' => $hireDate !== null ? (int) $hireDate->diffInMonths(now()) : null,
            'tenure_years' => $hireDate !== null ? round($hireDate->diffInMonths(now()) / 12, 2) : null,
            'hired_at' => $hireDate?->toDateString(),
            'today' => now()->toDateString(),
        ];

        return array_merge($context, $extra);
    }

    /** @param array<string, mixed> $context */
    public function matches(HrRule $rule, array $context): bool
    {
        $conditions = $rule->relationLoaded('conditions') ? $rule->conditions : $rule->conditions()->orderBy('sequence')->get();

        if ($conditions->isEmpty()) {
            // Aturan tanpa syarat = selalu berlaku (mis. benefit untuk semua pegawai).
            return true;
        }

        $all = strtoupper((string) $rule->match_mode) !== 'ANY';

        foreach ($conditions as $condition) {
            $passed = $this->conditionMatches($condition, $context);

            if ($all && ! $passed) {
                return false;
            }

            if (! $all && $passed) {
                return true;
            }
        }

        return $all;
    }

    /** @param array<string, mixed> $context */
    public function conditionMatches(HrRuleCondition $condition, array $context): bool
    {
        $actual = $context[$condition->field] ?? null;
        $expected = $this->decode($condition->value);

        // Nilai kondisi disimpan sebagai daftar agar operator IN/BETWEEN seragam;
        // satu tingkat bersarang dibuka di sini supaya tidak ada array di dalam array.
        // Nilai kondisi selalu disimpan sebagai daftar; satu elemen dibuka agar
        // operator skalar (=, >, CONTAINS) menerima nilai tunggal, bukan daftar.
        if (is_array($expected) && count($expected) === 1) {
            $expected = $expected[0];
        }
        $operator = strtoupper((string) $condition->operator);

        return match ($operator) {
            '=' => $this->looseEquals($actual, $expected),
            '!=' => ! $this->looseEquals($actual, $expected),
            '>' => $this->numeric($actual) > $this->numeric($expected),
            '>=' => $this->numeric($actual) >= $this->numeric($expected),
            '<' => $this->numeric($actual) < $this->numeric($expected),
            '<=' => $this->numeric($actual) <= $this->numeric($expected),
            'IN' => in_array((string) $actual, array_map(fn ($value) => (string) $value, (array) $expected), true),
            'NOT_IN' => ! in_array((string) $actual, array_map(fn ($value) => (string) $value, (array) $expected), true),
            'CONTAINS' => str_contains(strtolower((string) $actual), strtolower((string) $expected)),
            'IS_NULL' => $actual === null || $actual === '',
            'NOT_NULL' => $actual !== null && $actual !== '',
            'BETWEEN' => $this->between($actual, (array) $expected),
            default => false,
        };
    }

    /**
     * Aturan aktif yang cocok, terurut prioritas tertinggi lebih dahulu.
     *
     * @param array<string, mixed> $extra
     * @return Collection<int, HrRule>
     */
    public function evaluate(string $purpose, HrEmployee $employee, array $extra = [], bool $withTrace = false): Collection
    {
        $context = $this->context($employee, $extra);
        $today = now()->toDateString();

        $rules = HrRule::query()
            ->with('conditions')
            ->where('purpose', $purpose)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->orderByDesc('priority')
            ->get();

        $matched = $rules->filter(fn (HrRule $rule) => $this->matches($rule, $context))->values();

        if ($withTrace) {
            $this->trace($purpose, $employee, $rules, $matched, $context);
        }

        return $matched;
    }

    /**
     * Gabungan muatan aksi dari seluruh aturan yang cocok.
     *
     * @param array<string, mixed> $extra
     * @return list<array<string, mixed>>
     */
    public function actions(string $purpose, HrEmployee $employee, ?string $actionType = null, array $extra = []): array
    {
        return $this->evaluate($purpose, $employee, $extra)
            ->when($actionType !== null, fn (Collection $rules) => $rules->filter(
                fn (HrRule $rule) => strtoupper((string) $rule->action_type) === strtoupper($actionType),
            ))
            ->map(fn (HrRule $rule) => array_merge(
                is_array($rule->action_payload) ? $rule->action_payload : [],
                ['_rule_code' => $rule->code, '_action_type' => $rule->action_type, '_priority' => $rule->priority],
            ))
            ->values()
            ->all();
    }

    /**
     * Penjelasan per aturan dan per kondisi — dipakai layar HR agar keputusan
     * sistem dapat ditelusuri manusia.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function explain(string $purpose, HrEmployee $employee, array $extra = []): array
    {
        $context = $this->context($employee, $extra);

        $rules = HrRule::query()->with('conditions')->where('purpose', $purpose)->where('is_active', true)->orderByDesc('priority')->get();

        return [
            'purpose' => $purpose,
            'employee_id' => $employee->getKey(),
            'context' => $context,
            'rules' => $rules->map(fn (HrRule $rule) => [
                'code' => $rule->code,
                'name' => $rule->name,
                'matched' => $this->matches($rule, $context),
                'action_type' => $rule->action_type,
                'action_payload' => $rule->action_payload,
                'conditions' => $rule->conditions->map(fn (HrRuleCondition $condition) => [
                    'field' => $condition->field,
                    'operator' => $condition->operator,
                    'expected' => $condition->value,
                    'actual' => $context[$condition->field] ?? null,
                    'passed' => $this->conditionMatches($condition, $context),
                ])->all(),
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $skillCodes */
    public function skillsFor(int $employeeId): Collection
    {
        return HrSkill::query()->whereIn('code', (array) config('hrd.phase1.skills', []))->get();
    }

    /** @param Collection<int, HrRule> $rules @param Collection<int, HrRule> $matched @param array<string, mixed> $context */
    private function trace(string $purpose, HrEmployee $employee, Collection $rules, Collection $matched, array $context): void
    {
        foreach ($rules as $rule) {
            $isMatch = $matched->contains('id', $rule->getKey());

            HrRuleEvaluation::query()->create([
                'rule_id' => $rule->getKey(),
                'purpose' => $purpose,
                'subject_type' => HrEmployee::class,
                'subject_id' => $employee->getKey(),
                'employee_id' => $employee->getKey(),
                'matched' => $isMatch,
                'trace' => ['context' => $context, 'conditions' => $rule->conditions->count()],
                'evaluated_at' => now(),
            ]);
        }
    }

    private function looseEquals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual) || is_bool($expected)) {
            return filter_var($actual, FILTER_VALIDATE_BOOLEAN) === filter_var($expected, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return (string) $actual === (string) $expected;
    }

    private function numeric(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** @param list<mixed> $range */
    private function between(mixed $actual, array $range): bool
    {
        if (count($range) < 2) {
            return false;
        }

        return $this->numeric($actual) >= $this->numeric($range[0]) && $this->numeric($actual) <= $this->numeric($range[1]);
    }

    private function decode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
