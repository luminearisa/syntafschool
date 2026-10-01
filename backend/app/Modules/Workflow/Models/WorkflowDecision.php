<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\DecisionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keputusan seorang aktor — **baris yang tidak pernah boleh berubah** (§32, §47).
 *
 * Model ini mematikan `$timestamps` dan hanya menulis `created_at`; tidak ada
 * endpoint update/delete untuk keputusan. Koreksi dilakukan dengan keputusan
 * baru (`is_override`) yang alasannya wajib, bukan dengan menulis ulang sejarah.
 *
 * @property DecisionType $decision
 */
class WorkflowDecision extends WorkflowModel
{
    protected $table = 'workflow_decisions';

    public $timestamps = false;

    protected $casts = [
        'decision' => DecisionType::class,
        'is_override' => 'boolean',
        'metadata' => 'array',
        'decided_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStepInstance::class, 'step_instance_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function originalApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_approver_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(WorkflowApproval::class, 'approval_id');
    }

    /** Apakah keputusan ini diambil oleh orang yang berbeda dari penerima tugas. */
    public function isDelegatedDecision(): bool
    {
        return $this->original_approver_id !== null && $this->original_approver_id !== $this->actor_id;
    }
}
