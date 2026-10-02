<?php

namespace App\Modules\Hrd\Leave\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrLeaveType;

/**
 * Phase 2 — tabel `hr_leave_balances` (domain Leave).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrLeaveBalance extends HrdModel
{
    protected $table = 'hr_leave_balances';

    protected $guarded = [];
    protected $casts = [
        'entitlement_days' => 'decimal:2',
        'accrued_days' => 'decimal:2',
        'used_days' => 'decimal:2',
        'pending_days' => 'decimal:2',
        'carry_forward_days' => 'decimal:2',
        'expired_days' => 'decimal:2',
        'adjustment_days' => 'decimal:2',
        'available_days' => 'decimal:2',
        'last_accrued_at' => 'datetime',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(HrLeaveType::class, 'leave_type_id');
    }
}
