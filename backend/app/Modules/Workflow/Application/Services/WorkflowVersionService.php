<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowVersion;

/**
 * Versi workflow (§14, §15).
 *
 * Tipis di atas `WorkflowBuilderService` karena sebagian besar aturan versi
 * memang aturan graf (validasi & kompilasi). Yang ditambahkan di sini adalah
 * operasi tingkat-versi yang dipanggil layar admin: daftar versi, perbandingan
 * dua versi, dan penonaktifan versi aktif tanpa mengarsipkannya.
 */
class WorkflowVersionService
{
    public function __construct(
        private readonly WorkflowBuilderService $builder,
        private readonly WorkflowAuditService $audit,
    ) {
    }

    public function createVersion(
        WorkflowDefinition $definition,
        User $actor,
        ?WorkflowVersion $cloneFrom = null,
        ?string $label = null,
        bool $seedSkeleton = true,
    ): WorkflowVersion {
        return $this->builder->createVersion($definition, $actor, $cloneFrom, $label, $seedSkeleton);
    }

    /** @param list<array<string, mixed>> $nodes */
    public function saveGraph(
        WorkflowVersion $version,
        array $nodes,
        array $edges,
        array $conditions = [],
        array $actions = [],
        ?User $actor = null,
        ?array $config = null,
    ): WorkflowVersion {
        return $this->builder->saveGraph($version, $nodes, $edges, $conditions, $actions, $actor, $config);
    }

    /** @return array{valid: bool, errors: list<string>, warnings: list<string>} */
    public function validate(WorkflowVersion $version): array
    {
        $report = $this->builder->validate($version);

        $this->audit->record(
            AuditAction::VERSION_VALIDATED,
            null,
            $version,
            after: ['valid' => $report['valid'], 'errors' => count($report['errors']), 'warnings' => count($report['warnings'])],
            extra: ['definition_id' => $version->definition_id, 'version_id' => $version->id],
        );

        return $report;
    }

    public function publish(WorkflowVersion $version, User $actor, ?string $changelog = null, bool $activate = true): WorkflowVersion
    {
        return $this->builder->publish($version, $actor, $changelog, $activate);
    }

    public function archive(WorkflowVersion $version, User $actor, string $reason): WorkflowVersion
    {
        return $this->builder->archive($version, $actor, $reason);
    }

    /**
     * Bandingkan dua versi: node/edge mana yang bertambah, hilang, atau berubah.
     *
     * Berguna sebelum menerbitkan perbaikan alur: admin melihat dampaknya pada
     * request yang akan datang, tanpa harus membaca JSON.
     *
     * @return array<string, mixed>
     */
    public function compare(WorkflowVersion $left, WorkflowVersion $right): array
    {
        $leftGraph = $left->graph();
        $rightGraph = $right->graph();

        $leftNodes = collect($leftGraph->nodes())->keyBy('key');
        $rightNodes = collect($rightGraph->nodes())->keyBy('key');

        $added = $rightNodes->keys()->diff($leftNodes->keys())->values()->all();
        $removed = $leftNodes->keys()->diff($rightNodes->keys())->values()->all();

        $changed = [];

        foreach ($rightNodes as $key => $node) {
            $before = $leftNodes->get($key);

            if ($before === null) {
                continue;
            }

            if ($this->nodeSignature($before) !== $this->nodeSignature($node)) {
                $changed[] = $key;
            }
        }

        return [
            'left' => ['version' => $left->version, 'status' => $left->status->value, 'graph_hash' => $left->graph_hash],
            'right' => ['version' => $right->version, 'status' => $right->status->value, 'graph_hash' => $right->graph_hash],
            'nodes_added' => $added,
            'nodes_removed' => $removed,
            'nodes_changed' => $changed,
            'edges_changed' => count($leftGraph->edges()) !== count($rightGraph->edges()),
            'sla_changes' => $this->slaDiff($leftNodes->all(), $rightNodes->all()),
            'approver_changes' => $this->approverDiff($leftNodes->all(), $rightNodes->all()),
        ];
    }

    protected function nodeSignature(array $node): string
    {
        return hash('sha256', json_encode([
            $node['type'] ?? null,
            $node['approval_mode'] ?? null,
            $node['quorum_policy'] ?? null,
            $node['quorum_value'] ?? null,
            $node['sla_minutes'] ?? null,
            $node['approvers'] ?? [],
            $node['tiers'] ?? [],
            $node['condition'] ?? null,
            $node['condition_code'] ?? null,
            $node['actions'] ?? [],
            $node['escalation'] ?? [],
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, array<string, mixed>>  $left
     * @param  array<string, array<string, mixed>>  $right
     * @return list<array<string, mixed>>
     */
    protected function slaDiff(array $left, array $right): array
    {
        $rows = [];

        foreach ($right as $key => $node) {
            $before = $left[$key]['sla_minutes'] ?? null;
            $after = $node['sla_minutes'] ?? null;

            if ($before !== $after) {
                $rows[] = ['node_key' => $key, 'before' => $before, 'after' => $after];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $left
     * @param  array<string, array<string, mixed>>  $right
     * @return list<array<string, mixed>>
     */
    protected function approverDiff(array $left, array $right): array
    {
        $rows = [];

        foreach ($right as $key => $node) {
            $before = json_encode($left[$key]['approvers'] ?? []);
            $after = json_encode($node['approvers'] ?? []);

            if ($before !== $after) {
                $rows[] = ['node_key' => $key, 'before' => $left[$key]['approvers'] ?? [], 'after' => $node['approvers'] ?? []];
            }
        }

        return $rows;
    }
}
