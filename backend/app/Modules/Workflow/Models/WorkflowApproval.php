<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\ApprovalStatus;
use App\Modules\Workflow\Domain\Enums\ApproverSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan satu approver pada satu step (§5, §8, §9).
 *
 * Baris ini adalah "kotak masuk" seseorang. Ia menyimpan **dari mana** approver
 * berasal (`source`, `resolver`, `resolver_spec`) sehingga audit dapat
 * menjelaskan penugasan tanpa membaca ulang versi workflow yang mungkin sudah
 * berubah.
 *
 * @property ApprovalStatus $status
 * @property ApproverSource $source
 */
class WorkflowApproval extends WorkflowModel
{
    protected $table = 'workflow_approvals';

    protected $casts = [
        'status' => ApprovalStatus::class,
        'source' => ApproverSource::class,
        'resolver_spec' => 'array',
        'is_delegate' => 'boolean',
        'assigned_at' => 'datetime',
        'notified_at' => 'datetime',
        'reminded_at' => 'datetime',
        'due_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStepInstance::class, 'step_instance_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function originalApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_approver_id');
    }

    public function delegation(): BelongsTo
    {
        return $this->belongsTo(WorkflowDelegation::class, 'delegation_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(WorkflowDecision::class, 'decision_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::PENDING->value);
    }

    public function scopeForApprover(Builder $query, int $userId): Builder
    {
        return $query->where('approver_id', $userId);
    }

    public function isActionableBy(int $userId): bool
    {
        return $this->approver_id === $userId && $this->status->isPending();
    }
}
