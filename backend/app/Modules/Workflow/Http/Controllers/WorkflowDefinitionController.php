<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Workflow\Application\Services\WorkflowBuilderService;
use App\Modules\Workflow\Application\Services\WorkflowDefinitionService;
use App\Modules\Workflow\Application\Services\WorkflowSimulationService;
use App\Modules\Workflow\Application\Services\WorkflowVersionService;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Konfigurasi workflow: definisi, versi, builder, publikasi, simulasi (§13–§15, §54–§56).
 *
 * Semua endpoint di sini membutuhkan izin `workflow.definition.*` /
 * `workflow.version.*`. Workflow Admin **tidak** memperoleh kewenangan
 * menyetujui request bisnis dari izin ini (§10) — keputusan bisnis tetap lewat
 * `workflow.approval.decide` yang terpisah.
 */
class WorkflowDefinitionController extends BaseApiController
{
    public function __construct(
        private readonly WorkflowDefinitionService $definitions,
        private readonly WorkflowBuilderService $builder,
        private readonly WorkflowVersionService $versions,
        private readonly WorkflowSimulationService $simulation,
    ) {
    }

    /** Daftar definisi + versi aktif. */
    public function index(Request $request): JsonResponse
    {
        $query = WorkflowDefinition::query()->with(['currentVersion:id,definition_id,version,status', 'owner:id,name']);

        if ($module = $request->string('module')->toString()) {
            $query->where('module', $module);
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', strtoupper($status));
        }

        if ($search = $request->string('search')->toString()) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(code) LIKE ?', [$term])->orWhereRaw('LOWER(name) LIKE ?', [$term]));
        }

        $definitions = $query->orderBy('module')->orderBy('name')->paginate(min(100, (int) $request->integer('per_page', 20)));

        return $this->successResponse($definitions->through(fn (WorkflowDefinition $definition) => $this->describe($definition)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'module' => ['nullable', 'string', 'max:60'],
            'event' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'trigger_type' => ['nullable', 'string', 'max:20'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'owner_department' => ['nullable', 'string', 'max:80'],
            'allow_self_approval' => ['nullable', 'boolean'],
            'comment_required_on' => ['nullable', 'array'],
            'required_context' => ['nullable', 'array'],
            'observers' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'seed_skeleton' => ['nullable', 'boolean'],
        ]);

        $definition = $this->definitions->create($data, $request->user());

        if ($request->boolean('seed_skeleton', true)) {
            $this->builder->createVersion($definition, $request->user(), null, 'v1', true);
        }

        return $this->createdResponse($this->describe($definition->refresh()), 'Definisi workflow dibuat.');
    }

    public function show(WorkflowDefinition $definition): JsonResponse
    {
        $definition->load(['versions' => fn ($q) => $q->orderByDesc('version'), 'owner:id,name']);

        return $this->successResponse($this->describe($definition) + [
            'versions' => $definition->versions->map(fn (WorkflowVersion $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'status' => $version->status->value,
                'label' => $version->label,
                'changelog' => $version->changelog,
                'graph_hash' => $version->graph_hash,
                'published_at' => $version->published_at?->toIso8601String(),
                'activated_at' => $version->activated_at?->toIso8601String(),
                'archived_at' => $version->archived_at?->toIso8601String(),
                'config' => $version->config,
                'instances_count' => $version->instances()->count(),
            ])->all(),
        ]);
    }

    public function update(Request $request, WorkflowDefinition $definition): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'module' => ['sometimes', 'string', 'max:60'],
            'event' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'owner_department' => ['nullable', 'string', 'max:80'],
            'allow_self_approval' => ['sometimes', 'boolean'],
            'comment_required_on' => ['nullable', 'array'],
            'required_context' => ['nullable', 'array'],
            'observers' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
        ]);

        return $this->successResponse($this->describe($this->definitions->update($definition, $data, $request->user())), 'Definisi diperbarui.');
    }

    public function deactivate(Request $request, WorkflowDefinition $definition): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        $definition = $this->definitions->deactivate($definition, $request->user(), $data['reason']);

        return $this->successResponse($this->describe($definition), 'Definisi dinonaktifkan. Request yang berjalan tetap memakai versinya.');
    }

    /** Daftar versi sebuah definisi. */
    public function versions(WorkflowDefinition $definition): JsonResponse
    {
        $rows = $definition->versions()->orderByDesc('version')->get()->map(fn (WorkflowVersion $version) => [
            'id' => $version->id,
            'version' => $version->version,
            'status' => $version->status->value,
            'label' => $version->label,
            'changelog' => $version->changelog,
            'published_at' => $version->published_at?->toIso8601String(),
            'activated_at' => $version->activated_at?->toIso8601String(),
            'graph_hash' => $version->graph_hash,
            'nodes_count' => $version->nodes()->count(),
            'instances_count' => $version->instances()->count(),
        ]);

        return $this->successResponse($rows);
    }

    /** Buat versi baru (klon bila `from_version_id` dikirim). */
    public function storeVersion(Request $request, WorkflowDefinition $definition): JsonResponse
    {
        $data = $request->validate([
            'from_version_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:150'],
            'seed_skeleton' => ['nullable', 'boolean'],
        ]);

        $cloneFrom = isset($data['from_version_id'])
            ? WorkflowVersion::query()->where('definition_id', $definition->id)->findOrFail($data['from_version_id'])
            : null;

        $version = $this->versions->createVersion(
            $definition,
            $request->user(),
            $cloneFrom,
            $data['label'] ?? null,
            (bool) ($data['seed_skeleton'] ?? true),
        );

        return $this->createdResponse($this->versionDetail($version), 'Versi baru dibuat.');
    }

    public function showVersion(WorkflowVersion $version): JsonResponse
    {
        return $this->successResponse($this->versionDetail($version));
    }

    /** Simpan graf draf. */
    public function saveVersion(Request $request, WorkflowVersion $version): JsonResponse
    {
        $data = $request->validate([
            'nodes' => ['required', 'array', 'min:1'],
            'edges' => ['present', 'array'],
            'conditions' => ['nullable', 'array'],
            'actions' => ['nullable', 'array'],
            'config' => ['nullable', 'array'],
            'label' => ['nullable', 'string', 'max:150'],
        ]);

        $version = $this->versions->saveGraph(
            $version,
            $data['nodes'],
            $data['edges'] ?? [],
            $data['conditions'] ?? [],
            $data['actions'] ?? [],
            $request->user(),
            $data['config'] ?? null,
        );

        if (isset($data['label'])) {
            $version->forceFill(['label' => $data['label']])->save();
        }

        return $this->successResponse($this->versionDetail($version), 'Graf workflow disimpan.');
    }

    /** Periksa kesiapan versi (§55). */
    public function validateVersion(WorkflowVersion $version): JsonResponse
    {
        return $this->successResponse($this->versions->validate($version), 'Hasil pemeriksaan workflow.');
    }

    /** Terbitkan versi (§15). */
    public function publishVersion(Request $request, WorkflowVersion $version): JsonResponse
    {
        $data = $request->validate([
            'changelog' => ['nullable', 'string', 'max:1000'],
            'activate' => ['nullable', 'boolean'],
        ]);

        $published = $this->versions->publish(
            $version,
            $request->user(),
            $data['changelog'] ?? null,
            (bool) ($data['activate'] ?? true),
        );

        return $this->successResponse($this->versionDetail($published), 'Versi workflow diterbitkan.');
    }

    public function archiveVersion(Request $request, WorkflowVersion $version): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        $archived = $this->versions->archive($version, $request->user(), $data['reason']);

        return $this->successResponse($this->versionDetail($archived), 'Versi diarsipkan.');
    }

    /** Simulasi tanpa membuat request nyata (§56). */
    public function simulate(Request $request, WorkflowDefinition $definition): JsonResponse
    {
        $data = $request->validate([
            'context' => ['nullable', 'array'],
            'version_id' => ['nullable', 'integer'],
        ]);

        $version = isset($data['version_id'])
            ? WorkflowVersion::query()->where('definition_id', $definition->id)->findOrFail($data['version_id'])
            : $definition->startableVersion();

        $result = $version === null
            ? $this->simulation->simulate($definition, $data['context'] ?? [], $request->user())
            : $this->simulation->simulate($version, $data['context'] ?? [], $request->user());

        return $this->successResponse($result, 'Hasil simulasi workflow.');
    }

    public function simulateVersion(Request $request, WorkflowVersion $version): JsonResponse
    {
        $data = $request->validate(['context' => ['nullable', 'array']]);

        return $this->successResponse(
            $this->simulation->simulate($version, $data['context'] ?? [], $request->user()),
            'Hasil simulasi workflow.',
        );
    }

    /** Meta untuk builder: jenis node, mode, kuorum, operator, resolver, aksi. */
    public function meta(): JsonResponse
    {
        return $this->successResponse($this->builder->meta(), 'Meta builder workflow.');
    }

    /** @return array<string, mixed> */
    protected function describe(WorkflowDefinition $definition): array
    {
        return [
            'id' => $definition->id,
            'code' => $definition->code,
            'name' => $definition->name,
            'description' => $definition->description,
            'module' => $definition->module,
            'event' => $definition->event,
            'entity_type' => $definition->entity_type,
            'category' => $definition->category,
            'status' => $definition->status->value,
            'status_label' => $definition->status->label(),
            'trigger_type' => $definition->trigger_type->value,
            'owner' => $definition->owner?->name,
            'owner_user_id' => $definition->owner_user_id,
            'owner_department' => $definition->owner_department,
            'allow_self_approval' => (bool) $definition->allow_self_approval,
            'required_context' => $definition->required_context ?? [],
            'comment_required_on' => $definition->comment_required_on ?? [],
            'observers' => $definition->observers ?? [],
            'settings' => $definition->settings ?? [],
            'current_version' => $definition->currentVersion === null ? null : [
                'id' => $definition->currentVersion->id,
                'version' => $definition->currentVersion->version,
                'status' => $definition->currentVersion->status->value,
            ],
            'instances_count' => $definition->instances()->count(),
            'instances_open' => $definition->instances()->open()->count(),
            'created_at' => $definition->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    protected function versionDetail(WorkflowVersion $version): array
    {
        $version->loadMissing(['nodes', 'edges', 'conditions', 'actions', 'definition:id,code,name,module']);

        $graph = $version->graph();

        return [
            'id' => $version->id,
            'definition_id' => $version->definition_id,
            'definition' => $version->definition?->code,
            'definition_name' => $version->definition?->name,
            'module' => $version->definition?->module,
            'version' => $version->version,
            'label' => $version->label,
            'status' => $version->status->value,
            'changelog' => $version->changelog,
            'config' => $version->config,
            'graph_hash' => $version->graph_hash,
            'editable' => $version->isEditable(),
            'published_at' => $version->published_at?->toIso8601String(),
            'activated_at' => $version->activated_at?->toIso8601String(),
            'nodes' => $graph->nodes(),
            'edges' => $graph->edges(),
            'conditions' => $version->conditions->map(fn ($condition) => [
                'code' => $condition->code,
                'name' => $condition->name,
                'logic' => $condition->logic,
                'expression' => $condition->expression,
                'description' => $condition->description,
            ])->all(),
            'actions' => $version->actions->map(fn ($action) => [
                'key' => $action->key,
                'name' => $action->name,
                'type' => $action->type,
                'handler' => $action->handler,
                'params' => $action->params,
                'on_failure' => $action->on_failure,
                'is_active' => (bool) $action->is_active,
            ])->all(),
            'instances_count' => $version->instances()->count(),
        ];
    }
}
