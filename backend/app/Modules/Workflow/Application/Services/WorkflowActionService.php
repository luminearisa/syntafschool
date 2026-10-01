<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Domain\Contracts\WorkflowActionInterface;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Domain\Support\WorkflowActionContext;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;
use App\Modules\Workflow\Models\WorkflowEvent;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowStepInstance;
use Illuminate\Support\Facades\Log;

/**
 * Pelaksana aksi otomatis (§43).
 *
 * Aksi dipicu pada hook: `submitted`, `enter` (node dimasuki), `approved`,
 * `rejected`, `returned`, `cancelled`, `completed`, `failed`, `escalated`.
 * Sumbernya dua: konfigurasi node (`actions`) dan konfigurasi versi
 * (aksi tingkat workflow, mis. "setelah APPROVED buat Purchase Order").
 *
 * Kegagalan aksi **tidak pernah disembunyikan**: hasilnya dicatat sebagai
 * `ACTION_FAILED` pada audit + `workflow_events`, dan bila kebijakan
 * `action_on_failure = FAIL`, instance ditandai gagal supaya manusia melihatnya.
 */
class WorkflowActionService
{
    public function __construct(
        private readonly WorkflowRegistrar $registrar,
        private readonly WorkflowAuditService $audit,
    ) {
    }

    /**
     * Jalankan semua aksi untuk sebuah hook.
     *
     * @return array{executed: list<array<string, mixed>>, failed: list<array<string, mixed>>}
     */
    public function run(
        string $hook,
        WorkflowInstance $instance,
        WorkflowContext $context,
        ?WorkflowStepInstance $step = null,
        ?User $actor = null,
        ?array $node = null,
    ): array {
        $definitions = $this->actionsFor($hook, $instance, $step, $node);
        $executed = [];
        $failed = [];

        foreach ($definitions as $definition) {
            $alias = (string) ($definition['handler'] ?? '');
            $params = (array) ($definition['params'] ?? []);
            $onFailure = strtoupper((string) ($definition['on_failure'] ?? 'LOG'));

            $action = $this->registrar->action($alias);

            if ($action === null) {
                $failed[] = [
                    'handler' => $alias,
                    'error' => "Aksi `{$alias}` tidak terdaftar.",
                ];

                $this->recordFailure($instance, $step, $alias, "Aksi `{$alias}` tidak terdaftar.", $actor);

                continue;
            }

            $actionContext = new WorkflowActionContext(
                instance: $instance,
                context: $context,
                hook: $hook,
                stepInstance: $step,
                actor: $actor,
                definition: $instance->definition,
                version: $instance->version,
            );

            try {
                $result = $action->execute($actionContext, $params);

                $executed[] = ['handler' => $alias, 'hook' => $hook, 'result' => $result];

                $this->audit->record(
                    AuditAction::ACTION_EXECUTED,
                    $instance,
                    $step,
                    after: ['handler' => $alias, 'hook' => $hook, 'result' => $result],
                    actor: $actor,
                    extra: ['step_instance_id' => $step?->id],
                );
            } catch (\Throwable $e) {
                $failed[] = ['handler' => $alias, 'error' => $e->getMessage()];
                $this->recordFailure($instance, $step, $alias, $e->getMessage(), $actor);

                if ($onFailure === 'FAIL') {
                    $instance->forceFill([
                        'status' => 'FAILED',
                        'failure_reason' => mb_substr("Aksi {$alias} gagal: ".$e->getMessage(), 0, 290),
                    ])->save();

                    break;
                }
            }
        }

        return ['executed' => $executed, 'failed' => $failed];
    }

    /**
     * Konfigurasi aksi yang berlaku untuk sebuah hook.
     *
     * @return list<array<string, mixed>>
     */
    public function actionsFor(string $hook, WorkflowInstance $instance, ?WorkflowStepInstance $step = null, ?array $node = null): array
    {
        $definitions = [];

        // 1. Aksi tingkat versi/definisi (mis. "setelah APPROVED → buat PO").
        $versionConfig = (array) ($instance->version?->config ?? []);
        $definitions = array_merge($definitions, $this->normalizeHook($versionConfig['actions'][$hook] ?? []));

        // 2. Aksi node yang sedang berjalan (atau node yang baru saja selesai,
        //    mis. node END yang membawa aksi bisnis "approved").
        if ($step !== null && $node === null) {
            $node = $instance->version?->graph()->node($step->node_key);
        }

        if ($node !== null) {
            $nodeActions = (array) ($node['actions'] ?? []);
            $definitions = array_merge($definitions, $this->normalizeHook($nodeActions[$hook] ?? []));
        }

        return $definitions;
    }

    /**
     * Normalisasi bentuk konfigurasi aksi:
     *   ["notification.send"]                  → alias tanpa parameter
     *   [{"handler": "x", "params": {...}}]    → bentuk lengkap
     *
     * @return list<array<string, mixed>>
     */
    protected function normalizeHook(mixed $hook): array
    {
        if (! is_array($hook) || $hook === []) {
            return [];
        }

        $definitions = [];

        foreach ($hook as $entry) {
            if (is_string($entry)) {
                $definitions[] = ['handler' => $entry, 'params' => []];

                continue;
            }

            if (is_array($entry) && isset($entry['handler'])) {
                $definitions[] = $entry;
            }
        }

        return $definitions;
    }

    protected function recordFailure(WorkflowInstance $instance, ?WorkflowStepInstance $step, string $alias, string $error, ?User $actor): void
    {
        Log::error('workflow.action.failed', [
            'instance' => $instance->code,
            'handler' => $alias,
            'error' => $error,
        ]);

        WorkflowEvent::query()->create([
            'instance_id' => $instance->id,
            'definition_id' => $instance->definition_id,
            'step_instance_id' => $step?->id,
            'type' => 'action.failed',
            'actor_id' => $actor?->id,
            'payload' => ['handler' => $alias, 'error' => $error],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $this->audit->record(
            AuditAction::ACTION_FAILED,
            $instance,
            $step,
            after: ['handler' => $alias, 'error' => $error],
            actor: $actor,
            extra: ['step_instance_id' => $step?->id],
        );
    }

    /** Daftar alias aksi yang terdaftar — untuk builder & meta API. */
    public function registeredAliases(): array
    {
        $rows = [];

        foreach ($this->registrar->actions() as $alias => $action) {
            $rows[] = [
                'alias' => $alias,
                'description' => $action->description(),
                'class' => $action::class,
            ];
        }

        return $rows;
    }
}
