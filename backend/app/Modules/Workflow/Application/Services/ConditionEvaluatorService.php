<?php

namespace App\Modules\Workflow\Application\Services;

use App\Modules\Workflow\Domain\Contracts\ConditionEvaluatorInterface;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use App\Modules\Workflow\Domain\Support\WorkflowGraph;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;
use App\Modules\Workflow\Models\WorkflowVersion;

/**
 * Jembatan antara definisi (kondisi bernama / inline) dan evaluator (§30, §74).
 *
 * Kondisi dapat ditulis dua cara, dan keduanya sah:
 *  - `condition_code` → merujuk baris `workflow_conditions` (dipakai ulang), atau
 *  - `condition`      → ekspresi inline pada node/edge (sekali pakai).
 *
 * Kelas ini juga yang menentukan cabang mana yang ditempuh sebuah node
 * CONDITION: edge pertama (urut `priority`) yang kondisinya benar; edge
 * `is_default` selalu menjadi pilihan terakhir, dan bila tidak ada yang cocok
 * sama sekali alur berhenti dengan kegagalan yang terlihat (§58 semangat yang
 * sama: tidak ada keputusan diam-diam).
 */
class ConditionEvaluatorService
{
    public function __construct(
        private readonly WorkflowRegistrar $registrar,
    ) {
    }

    public function evaluator(): ConditionEvaluatorInterface
    {
        return $this->registrar->conditionEvaluator();
    }

    /** @param array<string, mixed>|null $expression */
    public function evaluate(?array $expression, WorkflowContext $context): bool
    {
        return $this->evaluator()->evaluate($expression, $context);
    }

    /**
     * Ekspresi efektif sebuah node/edge.
     *
     * @param  array<string, mixed>|null  $inline
     * @return array<string, mixed>|null
     */
    public function expressionFor(?string $conditionCode, ?array $inline, ?WorkflowVersion $version = null): ?array
    {
        $inline = $inline === null || $inline === [] ? null : $inline;

        if ($inline !== null) {
            return $inline;
        }

        if ($conditionCode === null || $version === null) {
            return null;
        }

        $condition = $version->conditions()
            ->where('code', $conditionCode)
            ->first();

        return $condition?->toExpression();
    }

    /**
     * Cabang yang ditempuh dari sebuah node.
     *
     * @return array{target: string|null, inactive: list<array{from_key: string, to_key: string}>, matched: string|null}
     */
    public function resolveBranch(WorkflowGraph $graph, string $nodeKey, WorkflowContext $context, ?WorkflowVersion $version = null): array
    {
        $edges = $graph->outgoing($nodeKey);
        $inactive = [];
        $fallback = null;
        $fallbackKey = null;

        $key = fn (array $edge): string => $edge['from_key'].'|'.$edge['to_key'];

        foreach ($edges as $edge) {
            $expression = $this->expressionFor(
                isset($edge['condition_code']) ? (string) $edge['condition_code'] : null,
                isset($edge['condition']) ? (array) $edge['condition'] : null,
                $version,
            );

            if ($expression === null) {
                // Edge tanpa kondisi = jalur default. Hanya boleh ada satu.
                if ($fallback === null || (bool) ($edge['is_default'] ?? false)) {
                    $fallback = $edge;
                    $fallbackKey = $key($edge);
                }

                continue;
            }

            if ($this->evaluate($expression, $context) && ! $this->edgeDisabled($edge)) {
                foreach ($edges as $other) {
                    if ($key($other) !== $key($edge)) {
                        $inactive[$key($other)] = ['from_key' => (string) $other['from_key'], 'to_key' => (string) $other['to_key']];
                    }
                }

                return [
                    'target' => (string) $edge['to_key'],
                    'inactive' => array_values($inactive),
                    'matched' => (string) $edge['to_key'],
                ];
            }
        }

        if ($fallback !== null) {
            foreach ($edges as $other) {
                if ($key($other) !== $fallbackKey) {
                    $inactive[$key($other)] = ['from_key' => (string) $other['from_key'], 'to_key' => (string) $other['to_key']];
                }
            }

            return [
                'target' => (string) $fallback['to_key'],
                'inactive' => array_values($inactive),
                'matched' => 'default',
            ];
        }

        return ['target' => null, 'inactive' => array_values($inactive), 'matched' => null];
    }

    /**
     * Apakah ada klausa yang bergantung pada field konteks yang belum tersedia.
     * Dipakai simulator untuk memperingatkan admin tanpa menggagalkan simulasi.
     *
     * @return list<string>
     */
    public function referencedFields(?array $expression): array
    {
        if ($expression === null || $expression === []) {
            return [];
        }

        $fields = [];

        $walk = function (array $node) use (&$walk, &$fields): void {
            foreach (['all', 'any', 'clauses'] as $group) {
                if (array_key_exists($group, $node)) {
                    foreach ((array) $node[$group] as $child) {
                        if (is_array($child)) {
                            $walk($child);
                        }
                    }
                }
            }

            if (array_key_exists('not', $node) && is_array($node['not'])) {
                $walk($node['not']);
            }

            if (isset($node['field'])) {
                $fields[] = (string) $node['field'];

                return;
            }

            if (! array_key_exists('field', $node) && ! array_intersect(['all', 'any', 'not', 'clauses', 'logic'], array_keys($node))) {
                foreach ($node as $field => $operator) {
                    if (is_array($operator)) {
                        $fields[] = (string) $field;
                    }
                }
            }
        };

        $walk($expression);

        return array_values(array_unique($fields));
    }

    /** @param array<string, mixed> $edge */
    protected function edgeDisabled(array $edge): bool
    {
        return (bool) ($edge['disabled'] ?? false);
    }
}
