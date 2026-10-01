<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Workflow\Domain\Enums\EscalationAction;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan eskalasi (§40). Menyimpan siapa yang menerima akibat eskalasi dan
 * pada tingkat berapa, sehingga "kenapa request ini pindah ke orang lain?"
 * selalu punya jawaban tertulis.
 *
 * @property EscalationAction $action
 */
class WorkflowEscalation extends WorkflowModel
{
    protected $table = 'workflow_escalations';

    public $timestamps = false;

    protected $casts = [
        'action' => EscalationAction::class,
        'applied_at' => 'datetime',
        'payload' => 'array',
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
}
