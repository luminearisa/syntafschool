<?php

namespace App\Modules\Hrd\Career\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Career\Models\HrMentoringSession;
use App\Modules\Hrd\Models\HrEmployee;

/**
 * Phase 2 — tabel `hr_mentor_assignments` (domain Career).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrMentorAssignment extends HrdModel
{
    protected $table = 'hr_mentor_assignments';

    protected $guarded = [];
    protected $casts = [
        'focus_areas' => 'array',
        'started_at' => 'date:Y-m-d',
        'ended_at' => 'date:Y-m-d',
        'progress' => 'decimal:2',
        'meta' => 'array',
    ];

    public function mentor(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'mentor_employee_id');
    }
    public function mentee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'mentee_employee_id');
    }
    public function sessions(): HasMany
    {
        return $this->hasMany(HrMentoringSession::class, 'assignment_id');
    }
}
