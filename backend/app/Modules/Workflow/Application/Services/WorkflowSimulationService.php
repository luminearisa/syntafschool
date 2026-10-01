<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Application\Support\WorkflowPolicy;
use App\Modules\Workflow\Domain\Contracts\OrganizationalDirectoryInterface;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Domain\Enums\NodeType;
use App\Modules\Workflow\Domain\Exceptions\InvalidWorkflowOperation;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use App\Modules\Workflow\Domain\Support\WorkflowGraph;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowVersion;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;

/**
 * Simulasi & dry run (**tanpa** membuat instance nyata) — §56, §57.
 *
 * Simulasi menjawab pertanyaan yang paling sering diajukan sebelum menerbitkan
 * definisi: *"kalau nominalnya 15 juta dan unitnya Finance, siapa saja yang akan
 * dimintai persetujuan, dan berapa lama SLA-nya?"*
 *
 * Yang dikerjakan: menelusuri graf dengan konteks yang diberikan, menyelesaikan
 * approver lewat resolver **yang sesungguhnya** (termasuk delegasi aktif),
 * menempuh cabang kondisi, lalu mengembalikan jalur yang akan dilalui beserta
 * peringatan. Tidak ada baris `workflow_instances`, tidak ada notifikasi, tidak
 * ada keputusan yang tercatat.
 */
class WorkflowSimulationService
{
    public function __construct(
        private readonly WorkflowBuilderService $builder,
        private readonly ConditionEvaluatorService $conditions,
        private readonly ApproverResolverService $resolvers,
        private readonly WorkflowRegistrar $registrar,
        private readonly SlaService $sla,
        private readonly OrganizationalDirectoryInterface $directory,
        private readonly WorkflowAuditService $audit,
    ) {
    }

    /**
     * Simulasikan sebuah definisi/versi.
     *
     * @param  array<string, mixed>  $context  konteks uji, mis. {"amount": 15000000, "department": "FINANCE"}
     * @return array<string, mixed>
     */
    public function simulate(WorkflowDefinition|WorkflowVersion $subject, array $context = [], ?User $actor = null): array
    {
        $version = $subject instanceof WorkflowVersion ? $subject : $subject->startableVersion();

        if ($version === null) {
            throw InvalidWorkflowOperation::make('Definisi ini belum memiliki versi yang dapat disimulasikan.');
        }

        $definition = $version->definition ?? ($subject instanceof WorkflowDefinition ? $subject : null);
        $graph = $version->graph();
        $policy = WorkflowPolicy::resolve($definition, $version);

        if ($graph->nodes() === []) {
            throw InvalidWorkflowOperation::make('Graf workflow masih kosong — tidak ada yang dapat disimulasikan.');
        }

        $requesterId = (int) ($context['requester_id'] ?? $actor?->id ?? 0);

        $workflowContext = WorkflowContext::fromArray($context, [
            'subject_type' => (string) ($context['subject_type'] ?? $definition?->entity_type ?? 'simulation'),
            'subject_id' => (string) ($context['subject_id'] ?? 'simulation'),
            'requester_id' => $requesterId,
            'definition_code' => (string) $definition?->code,
            'module' => (string) $definition?->module,
        ]);

        $state = ['completed' => [], 'skipped' => [], 'inactive_edges' => []];
        $path = [];
        $warnings = [];
        $referencedFields = [];

        $hops = 0;

        while ($hops < $policy->maxExecutionHops) {
            $hops++;
            $progressed = false;

            $this->prune($graph, $state);

            foreach ($graph->nodes() as $node) {
                $key = (string) $node['key'];

                if (in_array($key, $state['completed'], true) || in_array($key, $state['skipped'], true)) {
                    continue;
                }

                if (! $this->isReady($graph, $state, $key)) {
                    continue;
                }

                $type = NodeType::tryFrom((string) $node['type']) ?? NodeType::AUTOMATIC;
                $entry = [
                    'node_key' => $key,
                    'name' => $node['name'] ?? $key,
                    'type' => $type->value,
                    'sequence' => (int) ($node['sequence'] ?? 0),
                    'sla_minutes' => $node['sla_minutes'] ?? null,
                    'sla_human' => $this->sla->humanize($node['sla_minutes'] ?? null),
                    'approvers' => [],
                    'condition_matched' => null,
                    'warnings' => [],
                ];

                if ($type->waitsForHuman()) {
                    $resolution = $this->resolvers->resolveForNode($node, $workflowContext, $policy->allowSelfApproval);

                    $entry['approvers'] = array_map(fn ($approver) => [
                        'user_id' => $approver->userId,
                        'name' => User::query()->whereKey($approver->userId)->value('name'),
                        'source' => $approver->source->value,
                        'resolver' => $approver->resolver,
                        'is_delegate' => $approver->isDelegate,
                        'original_user_id' => $approver->originalUserId,
                    ], $resolution->approvers);

                    $entry['approval_mode'] = $node['approval_mode'] ?? 'PARALLEL';
                    $entry['quorum'] = [
                        'policy' => $node['quorum_policy'] ?? 'ALL',
                        'value' => $node['quorum_value'] ?? null,
                        'required' => $this->requiredApprovals($node, $resolution->count()),
                    ];

                    if ($resolution->isEmpty()) {
                        $entry['warnings'][] = 'Tidak ada approver yang memenuhi syarat untuk konteks ini.';
                        $warnings[] = sprintf('Step "%s": tidak ada approver untuk konteks simulasi ini.', $entry['name']);
                    }

                    foreach ($resolution->warnings as $message) {
                        $entry['warnings'][] = $message;
                    }

                    $entry['dry_run_ok'] = ! $resolution->isEmpty();
                    $state['completed'][] = $key;
                } elseif ($type === NodeType::CONDITION) {
                    $branch = $this->conditions->resolveBranch($graph, $key, $workflowContext, $version);

                    if ($branch['target'] === null) {
                        $entry['warnings'][] = 'Tidak ada cabang kondisi yang cocok — request akan gagal. Tambahkan cabang default.';
                        $warnings[] = sprintf('Node kondisi "%s" tidak punya cabang yang cocok untuk konteks ini.', $entry['name']);
                    }

                    $entry['condition_matched'] = $branch['matched'];
                    $entry['condition_target'] = $branch['target'];

                    foreach ($branch['inactive'] as $edge) {
                        $state['inactive_edges'][] = $edge['from_key'].'|'.$edge['to_key'];
                    }

                    foreach ($graph->outgoing($key) as $edge) {
                        $expression = $this->conditions->expressionFor(
                            isset($edge['condition_code']) ? (string) $edge['condition_code'] : null,
                            isset($edge['condition']) ? (array) $edge['condition'] : null,
                            $version,
                        );

                        $referencedFields = array_merge($referencedFields, $this->conditions->referencedFields($expression));
                    }

                    $state['completed'][] = $key;
                } else {
                    $state['completed'][] = $key;
                }

                $path[] = $entry;
                $progressed = true;
            }

            if (! $progressed) {
                break;
            }
        }

        foreach ($graph->nodes() as $node) {
            $key = (string) $node['key'];

            if (in_array($key, $state['completed'], true)) {
                continue;
            }

            $path[] = [
                'node_key' => $key,
                'name' => $node['name'] ?? $key,
                'type' => $node['type'],
                'sequence' => (int) ($node['sequence'] ?? 0),
                'sla_minutes' => $node['sla_minutes'] ?? null,
                'sla_human' => $this->sla->humanize($node['sla_minutes'] ?? null),
                'approvers' => [],
                'skipped' => true,
                'skipped_reason' => in_array($key, $state['skipped'], true)
                    ? 'Tidak ditempuh pada konteks ini.'
                    : 'Tidak terjangkau dari START pada konteks ini.',
                'warnings' => [],
            ];
        }

        $missingContextFields = array_values(array_diff(
            array_values(array_unique($referencedFields)),
            array_keys($context),
        ));

        if ($missingContextFields !== []) {
            $warnings[] = 'Konteks simulasi belum memuat field yang dipakai kondisi: '.implode(', ', $missingContextFields).'.';
        }

        $validation = $this->builder->validate($version);

        $this->audit->record(
            AuditAction::VERSION_SIMULATED,
            null,
            $version,
            after: [
                'context' => $context,
                'steps' => count($path),
                'warnings' => count($warnings) + count($validation['warnings']),
            ],
            actor: $actor,
            extra: ['definition_id' => $version->definition_id, 'version_id' => $version->id],
        );

        usort($path, fn (array $a, array $b) => $a['sequence'] <=> $b['sequence']);

        return [
            'definition' => $definition?->code,
            'version' => $version->version,
            'version_status' => $version->status->value,
            'context' => $context,
            'context_hash' => $workflowContext->hash(),
            'path' => $path,
            'warnings' => array_values(array_unique(array_merge($warnings, $validation['warnings']))),
            'errors' => $validation['errors'],
            'valid' => $validation['valid'],
            'estimated_steps' => count(array_filter($path, fn (array $entry) => in_array($entry['type'], ['APPROVAL', 'REVIEW'], true) && ! ($entry['skipped'] ?? false))),
            'estimated_work_minutes' => array_sum(array_map(
                fn (array $entry) => (int) ($entry['sla_minutes'] ?? 0),
                array_filter($path, fn (array $entry) => ! ($entry['skipped'] ?? false)),
            )),
            'requester_id' => $requesterId,
            'requester_department' => $this->directory->departmentOf($requesterId ?: null),
        ];
    }

    /**
     * Dry run untuk request yang sudah berjalan (§57).
     *
     * Menjawab: apakah approver step yang sedang aktif masih dapat ditemukan,
     * apakah aksinya terdaftar, dan apakah request berpotensi tersangkut.
     *
     * @return array<string, mixed>
     */
    public function dryRun(WorkflowInstance $instance): array
    {
        $instance->loadMissing(['definition', 'version']);
        $version = $instance->version;
        $graph = $version?->graph();
        $policy = WorkflowPolicy::resolve($instance->definition, $version);
        $context = app(WorkflowContextService::class)->forInstance($instance);

        $checks = [];
        $blockers = [];

        foreach ($instance->activeSteps() as $step) {
            $node = $graph?->node((string) $step->node_key) ?? [];
            $resolution = $this->resolvers->resolveForNode($node, $context, $policy->allowSelfApproval);

            $assigned = $step->approvals()->where('status', 'PENDING')->count();

            $checks[] = [
                'node_key' => $step->node_key,
                'name' => $step->name,
                'status' => $step->status->value,
                'assigned_approvals' => $assigned,
                'resolvable_approvers' => $resolution->count(),
                'warnings' => $resolution->warnings,
                'due_at' => $step->due_at?->toIso8601String(),
                'sla_status' => $step->sla_status?->value,
            ];

            if ($assigned === 0) {
                // Step tanpa penugasan selalu pekerjaan untuk manusia: entah
                // approver belum ada, atau sudah ada tetapi belum ditetapkan.
                $blockers[] = $resolution->isEmpty()
                    ? sprintf('Step "%s" tidak memiliki approver aktif.', $step->name)
                    : sprintf('Step "%s" menunggu penetapan approver (%d kandidat tersedia).', $step->name, $resolution->count());
            }
        }

        // Aksi yang dirujuk versi & node harus terdaftar.
        $actionErrors = [];
        foreach ($graph?->nodes() ?? [] as $node) {
            foreach ((array) ($node['actions'] ?? []) as $hook => $entries) {
                foreach ((array) $entries as $entry) {
                    $handler = is_string($entry) ? $entry : (string) ($entry['handler'] ?? '');

                    if ($handler !== '' && $this->registrar->action($handler) === null) {
                        $actionErrors[] = "Aksi `{$handler}` (hook {$hook}, node {$node['key']}) tidak terdaftar.";
                    }
                }
            }
        }

        $blockers = array_merge($blockers, $actionErrors);

        return [
            'instance' => $instance->code,
            'status' => $instance->status->value,
            'context_hash' => $context->hash(),
            'active_steps' => $checks,
            'action_errors' => $actionErrors,
            'blockers' => $blockers,
            'healthy' => $blockers === [],
            'graph_hash' => $version?->graph_hash,
        ];
    }

    /** @param array<string, mixed> $node */
    protected function requiredApprovals(array $node, int $total): int
    {
        $policy = \App\Modules\Workflow\Domain\Enums\QuorumPolicy::tryFrom((string) ($node['quorum_policy'] ?? 'ALL'))
            ?? \App\Modules\Workflow\Domain\Enums\QuorumPolicy::ALL;

        return $policy->requiredApprovals($total, (int) ($node['quorum_value'] ?? 0));
    }

    /** @param array<string, mixed> $state */
    protected function isReady(WorkflowGraph $graph, array $state, string $key): bool
    {
        $incoming = array_values(array_filter(
            $graph->incoming($key),
            fn (array $edge) => ! in_array($edge['from_key'].'|'.$edge['to_key'], $state['inactive_edges'], true),
        ));

        $startKey = (string) ($graph->startNode()['key'] ?? '');

        if ($incoming === []) {
            return $key === $startKey;
        }

        // Aturan kesiapan identik dengan executor: seluruh cabang hidup harus
        // sudah berakhir (selesai atau dilewati).
        foreach (array_unique(array_map(fn (array $edge) => (string) $edge['from_key'], $incoming)) as $source) {
            if (! in_array($source, $state['completed'], true) && ! in_array($source, $state['skipped'], true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $state */
    protected function prune(WorkflowGraph $graph, array &$state): void
    {
        $changed = true;

        while ($changed) {
            $changed = false;

            foreach ($graph->nodes() as $node) {
                $key = (string) $node['key'];

                if (in_array($key, $state['completed'], true) || in_array($key, $state['skipped'], true)) {
                    continue;
                }

                $incoming = $graph->incoming($key);

                if ($incoming === []) {
                    continue;
                }

                $active = array_filter(
                    $incoming,
                    fn (array $edge) => ! in_array($edge['from_key'].'|'.$edge['to_key'], $state['inactive_edges'], true),
                );

                if ($active === []) {
                    $state['skipped'][] = $key;
                    $changed = true;
                }
            }
        }
    }
}
