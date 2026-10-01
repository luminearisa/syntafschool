<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relasi atasan–bawahan untuk resolver `manager` (§25).
 */
class WorkflowOrgReport extends WorkflowModel
{
    protected $table = 'workflow_org_reports';

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function backupManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'backup_user_id');
    }

    public function isEffectiveOn(?\DateTimeInterface $moment = null): bool
    {
        $date = $moment === null ? now() : \Carbon\Carbon::parse($moment);

        if ($this->effective_from !== null && $this->effective_from->isAfter($date)) {
            return false;
        }

        return $this->effective_to === null || ! $this->effective_to->isBefore($date);
    }
}
