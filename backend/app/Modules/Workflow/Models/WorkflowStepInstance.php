<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\ApprovalMode;
use App\Modules\Workflow\Domain\Enums\NodeType;
use App\Modules\Workflow\Domain\Enums\QuorumPolicy;
use App\Modules\Workflow\Domain\Enums\SlaStatus;
use App\Modules\Workflow\Domain\Enums\StepResult;
use App\Modules\Workflow\Domain\Enums\StepStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Kemunculan satu node pada sebuah instance (§18, §46).
 *
 * `round` menaik setiap kali request dikembalikan lalu disubmit ulang, sehingga
 * percobaan pertama dan percobaan berikutnya tetap terpisah dan dapat diaudit.
 *
 * @property StepStatus $status
 * @property StepResult|null $result
 * @property NodeType $node_type
 */
class WorkflowStepInstance extends WorkflowModel
{
    protected $table = 'workflow_step_instances';

    protected $casts = [
        'node_type' => NodeType::class,
        'status' => StepStatus::class,
        'result' => StepResult::class,
        'approval_mode' => ApprovalMode::class,
        'quorum_policy' => QuorumPolicy::class,
        'sla_status' => SlaStatus::class,
        'required' => 'boolean',
        'metadata' => 'array',
        'activated_at' => 'datetime',
        'due_at' => 'datetime',
        'first_response_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(WorkflowApproval::class, 'step_instance_id');
    }

    public function activeApprovals(): HasMany
    {
        return $this->hasMany(WorkflowApproval::class, 'step_instance_id')->where('status', 'PENDING');
    }

    public function slaRecord(): HasOne
    {
        return $this->hasOne(WorkflowSlaRecord::class, 'step_instance_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** Berapa suara setuju yang masih dibutuhkan. */
    public function remainingApprovals(): int
    {
        $policy = $this->quorum_policy ?? QuorumPolicy::ALL;
        $required = $policy->requiredApprovals((int) $this->total_approvers, (int) ($this->quorum_value ?? 0));

        return max(0, $required - (int) $this->approvals_count);
    }

    /** Berapa penolakan lagi yang menghentikan step ini. */
    public function remainingRejections(): int
    {
        $policy = $this->quorum_policy ?? QuorumPolicy::ALL;
        $threshold = $policy->rejectionThreshold((int) $this->total_approvers, (int) ($this->quorum_value ?? 0));

        return max(0, $threshold - (int) $this->rejections_count);
    }
}
