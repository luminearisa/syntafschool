<?php

namespace App\Modules\Workflow\Infrastructure\Conditions;

use App\Modules\Workflow\Domain\Contracts\ConditionEvaluatorInterface;
use App\Modules\Workflow\Domain\Enums\ConditionOperator;
use App\Modules\Workflow\Domain\Support\WorkflowContext;

/**
 * Evaluator kondisi bawaan (§30).
 *
 * Ekspresi menerima tiga bentuk, semuanya divalidasi saat publish:
 *
 * ```json
 * { "field": "amount", "operator": ">", "value": 10000000 }
 *
 * { "any": [ {…}, {…} ],
 *   "all": [ { "field": "department", "operator": "=", "value": "FINANCE" } ] }
 *
 * { "logic": "AND", "clauses": [ {…}, {…} ] }
 * ```
 *
 * Perbandingan angka toleran terhadap tipe: nilai dari basis data bisnis sering
 * tiba sebagai string ("15000000.00"), dan kondisi workflow tidak boleh gagal
 * hanya karena itu.
 */
class DefaultConditionEvaluator implements ConditionEvaluatorInterface
{
    public function evaluate(?array $expression, WorkflowContext $context): bool
    {
        if ($expression === null || $expression === []) {
            return true;
        }

        // 1. Pembungkus logika bersarang.
        if (array_key_exists('all', $expression)) {
            foreach ((array) $expression['all'] as $child) {
                if (! $this->evaluate((array) $child, $context)) {
                    return false;
                }
            }

            return true;
        }

        if (array_key_exists('any', $expression)) {
            foreach ((array) $expression['any'] as $child) {
                if ($this->evaluate((array) $child, $context)) {
                    return true;
                }
            }

            return false;
        }

        if (array_key_exists('not', $expression)) {
            return ! $this->evaluate((array) $expression['not'], $context);
        }

        if (array_key_exists('clauses', $expression)) {
            return $this->evaluateGroup(
                strtoupper((string) ($expression['logic'] ?? 'AND')),
                (array) $expression['clauses'],
                $context,
            );
        }

        if (array_key_exists('logic', $expression) && is_array($expression['logic'] ?? null)) {
            // Bentuk ringkas: { "logic": { ... } } diperlakukan sebagai klausa tunggal.
            return $this->evaluate((array) $expression['logic'], $context);
        }

        // 2. Daftar klausa langsung.
        if (array_is_list($expression)) {
            foreach ($expression as $child) {
                if (! $this->evaluate((array) $child, $context)) {
                    return false;
                }
            }

            return true;
        }

        // 3. Klausa tunggal.
        return $this->evaluateLeaf($expression, $context);
    }

    public function supports(string $operator): bool
    {
        return ConditionOperator::tryFrom(strtoupper($operator)) !== null;
    }

    public function operators(): array
    {
        return ConditionOperator::values();
    }

    /**
     * @return list<string>
     */
    public function validate(?array $expression): array
    {
        if ($expression === null || $expression === []) {
            return [];
        }

        return $this->validateNode($expression, 'kondisi');
    }

    /** @param array<string, mixed> $expression */
    protected function validateNode(array $expression, string $path): array
    {
        $errors = [];

        if (array_key_exists('all', $expression) || array_key_exists('any', $expression)) {
            $key = array_key_exists('all', $expression) ? 'all' : 'any';
            $children = $expression[$key];

            if (! is_array($children) || $children === []) {
                return ["{$path}: grup `{$key}` tidak boleh kosong."];
            }

            foreach ($children as $index => $child) {
                if (! is_array($child)) {
                    $errors[] = "{$path}.{$key}[{$index}]: klausa harus berupa objek.";

                    continue;
                }

                $errors = array_merge($errors, $this->validateNode($child, "{$path}.{$key}[{$index}]"));
            }

            return $errors;
        }

        if (array_key_exists('not', $expression)) {
            if (! is_array($expression['not'])) {
                return ["{$path}.not: klausa harus berupa objek."];
            }

            return $this->validateNode($expression['not'], "{$path}.not");
        }

        if (array_key_exists('clauses', $expression)) {
            $logic = strtoupper((string) ($expression['logic'] ?? 'AND'));

            if (! in_array($logic, ['AND', 'OR', 'NOT'], true)) {
                $errors[] = "{$path}.logic: harus AND, OR, atau NOT.";
            }

            foreach ((array) $expression['clauses'] as $index => $child) {
                if (! is_array($child)) {
                    $errors[] = "{$path}.clauses[{$index}]: klausa harus berupa objek.";

                    continue;
                }

                $errors = array_merge($errors, $this->validateNode($child, "{$path}.clauses[{$index}]"));
            }

            return $errors;
        }

        if (array_is_list($expression)) {
            foreach ($expression as $index => $child) {
                if (! is_array($child)) {
                    $errors[] = "{$path}[{$index}]: klausa harus berupa objek.";

                    continue;
                }

                $errors = array_merge($errors, $this->validateNode($child, "{$path}[{$index}]"));
            }

            return $errors;
        }

        return $this->validateLeaf($expression, $path);
    }

    /** @param array<string, mixed> $leaf */
    protected function validateLeaf(array $leaf, string $path): array
    {
        // Bentuk ringkas { "amount": { ">": 100 } }.
        if (! array_key_exists('field', $leaf)) {
            if (count($leaf) === 1) {
                $field = (string) array_key_first($leaf);
                $operatorMap = $leaf[$field];

                if (is_array($operatorMap)) {
                    foreach ($operatorMap as $operator => $value) {
                        if ($this->supports((string) $operator)) {
                            return [];
                        }

                        return ["{$path}: operator `{$operator}` tidak dikenal (field {$field})."];
                    }

                    return ["{$path}: operasi pada field {$field} kosong."];
                }
            }

            return ["{$path}: setiap klausa harus punya `field` dan `operator`."];
        }

        $operator = strtoupper((string) ($leaf['operator'] ?? $leaf['op'] ?? ''));

        if ($operator === '') {
            return ["{$path}: klausa pada field {$leaf['field']} tidak menyebut operator."];
        }

        $enum = ConditionOperator::tryFrom($operator);

        if ($enum === null) {
            return ["{$path}: operator `{$operator}` tidak dikenal."];
        }

        if ($enum->expectsValue() && ! array_key_exists('value', $leaf)) {
            return ["{$path}: operator `{$operator}` membutuhkan `value`."];
        }

        if ($enum->expectsList() && ! is_array($leaf['value'] ?? null)) {
            return ["{$path}: operator `{$operator}` membutuhkan `value` berupa array."];
        }

        if ($enum === ConditionOperator::BETWEEN && count((array) ($leaf['value'] ?? [])) !== 2) {
            return ["{$path}: operator BETWEEN membutuhkan tepat dua nilai."];
        }

        return [];
    }

    /** @param list<array<string, mixed>> $clauses */
    protected function evaluateGroup(string $logic, array $clauses, WorkflowContext $context): bool
    {
        if ($clauses === []) {
            return true;
        }

        return match ($logic) {
            'OR' => (bool) array_filter($clauses, fn ($clause) => $this->evaluate((array) $clause, $context)),
            'NOT' => ! $this->evaluate($clauses[0], $context),
            default => ! in_array(false, array_map(fn ($clause) => $this->evaluate((array) $clause, $context), $clauses), true),
        };
    }

    /** @param array<string, mixed> $leaf */
    protected function evaluateLeaf(array $leaf, WorkflowContext $context): bool
    {
        // Bentuk ringkas { "amount": { ">": 10000000 } }.
        if (! array_key_exists('field', $leaf)) {
            if (count($leaf) !== 1) {
                return false;
            }

            $field = (string) array_key_first($leaf);
            $operatorMap = $leaf[$field];

            if (! is_array($operatorMap) || $operatorMap === []) {
                return false;
            }

            foreach ($operatorMap as $operator => $value) {
                if (! $this->compare((string) $operator, $context->get($field), $value, $context, $field)) {
                    return false;
                }
            }

            return true;
        }

        $field = (string) $leaf['field'];
        $operator = strtoupper((string) ($leaf['operator'] ?? $leaf['op'] ?? ''));

        return $this->compare($operator, $context->get($field), $leaf['value'] ?? null, $context, $field);
    }

    protected function compare(string $operator, mixed $actual, mixed $expected, WorkflowContext $context, string $field): bool
    {
        $enum = ConditionOperator::tryFrom($operator);

        if ($enum === null) {
            // Operator tak dikenal diperlakukan sebagai tidak terpenuhi, bukan
            // "true" — kegagalan config tidak boleh meloloskan approval.
            return false;
        }

        return match ($enum) {
            ConditionOperator::IS_EMPTY => $context->isEmpty($field),
            ConditionOperator::IS_NOT_EMPTY => $context->isNotEmpty($field),
            ConditionOperator::EQUALS => $this->looseEquals($actual, $expected),
            ConditionOperator::NOT_EQUALS => ! $this->looseEquals($actual, $expected),
            ConditionOperator::GREATER_THAN => $this->numericCompare($actual, $expected) > 0,
            ConditionOperator::GREATER_THAN_OR_EQUAL => $this->numericCompare($actual, $expected) >= 0,
            ConditionOperator::LESS_THAN => $this->numericCompare($actual, $expected) < 0,
            ConditionOperator::LESS_THAN_OR_EQUAL => $this->numericCompare($actual, $expected) <= 0,
            ConditionOperator::IN => $this->inList($actual, (array) $expected),
            ConditionOperator::NOT_IN => ! $this->inList($actual, (array) $expected),
            ConditionOperator::CONTAINS => $this->contains($actual, $expected),
            ConditionOperator::BETWEEN => $this->between($actual, (array) $expected),
        };
    }

    protected function looseEquals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual) || is_bool($expected)) {
            return (bool) $actual === $this->toBool($expected);
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        if (is_array($actual)) {
            return in_array($expected, $actual, false);
        }

        return (string) $actual === (string) $expected;
    }

    protected function numericCompare(mixed $actual, mixed $expected): int
    {
        if ($actual === null || $expected === null) {
            return -1;
        }

        if (is_string($actual) && ! is_numeric($actual)) {
            // Perbandingan tanggal/waktu bila nilai bukan angka.
            $left = strtotime($actual);
            $right = is_numeric($expected) ? (int) $expected : strtotime((string) $expected);

            if ($left === false || $right === false) {
                return -1;
            }

            return $left <=> $right;
        }

        return (float) $actual <=> (float) $expected;
    }

    /** @param list<mixed> $list */
    protected function inList(mixed $actual, array $list): bool
    {
        foreach ($list as $candidate) {
            if ($this->looseEquals($actual, $candidate)) {
                return true;
            }
        }

        return false;
    }

    protected function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return $this->inList($expected, $actual);
        }

        if (is_string($actual) && $expected !== null) {
            return mb_stripos($actual, (string) $expected) !== false;
        }

        return false;
    }

    /** @param array<int, mixed> $range */
    protected function between(mixed $actual, array $range): bool
    {
        if (count($range) !== 2) {
            return false;
        }

        [$low, $high] = array_values($range);

        return $this->numericCompare($actual, $low) >= 0 && $this->numericCompare($actual, $high) <= 0;
    }

    protected function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
