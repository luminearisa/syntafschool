<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Workflow\Domain\Enums\SlaStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan SLA satu step (§41, §42).
 *
 * Menyimpan seluruh ambang (remind/due soon/escalate) agar keputusan eskalasi
 * dapat dipertanggungjawabkan terhadap konfigurasi yang berlaku saat itu.
 *
 * @property SlaStatus $status
 */
class WorkflowSlaRecord extends WorkflowModel
{
    protected $table = 'workflow_sla_records';

    protected $casts = [
        'status' => SlaStatus::class,
        'started_at' => 'datetime',
        'due_at' => 'datetime',
        'remind_at' => 'datetime',
        'due_soon_at' => 'datetime',
        'escalate_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'due_soon_notified_at' => 'datetime',
        'overdue_at' => 'datetime',
        'escalated_at' => 'datetime',
        'escalated_to' => 'array',
        'metadata' => 'array',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStepInstance::class, 'step_instance_id');
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast() && $this->status->isOpen();
    }

    public function remainingMinutes(): ?int
    {
        return $this->due_at === null ? null : (int) now()->diffInMinutes($this->due_at, false);
    }
}
