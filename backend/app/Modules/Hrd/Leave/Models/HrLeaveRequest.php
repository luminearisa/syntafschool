<?php

namespace App\Modules\Hrd\Leave\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Leave\Models\HrLeaveApproval;
use App\Modules\Hrd\Leave\Models\HrLeaveRequestDay;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrLeaveType;

/**
 * Phase 2 — tabel `hr_leave_requests` (domain Leave).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrLeaveRequest extends HrdModel
{
    protected $table = 'hr_leave_requests';

    protected $guarded = [];
    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'days' => 'decimal:2',
        'half_day' => 'boolean',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'conflict_flags' => 'array',
        'balance_breakdown' => 'array',
        'attendance_applied' => 'boolean',
        'payroll_impact' => 'array',
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
    public function days(): HasMany
    {
        return $this->hasMany(HrLeaveRequestDay::class, 'request_id');
    }
    public function approvals(): HasMany
    {
        return $this->hasMany(HrLeaveApproval::class, 'request_id');
    }
}
