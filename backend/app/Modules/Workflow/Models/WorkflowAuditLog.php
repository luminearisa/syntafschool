<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak audit berantai (§48).
 *
 * Setiap baris memuat `previous_hash` dan `hash` dirinya sendiri, membentuk
 * rantai yang dapat diverifikasi ulang (`GET /workflows/audit/verify`). Menghapus
 * atau menyunting satu baris di tengah rantai akan terbaca sebagai pemutusan.
 * Baris audit tidak memiliki `updated_at` dan tidak pernah diperbarui.
 *
 * @property AuditAction $action
 */
class WorkflowAuditLog extends WorkflowModel
{
    protected $table = 'workflow_audit_logs';

    public $timestamps = false;

    protected $casts = [
        'action' => AuditAction::class,
        'before' => 'array',
        'after' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Bahan hash: seluruh isi baris kecuali hash itu sendiri (§48). */
    public function payloadForHash(): array
    {
        return [
            'instance_id' => $this->instance_id,
            'definition_id' => $this->definition_id,
            'version_id' => $this->version_id,
            'step_instance_id' => $this->step_instance_id,
            'actor_id' => $this->actor_id,
            'action' => $this->action instanceof AuditAction ? $this->action->value : $this->action,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'before' => $this->before,
            'after' => $this->after,
            'reason' => $this->reason,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
