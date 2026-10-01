<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\ApprovalMode;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Domain\Enums\ConditionOperator;
use App\Modules\Workflow\Domain\Enums\DefinitionStatus;
use App\Modules\Workflow\Domain\Enums\EscalationAction;
use App\Modules\Workflow\Domain\Enums\NodeType;
use App\Modules\Workflow\Domain\Enums\QuorumPolicy;
use App\Modules\Workflow\Domain\Enums\VersionStatus;
use App\Modules\Workflow\Domain\Exceptions\InvalidWorkflowOperation;
use App\Modules\Workflow\Domain\Support\WorkflowGraph;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowEdge;
use App\Modules\Workflow\Models\WorkflowNode;
use App\Modules\Workflow\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;

/**
 * Builder & penerbit versi workflow (§14, §54, §55).
 *
 * Aturan yang dipegang: **tidak ada definisi yang boleh terbit dalam keadaan
 * setengah jadi**. Validator menolak graf yang tidak punya START/END, punya
 * node yatim, siklus, step tanpa resolver, kondisi tidak sah, aksi tak terdaftar,
 * cabang paralel tanpa titik temu, atau SLA/eskalasi yang tidak masuk akal.
 *
 * Versi yang sudah terbit menjadi beku: request lama tetap memakai versi saat ia
 * dibuat, sehingga memperbaiki alur tidak pernah mengubah keputusan yang sudah
 * terjadi (§14).
 */
class WorkflowBuilderService
{
    public function __construct(
        private readonly WorkflowRegistrar $registrar,
        private readonly ApproverResolverService $resolvers,
        private readonly ConditionEvaluatorService $conditions,
        private readonly WorkflowAuditService $audit,
    ) {
    }

    /**
     * Validasi lengkap sebuah versi (dipakai tombol "Periksa" dan saat publish).
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validate(WorkflowVersion $version): array
    {
        $version->loadMissing(['nodes', 'edges', 'conditions', 'actions']);

        $graph = $version->graph();
        $errors = [];
        $warnings = [];

        $nodes = $graph->nodes();
        $keys = $graph->keys();

        // --- Struktur dasar -------------------------------------------------
        $starts = array_filter($nodes, fn (array $n) => ($n['type'] ?? null) === NodeType::START->value);
        $ends = $graph->endNodes();

        if ($starts === []) {
            $errors[] = 'Graf tidak memiliki node START.';
        }

        if (count($starts) > 1) {
            $errors[] = 'Graf memiliki lebih dari satu node START.';
        }

        if ($ends === []) {
            $errors[] = 'Graf tidak memiliki node END.';
        }

        if ($nodes === []) {
            $errors[] = 'Versi workflow belum memiliki node apa pun.';
        }

        // --- Duplikasi & integritas edge ------------------------------------
        $duplicates = array_diff_assoc($keys, array_unique($keys));

        foreach (array_unique($duplicates) as $duplicate) {
            $errors[] = "Kunci node `{$duplicate}` dipakai lebih dari sekali.";
        }

        foreach ($graph->edges() as $edge) {
            if (! in_array($edge['from_key'], $keys, true) || ! in_array($edge['to_key'], $keys, true)) {
                $errors[] = sprintf(
                    'Edge %s → %s menunjuk node yang tidak ada.',
                    $edge['from_key'],
                    $edge['to_key'],
                );
            }
        }

        // --- Keterjangkauan & siklus ---------------------------------------
        foreach ($graph->unreachableNodes() as $orphan) {
            $errors[] = "Node `{$orphan}` tidak dapat dicapai dari START.";
        }

        foreach ($graph->cycles() as $cycle) {
            $errors[] = 'Terdapat siklus antar node: '.implode(' → ', $cycle);
        }

        // --- Validasi per node ---------------------------------------------
        foreach ($nodes as $node) {
            $key = (string) $node['key'];
            $type = NodeType::tryFrom((string) ($node['type'] ?? ''));

            if ($type === null) {
                $errors[] = "Node `{$key}` memiliki jenis yang tidak dikenal.";

                continue;
            }

            $incoming = $graph->incoming($key);
            $outgoing = $graph->outgoing($key);

            if ($type === NodeType::START && $incoming !== []) {
                $errors[] = 'Node START tidak boleh memiliki cabang masuk.';
            }

            if ($type !== NodeType::START && $incoming === []) {
                $errors[] = "Node `{$key}` tidak memiliki cabang masuk (node yatim).";
            }

            if ($type === NodeType::END && $outgoing !== []) {
                $errors[] = "Node END `{$key}` tidak boleh memiliki cabang keluar.";
            }

            if ($type !== NodeType::END && $outgoing === []) {
                $errors[] = "Node `{$key}` tidak memiliki cabang keluar (alur buntu).";
            }

            if ($type->waitsForHuman()) {
                $errors = array_merge($errors, $this->resolvers->validateNodeConfig($node));
                $errors = array_merge($errors, $this->validateQuorum($node, $key));
                $warnings = array_merge($warnings, $this->warnStep($node, $key, $outgoing));
            }

            if ($type === NodeType::CONDITION) {
                $errors = array_merge($errors, $this->validateConditionConfig($node, $key, $version));
            }

            if ($type === NodeType::PARALLEL) {
                $errors = array_merge($errors, $this->validateParallel($graph, $node, $key));
            }

            $errors = array_merge($errors, $this->validateSla($node, $key));
            $errors = array_merge($errors, $this->validateEscalation($node, $key));
            $errors = array_merge($errors, $this->validateActions($node, $key));
        }

        // --- Validasi edge (kondisi cabang) --------------------------------
        foreach ($graph->edges() as $edge) {
            $expression = $this->conditions->expressionFor(
                isset($edge['condition_code']) ? (string) $edge['condition_code'] : null,
                isset($edge['condition']) ? (array) $edge['condition'] : null,
                $version,
            );

            foreach ($this->conditions->evaluator()->validate($expression) as $message) {
                $errors[] = sprintf('Edge %s → %s: %s', $edge['from_key'], $edge['to_key'], $message);
            }

            if (($edge['condition_code'] ?? null) !== null
                && ! $version->conditions()->where('code', $edge['condition_code'])->exists()) {
                $errors[] = sprintf('Edge %s → %s merujuk kondisi `%s` yang tidak ada.', $edge['from_key'], $edge['to_key'], $edge['condition_code']);
            }
        }

        // --- Peringatan operasional ----------------------------------------
        foreach ($nodes as $node) {
            if (NodeType::tryFrom((string) ($node['type'] ?? '')) === NodeType::CONDITION) {
                $hasDefault = collect($graph->outgoing((string) $node['key']))->contains(fn (array $edge) => ($edge['is_default'] ?? false) || empty($edge['condition']) && empty($edge['condition_code']));

                if (! $hasDefault) {
                    $warnings[] = sprintf('Node kondisi `%s` tidak punya cabang default — request akan gagal bila tidak ada kondisi yang cocok.', $node['key']);
                }
            }
        }

        if (($version->config['allow_self_approval'] ?? false) === true) {
            $warnings[] = 'Self-approval diaktifkan pada versi ini. Pastikan kebijakan sekolah memang mengizinkannya.';
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Simpan graf draf (nodes + edges + kondisi + aksi).
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<array<string, mixed>>  $actions
     */
    public function saveGraph(
        WorkflowVersion $version,
        array $nodes,
        array $edges,
        array $conditions = [],
        array $actions = [],
        ?User $actor = null,
        ?array $config = null,
    ): WorkflowVersion {
        if (! $version->isEditable()) {
            throw InvalidWorkflowOperation::make(
                'Versi yang sudah terbit tidak dapat diubah. Buat versi baru (klon) untuk perbaikan.',
                ['status' => $version->status->value],
            );
        }

        return DB::transaction(function () use ($version, $nodes, $edges, $conditions, $actions, $actor, $config) {
            $before = ['nodes' => $version->nodes()->count(), 'edges' => $version->edges()->count()];

            $version->nodes()->delete();
            $version->edges()->delete();
            $version->conditions()->delete();
            $version->actions()->delete();

            foreach ($nodes as $node) {
                WorkflowNode::query()->create($this->normalizeNode($version, $node));
            }

            foreach ($edges as $edge) {
                WorkflowEdge::query()->create([
                    'version_id' => $version->id,
                    'from_key' => (string) $edge['from_key'],
                    'to_key' => (string) $edge['to_key'],
                    'label' => $edge['label'] ?? null,
                    'condition_code' => $edge['condition_code'] ?? null,
                    'condition' => $edge['condition'] ?? null,
                    'priority' => (int) ($edge['priority'] ?? 0),
                    'is_default' => (bool) ($edge['is_default'] ?? false),
                ]);
            }

            foreach ($conditions as $condition) {
                $version->conditions()->create([
                    'code' => (string) $condition['code'],
                    'name' => $condition['name'] ?? (string) $condition['code'],
                    'logic' => strtoupper((string) ($condition['logic'] ?? 'AND')),
                    'expression' => $condition['expression'] ?? [],
                    'description' => $condition['description'] ?? null,
                ]);
            }

            foreach ($actions as $action) {
                $version->actions()->create([
                    'key' => (string) $action['key'],
                    'name' => $action['name'] ?? null,
                    'type' => strtoupper((string) ($action['type'] ?? 'CUSTOM')),
                    'handler' => $action['handler'] ?? null,
                    'params' => $action['params'] ?? null,
                    'on_failure' => strtoupper((string) ($action['on_failure'] ?? 'LOG')),
                    'is_active' => (bool) ($action['is_active'] ?? true),
                ]);
            }

            if ($config !== null) {
                $version->forceFill(['config' => $config])->save();
            }

            $version->forceFill(['graph_hash' => null, 'compiled_graph' => null])->save();

            $this->audit->record(
                AuditAction::VERSION_UPDATED,
                null,
                $version,
                before: $before,
                after: ['nodes' => count($nodes), 'edges' => count($edges)],
                actor: $actor,
                extra: ['definition_id' => $version->definition_id, 'version_id' => $version->id],
            );

            return $version->refresh();
        });
    }

    /**
     * Terbitkan versi: validasi → kompilasi → aktifkan (§15, §55).
     */
    public function publish(WorkflowVersion $version, User $actor, ?string $changelog = null, bool $activate = true): WorkflowVersion
    {
        if (! $version->isEditable()) {
            throw InvalidWorkflowOperation::make('Versi ini sudah terbit.');
        }

        $report = $this->validate($version);

        if (! $report['valid']) {
            throw InvalidWorkflowOperation::make(
                'Versi belum dapat diterbitkan. Perbaiki kesalahan berikut lebih dulu.',
                ['errors' => $report['errors'], 'warnings' => $report['warnings']],
            );
        }

        return DB::transaction(function () use ($version, $actor, $changelog, $activate, $report) {
            $definition = $version->definition;
            $graph = $version->compile();

            $version->forceFill([
                'compiled_graph' => $graph,
                'graph_hash' => hash('sha256', json_encode($graph, JSON_UNESCAPED_UNICODE)),
                'status' => $activate ? VersionStatus::ACTIVE->value : VersionStatus::TESTING->value,
                'changelog' => $changelog ?? $version->changelog,
                'published_at' => now(),
                'published_by' => $actor->id,
            ])->save();

            if ($activate) {
                // Versi aktif sebelumnya turun menjadi INACTIVE — riwayatnya tetap
                // utuh dan tetap dipakai semua instance yang sudah berjalan.
                WorkflowVersion::query()
                    ->where('definition_id', $definition->id)
                    ->where('id', '!=', $version->id)
                    ->where('status', VersionStatus::ACTIVE->value)
                    ->update(['status' => VersionStatus::INACTIVE->value, 'archived_at' => now()]);

                $version->forceFill(['activated_at' => now()])->save();

                $definition->forceFill([
                    'current_version_id' => $version->id,
                    'status' => DefinitionStatus::ACTIVE->value,
                ])->save();

                $this->audit->record(
                    AuditAction::VERSION_ACTIVATED,
                    null,
                    $version,
                    after: [
                        'definition' => $definition->code,
                        'version' => $version->version,
                        'graph_hash' => $version->graph_hash,
                    ],
                    actor: $actor,
                    extra: ['definition_id' => $definition->id, 'version_id' => $version->id],
                );
            }

            $this->audit->record(
                AuditAction::VERSION_PUBLISHED,
                null,
                $version,
                after: ['status' => $version->status->value, 'warnings' => $report['warnings']],
                actor: $actor,
                extra: ['definition_id' => $definition->id, 'version_id' => $version->id],
            );

            return $version->refresh();
        });
    }

    /** Arsipkan versi lama (tidak lagi bisa dipakai request baru). */
    public function archive(WorkflowVersion $version, User $actor, string $reason): WorkflowVersion
    {
        if (trim($reason) === '') {
            throw InvalidWorkflowOperation::make('Mengarsipkan versi mewajibkan alasan.');
        }

        $version->forceFill([
            'status' => VersionStatus::ARCHIVED->value,
            'archived_at' => now(),
        ])->save();

        $this->audit->record(
            AuditAction::VERSION_ARCHIVED,
            null,
            $version,
            after: ['status' => VersionStatus::ARCHIVED->value],
            reason: $reason,
            actor: $actor,
            extra: ['definition_id' => $version->definition_id, 'version_id' => $version->id],
        );

        return $version;
    }

    /**
     * Buat versi draf baru. Dari versi lain (klon) atau kosong dengan kerangka
     * START → APPROVAL → END supaya admin punya titik awal.
     */
    public function createVersion(
        WorkflowDefinition $definition,
        User $actor,
        ?WorkflowVersion $cloneFrom = null,
        ?string $label = null,
        bool $seedSkeleton = true,
    ): WorkflowVersion {
        return DB::transaction(function () use ($definition, $actor, $cloneFrom, $label, $seedSkeleton) {
            $next = (int) WorkflowVersion::query()->where('definition_id', $definition->id)->max('version') + 1;

            $version = WorkflowVersion::query()->create([
                'definition_id' => $definition->id,
                'version' => $next,
                'status' => VersionStatus::DRAFT->value,
                'label' => $label ?? 'v'.$next,
                'config' => $cloneFrom?->config,
                'created_by' => $actor->id,
            ]);

            if ($cloneFrom !== null) {
                $this->cloneGraph($cloneFrom, $version);
            } elseif ($seedSkeleton) {
                $this->seedSkeleton($version);
            }

            $this->audit->record(
                AuditAction::VERSION_CREATED,
                null,
                $version,
                after: ['version' => $next, 'cloned_from' => $cloneFrom?->version],
                actor: $actor,
                extra: ['definition_id' => $definition->id, 'version_id' => $version->id],
            );

            return $version->refresh();
        });
    }

    protected function cloneGraph(WorkflowVersion $source, WorkflowVersion $target): void
    {
        foreach ($source->nodes()->get() as $node) {
            $target->nodes()->create($node->only([
                'key', 'type', 'name', 'description', 'sequence', 'approval_mode', 'quorum_policy',
                'quorum_value', 'sla_minutes', 'required', 'allow_skip', 'allow_reassign',
                'self_approval_rule', 'approvers', 'tiers', 'condition_code', 'condition',
                'escalation', 'actions', 'notification', 'config', 'position_x', 'position_y',
            ]));
        }

        foreach ($source->edges()->get() as $edge) {
            $target->edges()->create($edge->only([
                'from_key', 'to_key', 'label', 'condition_code', 'condition', 'priority', 'is_default',
            ]));
        }

        foreach ($source->conditions()->get() as $condition) {
            $target->conditions()->create($condition->only(['code', 'name', 'logic', 'expression', 'description']));
        }

        foreach ($source->actions()->get() as $action) {
            $target->actions()->create($action->only(['key', 'name', 'type', 'handler', 'params', 'on_failure', 'is_active']));
        }
    }

    protected function seedSkeleton(WorkflowVersion $version): void
    {
        $nodes = [
            ['key' => 'start', 'type' => 'START', 'name' => 'Mulai', 'sequence' => 1],
            ['key' => 'approval_1', 'type' => 'APPROVAL', 'name' => 'Persetujuan', 'sequence' => 2, 'approval_mode' => 'PARALLEL', 'quorum_policy' => 'ALL', 'approvers' => []],
            ['key' => 'end', 'type' => 'END', 'name' => 'Selesai', 'sequence' => 3],
        ];

        foreach ($nodes as $node) {
            $version->nodes()->create($node + ['required' => true]);
        }

        foreach ([['start', 'approval_1'], ['approval_1', 'end']] as $index => [$from, $to]) {
            $version->edges()->create(['from_key' => $from, 'to_key' => $to, 'priority' => $index]);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    protected function normalizeNode(WorkflowVersion $version, array $node): array
    {
        $type = strtoupper((string) ($node['type'] ?? 'APPROVAL'));

        return [
            'version_id' => $version->id,
            'key' => (string) ($node['key'] ?? 'node_'.uniqid()),
            'type' => $type,
            'name' => (string) ($node['name'] ?? NodeType::tryFrom($type)?->label() ?? $type),
            'description' => $node['description'] ?? null,
            'sequence' => (int) ($node['sequence'] ?? 0),
            'approval_mode' => isset($node['approval_mode']) ? strtoupper((string) $node['approval_mode']) : null,
            'quorum_policy' => isset($node['quorum_policy']) ? strtoupper((string) $node['quorum_policy']) : null,
            'quorum_value' => $node['quorum_value'] ?? null,
            'sla_minutes' => $node['sla_minutes'] ?? null,
            'required' => (bool) ($node['required'] ?? true),
            'allow_skip' => (bool) ($node['allow_skip'] ?? false),
            'allow_reassign' => (bool) ($node['allow_reassign'] ?? true),
            'self_approval_rule' => strtoupper((string) ($node['self_approval_rule'] ?? 'INHERIT')),
            'approvers' => $node['approvers'] ?? [],
            'tiers' => $node['tiers'] ?? null,
            'condition_code' => $node['condition_code'] ?? null,
            'condition' => $node['condition'] ?? null,
            'escalation' => $node['escalation'] ?? null,
            'actions' => $node['actions'] ?? null,
            'notification' => $node['notification'] ?? null,
            'config' => $node['config'] ?? null,
            'position_x' => $node['position_x'] ?? null,
            'position_y' => $node['position_y'] ?? null,
        ];
    }

    /** @param array<string, mixed> $node */
    protected function validateQuorum(array $node, string $key): array
    {
        $mode = strtoupper((string) ($node['approval_mode'] ?? 'PARALLEL'));

        if (ApprovalMode::tryFrom($mode) === null) {
            return ["Step `{$key}` memakai mode approval `{$mode}` yang tidak dikenal."];
        }

        $policy = strtoupper((string) ($node['quorum_policy'] ?? 'ALL'));
        $enum = QuorumPolicy::tryFrom($policy);

        if ($enum === null) {
            return ["Step `{$key}` memakai kebijakan kuorum `{$policy}` yang tidak dikenal."];
        }

        if (in_array($enum, [QuorumPolicy::MINIMUM_N, QuorumPolicy::PERCENTAGE], true) && (int) ($node['quorum_value'] ?? 0) <= 0) {
            return ["Step `{$key}` memakai kuorum {$policy} tanpa nilai yang sah."];
        }

        if ($enum === QuorumPolicy::PERCENTAGE && (int) $node['quorum_value'] > 100) {
            return ["Step `{$key}` memakai kuorum persentase di atas 100%."];
        }

        if ($mode === ApprovalMode::HYBRID->value && (array) ($node['tiers'] ?? []) === []) {
            return ["Step `{$key}` memakai mode HYBRID tetapi tidak mendefinisikan tingkat (tiers)."];
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $outgoing
     * @return list<string>
     */
    protected function warnStep(array $node, string $key, array $outgoing): array
    {
        $warnings = [];

        if (count($outgoing) > 1
            && strtoupper((string) ($node['quorum_policy'] ?? '')) === QuorumPolicy::ANY->value) {
            $warnings[] = "Step `{$key}` bercabang lebih dari satu tetapi memakai kuorum ANY.";
        }

        return $warnings;
    }

    /** @param array<string, mixed> $node */
    protected function validateConditionConfig(array $node, string $key, WorkflowVersion $version): array
    {
        $errors = [];

        if (count($version->edges()->where('from_key', $key)->get()) < 2) {
            $errors[] = "Node kondisi `{$key}` harus memiliki minimal dua cabang keluar.";
        }

        $expression = $this->conditions->expressionFor(
            isset($node['condition_code']) ? (string) $node['condition_code'] : null,
            isset($node['condition']) ? (array) $node['condition'] : null,
            $version,
        );

        foreach ($this->conditions->evaluator()->validate($expression) as $message) {
            $errors[] = "Node kondisi `{$key}`: {$message}";
        }

        return $errors;
    }

    /** @param array<string, mixed> $node */
    protected function validateParallel(WorkflowGraph $graph, array $node, string $key): array
    {
        $errors = [];
        $branches = $graph->successors($key);

        if (count($branches) < 2) {
            $errors[] = "Node paralel `{$key}` harus memiliki minimal dua cabang.";
        }

        if ($graph->convergenceAfter($key) === null) {
            $errors[] = "Cabang paralel pada `{$key}` tidak pernah bertemu kembali (merge strategy tidak jelas).";
        }

        $strategy = $node['config']['merge_strategy'] ?? 'ALL';

        if (! in_array(strtoupper((string) $strategy), ['ALL', 'ANY', 'MINIMUM_N'], true)) {
            $errors[] = "Node paralel `{$key}` memakai strategi penggabungan `{$strategy}` yang tidak dikenal.";
        }

        return $errors;
    }

    /** @param array<string, mixed> $node */
    protected function validateSla(array $node, string $key): array
    {
        $sla = $node['sla_minutes'] ?? null;

        if ($sla === null) {
            return [];
        }

        if (! is_numeric($sla) || (int) $sla <= 0) {
            return ["Step `{$key}` memiliki SLA yang tidak sah (harus menit > 0)."];
        }

        if ((int) $sla > 60 * 24 * 60) {
            return ["Step `{$key}` memiliki SLA lebih dari 60 hari — periksa kembali."];
        }

        return [];
    }

    /** @param array<string, mixed> $node */
    protected function validateEscalation(array $node, string $key): array
    {
        $escalation = (array) ($node['escalation'] ?? []);

        if ($escalation === []) {
            return [];
        }

        $errors = [];
        $action = strtoupper((string) ($escalation['action'] ?? 'NOTIFY'));

        if (EscalationAction::tryFrom($action) === null) {
            $errors[] = "Eskalasi pada `{$key}` memakai aksi `{$action}` yang tidak dikenal.";
        }

        if ((int) ($escalation['max_levels'] ?? 1) < 1) {
            $errors[] = "Eskalasi pada `{$key}` harus memiliki minimal satu tingkat.";
        }

        $specs = (array) ($escalation['target'] ?? []);

        if ($specs !== []) {
            $errors = array_merge($errors, $this->resolvers->validateNodeConfig([
                'key' => $key, 'name' => 'Eskalasi '.$key, 'approvers' => $specs,
            ]));
        }

        return $errors;
    }

    /** @param array<string, mixed> $node */
    protected function validateActions(array $node, string $key): array
    {
        $errors = [];
        $hooks = (array) ($node['actions'] ?? []);

        foreach ($hooks as $hook => $entries) {
            foreach ((array) $entries as $entry) {
                $handler = is_string($entry) ? $entry : (string) ($entry['handler'] ?? '');

                if ($handler === '') {
                    $errors[] = "Node `{$key}` memiliki aksi tanpa handler pada hook `{$hook}`.";

                    continue;
                }

                if ($this->registrar->action($handler) === null) {
                    $errors[] = "Node `{$key}` memakai aksi `{$handler}` yang belum terdaftar.";
                }
            }
        }

        return $errors;
    }

    /** Ringkasan operator & resolver untuk UI builder (§54). */
    public function meta(): array
    {
        $resolvers = [];
        foreach ($this->registrar->resolvers() as $alias => $resolver) {
            $resolvers[] = [
                'alias' => $alias,
                'description' => $resolver->description(),
                'required_spec' => $resolver->requiredSpec(),
            ];
        }

        $actions = [];
        foreach ($this->registrar->actions() as $alias => $action) {
            $actions[] = ['alias' => $alias, 'description' => $action->description()];
        }

        return [
            'node_types' => array_map(
                fn (NodeType $type) => ['value' => $type->value, 'label' => $type->label()],
                NodeType::cases(),
            ),
            'approval_modes' => array_map(
                fn (ApprovalMode $mode) => ['value' => $mode->value, 'label' => $mode->label()],
                ApprovalMode::cases(),
            ),
            'quorum_policies' => array_map(
                fn (QuorumPolicy $policy) => ['value' => $policy->value, 'label' => $policy->label()],
                QuorumPolicy::cases(),
            ),
            'condition_operators' => array_map(
                fn (ConditionOperator $operator) => ['value' => $operator->value, 'label' => $operator->label()],
                ConditionOperator::cases(),
            ),
            'escalation_actions' => array_map(
                fn (EscalationAction $action) => ['value' => $action->value, 'label' => $action->label()],
                EscalationAction::cases(),
            ),
            'resolvers' => $resolvers,
            'actions' => $actions,
            'policies' => [
                'empty_approver' => \App\Modules\Workflow\Domain\Enums\EmptyApproverPolicy::values(),
                'return_strategy' => \App\Modules\Workflow\Domain\Enums\ReturnStrategy::values(),
            ],
            'subject_types' => array_keys($this->registrar->contextProviders()),
            'calendar' => [
                'default' => config('workflow.calendar'),
                'respect_working_hours' => (bool) config('workflow.business_calendar.respect_working_hours'),
            ],
        ];
    }
}
