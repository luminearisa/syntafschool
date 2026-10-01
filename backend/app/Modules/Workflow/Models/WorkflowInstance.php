<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\InstanceStatus;
use App\Modules\Workflow\Domain\Enums\Priority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Instance workflow — satu dokumen bisnis yang sedang diproses (§16).
 *
 * Instance **tidak** menyimpan salinan entitas bisnis. Yang disimpan hanya
 * rujukan subjek (`subject_type`/`subject_id`), angka yang dibutuhkan mesin
 * (nominal, unit, cost center), dan snapshot konteks di `workflow_contexts`.
 *
 * @property InstanceStatus $status
 * @property Priority $priority
 */
class WorkflowInstance extends WorkflowModel
{
    protected $table = 'workflow_instances';

    protected $casts = [
        'status' => InstanceStatus::class,
        'priority' => Priority::class,
        'amount' => 'decimal:2',
        'current_node_keys' => 'array',
        'execution_state' => 'array',
        'context_summary' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'due_at' => 'datetime',
        'last_decision_at' => 'datetime',
        'returned_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'definition_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'version_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStepInstance::class, 'instance_id')->orderBy('sequence')->orderBy('id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(WorkflowApproval::class, 'instance_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(WorkflowDecision::class, 'instance_id')->orderBy('decided_at');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(WorkflowSubmission::class, 'instance_id')->orderBy('submission_number');
    }

    public function contextSnapshots(): HasMany
    {
        return $this->hasMany(WorkflowContextSnapshot::class, 'instance_id')->orderBy('submission_number');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkflowEvent::class, 'instance_id')->orderBy('occurred_at');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WorkflowComment::class, 'instance_id')->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkflowAttachment::class, 'instance_id');
    }

    public function latestContext(): HasOne
    {
        return $this->hasOne(WorkflowContextSnapshot::class, 'instance_id')->latestOfMany('submission_number');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(WorkflowEscalation::class, 'instance_id');
    }

    /** Penugasan yang masih menunggu keputusan. */
    public function pendingApprovals(): HasMany
    {
        return $this->hasMany(WorkflowApproval::class, 'instance_id')->where('status', 'PENDING');
    }

    public function stepByKey(string $nodeKey, ?int $round = null): ?WorkflowStepInstance
    {
        $query = $this->steps()->where('node_key', $nodeKey);

        if ($round !== null) {
            $query->where('round', $round);
        }

        return $query->orderByDesc('round')->first();
    }

    /** Step yang saat ini menahan alur (untuk visualizer & inbox). */
    public function activeSteps()
    {
        return $this->steps()
            ->whereIn('status', ['ACTIVE', 'WAITING_APPROVAL', 'WAITING_REVIEW', 'WAITING_FOR_APPROVER'])
            ->orderBy('sequence')
            ->get();
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function currentKeys(): array
    {
        return array_values(array_map('strval', $this->current_node_keys ?? []));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InstanceStatus::DRAFT->value,
            InstanceStatus::SUBMITTED->value,
            InstanceStatus::IN_PROGRESS->value,
            InstanceStatus::WAITING_APPROVAL->value,
            InstanceStatus::WAITING_REVIEW->value,
            InstanceStatus::WAITING_FOR_APPROVER->value,
            InstanceStatus::RETURNED->value,
        ]);
    }

    public function scopeForRequester(Builder $query, int $userId): Builder
    {
        return $query->where('requester_id', $userId);
    }
}
