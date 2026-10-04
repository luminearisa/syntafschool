<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\Enums\AccountingHandoffStatus;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Catatan integrasi (outbox/inbox) ke penyedia luar. Tabel: `proc_integrations`.
 *
 * `idempotency_key` unik menjamin percobaan ulang tidak menghasilkan posting
 * ganda; `status` menjejaki perjalanan handoff (§33, §63).
 */
class ProcurementIntegration extends ProcurementModel
{
    protected $table = 'proc_integrations';

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
        'status' => AccountingHandoffStatus::class,
        'sent_at' => 'datetime',
        'responded_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
