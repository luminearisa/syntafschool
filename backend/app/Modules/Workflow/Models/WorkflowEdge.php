<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu panah antar node. Kondisi pada edge adalah **cabang**: edge pertama yang
 * kondisinya benar (urutan `priority`) itulah yang ditempuh (§29, §54).
 */
class WorkflowEdge extends WorkflowModel
{
    protected $table = 'workflow_edges';

    protected $casts = [
        'condition' => 'array',
        'priority' => 'integer',
        'is_default' => 'boolean',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'version_id');
    }

    /** @return array<string, mixed> */
    public function toGraphArray(): array
    {
        return [
            'from_key' => $this->from_key,
            'to_key' => $this->to_key,
            'label' => $this->label,
            'condition_code' => $this->condition_code,
            'condition' => $this->condition ?? [],
            'priority' => (int) $this->priority,
            'is_default' => (bool) $this->is_default,
        ];
    }
}
