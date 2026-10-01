<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat submit/revisi (§35). Setiap resubmission menambah baris — tidak
 * menimpa yang sebelumnya.
 */
class WorkflowSubmission extends WorkflowModel
{
    protected $table = 'workflow_submissions';

    public $timestamps = false;

    protected $casts = [
        'submitted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
