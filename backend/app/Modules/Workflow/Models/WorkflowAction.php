<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aksi bernama pada sebuah versi (§43).
 *
 * `handler` menunjuk alias yang didaftarkan modul pada `WorkflowRegistrar`
 * (mis. `finance.create_purchase_order`). Engine tidak tahu isi handler itu.
 */
class WorkflowAction extends WorkflowModel
{
    protected $table = 'workflow_actions';

    protected $casts = [
        'params' => 'array',
        'is_active' => 'boolean',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'version_id');
    }
}
