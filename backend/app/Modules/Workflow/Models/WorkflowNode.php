<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Workflow\Domain\Enums\ApprovalMode;
use App\Modules\Workflow\Domain\Enums\NodeType;
use App\Modules\Workflow\Domain\Enums\QuorumPolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu node pada graf workflow (§18, §54).
 *
 * `toGraphArray()` adalah jembatan ke `WorkflowGraph`: apapun yang dipakai
 * executor/simulator/validator berasal dari bentuk array yang sama, sehingga
 * ketiganya tidak mungkin berbeda tafsir.
 *
 * @property NodeType $type
 * @property ApprovalMode|null $approval_mode
 * @property QuorumPolicy|null $quorum_policy
 */
class WorkflowNode extends WorkflowModel
{
    protected $table = 'workflow_nodes';

    protected $casts = [
        'type' => NodeType::class,
        'approval_mode' => ApprovalMode::class,
        'quorum_policy' => QuorumPolicy::class,
        'required' => 'boolean',
        'allow_skip' => 'boolean',
        'allow_reassign' => 'boolean',
        'approvers' => 'array',
        'tiers' => 'array',
        'condition' => 'array',
        'escalation' => 'array',
        'actions' => 'array',
        'notification' => 'array',
        'config' => 'array',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'version_id');
    }

    public function waitsForHuman(): bool
    {
        return $this->type->waitsForHuman();
    }

    /** Berapa approver yang dibutuhkan agar node ini selesai. */
    public function requiredApprovals(int $total): int
    {
        $policy = $this->quorum_policy ?? QuorumPolicy::ALL;

        return $policy->requiredApprovals($total, (int) ($this->quorum_value ?? 0));
    }

    /** Ambang penolakan sebelum node berhenti. */
    public function rejectionThreshold(int $total): int
    {
        $policy = $this->quorum_policy ?? QuorumPolicy::ALL;

        return $policy->rejectionThreshold($total, (int) ($this->quorum_value ?? 0));
    }

    /** @return array<string, mixed> */
    public function toGraphArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'name' => $this->name,
            'description' => $this->description,
            'sequence' => (int) $this->sequence,
            'approval_mode' => $this->approval_mode?->value,
            'quorum_policy' => $this->quorum_policy?->value,
            'quorum_value' => $this->quorum_value === null ? null : (int) $this->quorum_value,
            'sla_minutes' => $this->sla_minutes === null ? null : (int) $this->sla_minutes,
            'required' => (bool) $this->required,
            'allow_skip' => (bool) $this->allow_skip,
            'allow_reassign' => (bool) $this->allow_reassign,
            'self_approval_rule' => $this->self_approval_rule,
            'approvers' => $this->approvers ?? [],
            'tiers' => $this->tiers ?? [],
            'condition_code' => $this->condition_code,
            'condition' => $this->condition ?? [],
            'escalation' => $this->escalation ?? [],
            'actions' => $this->actions ?? [],
            'notification' => $this->notification ?? [],
            'config' => $this->config ?? [],
            'position_x' => $this->position_x,
            'position_y' => $this->position_y,
        ];
    }
}
