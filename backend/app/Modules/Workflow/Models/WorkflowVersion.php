<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Workflow\Domain\Enums\VersionStatus;
use App\Modules\Workflow\Domain\Support\WorkflowGraph;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu versi rancangan workflow (§14).
 *
 * Versi adalah unit **imutabilitas**: begitu statusnya bukan DRAFT/TESTING,
 * grafnya tidak dapat diubah lagi. Request yang sedang berjalan tetap memakai
 * versi saat ia dibuat — menerbitkan versi baru tidak memindahkan mereka.
 *
 * @property VersionStatus $status
 */
class WorkflowVersion extends WorkflowModel
{
    protected $table = 'workflow_versions';

    protected $casts = [
        'status' => VersionStatus::class,
        'config' => 'array',
        'compiled_graph' => 'array',
        'published_at' => 'datetime',
        'activated_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'definition_id');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(WorkflowNode::class, 'version_id')->orderBy('sequence')->orderBy('id');
    }

    public function edges(): HasMany
    {
        return $this->hasMany(WorkflowEdge::class, 'version_id')->orderBy('priority')->orderBy('id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(WorkflowCondition::class, 'version_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowAction::class, 'version_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'version_id');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Graf versi ini: dari `compiled_graph` bila sudah diterbitkan (beku),
     * atau dari tabel node/edge selama masih draf.
     */
    public function graph(): WorkflowGraph
    {
        if (is_array($this->compiled_graph) && $this->compiled_graph !== []) {
            return WorkflowGraph::make(
                (array) ($this->compiled_graph['nodes'] ?? []),
                (array) ($this->compiled_graph['edges'] ?? []),
            );
        }

        $nodes = $this->relationLoaded('nodes') ? $this->nodes : $this->nodes()->get();
        $edges = $this->relationLoaded('edges') ? $this->edges : $this->edges()->get();

        return WorkflowGraph::make(
            $nodes->map(fn (WorkflowNode $node) => $node->toGraphArray())->all(),
            $edges->map(fn (WorkflowEdge $edge) => $edge->toGraphArray())->all(),
        );
    }

    /** @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} */
    public function compile(): array
    {
        $nodes = $this->nodes()->get()->map(fn (WorkflowNode $node) => $node->toGraphArray())->all();
        $edges = $this->edges()->get()->map(fn (WorkflowEdge $edge) => $edge->toGraphArray())->all();

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
