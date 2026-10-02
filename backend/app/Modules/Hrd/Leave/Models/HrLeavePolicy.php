<?php

namespace App\Modules\Hrd\Leave\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrLeaveType;

/**
 * Phase 2 — tabel `hr_leave_policies` (domain Leave).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrLeavePolicy extends HrdModel
{
    protected $table = 'hr_leave_policies';

    protected $guarded = [];
    protected $casts = [
        'entitlement_days' => 'decimal:2',
        'accrual_rate' => 'decimal:2',
        'max_balance' => 'decimal:2',
        'carry_forward' => 'boolean',
        'carry_forward_limit' => 'decimal:2',
        'requires_document' => 'boolean',
        'paid' => 'boolean',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(HrLeaveType::class, 'leave_type_id');
    }
}
