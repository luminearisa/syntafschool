<?php

namespace App\Modules\Hrd\Workforce\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Workforce\Models\HrOvertimeRecord;

/**
 * Phase 2 — tabel `hr_overtime_requests` (domain Workforce).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrOvertimeRequest extends HrdModel
{
    protected $table = 'hr_overtime_requests';

    protected $guarded = [];
    protected $casts = [
        'date' => 'date:Y-m-d',
        'planned_start' => 'datetime',
        'planned_end' => 'datetime',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
    public function records(): HasMany
    {
        return $this->hasMany(HrOvertimeRecord::class, 'overtime_request_id');
    }
}
