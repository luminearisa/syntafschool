<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jejak audit modul Procurement. Tabel: `proc_audit_logs`. */
class AuditLog extends ProcurementModel
{
    protected $table = 'proc_audit_logs';

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'action' => ProcurementAuditAction::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
