<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot konteks yang dibekukan saat submit (§65).
 *
 * Nama kelas diberi akhiran `Snapshot` agar tidak tertukar dengan value object
 * `App\Modules\Workflow\Domain\Support\WorkflowContext`.
 */
class WorkflowContextSnapshot extends WorkflowModel
{
    protected $table = 'workflow_contexts';

    public $timestamps = false;

    protected $casts = [
        'snapshot' => 'array',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }
}
