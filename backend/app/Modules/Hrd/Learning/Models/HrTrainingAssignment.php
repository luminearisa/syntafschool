<?php

namespace App\Modules\Hrd\Learning\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hrd\Learning\Models\HrTrainingRequest;
use App\Modules\Hrd\Learning\Models\HrTrainingRequirement;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Learning\Models\HrTrainingCatalog;
use App\Modules\Hrd\Learning\Models\HrTrainingCompletion;
use App\Modules\Hrd\Models\HrEmployee;

/**
 * Phase 2 — tabel `hr_training_assignments` (domain Learning).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrTrainingAssignment extends HrdModel
{
    protected $table = 'hr_training_assignments';

    protected $guarded = [];
    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'is_mandatory' => 'boolean',
        'due_date' => 'date:Y-m-d',
        'effort_hours' => 'decimal:2',
        'meta' => 'array',
    ];

    public function training(): BelongsTo
    {
        return $this->belongsTo(HrTrainingCatalog::class, 'training_id');
    }
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
    public function completion(): HasOne
    {
        return $this->hasOne(HrTrainingCompletion::class, 'assignment_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(HrTrainingRequest::class, 'request_id');
    }
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(HrTrainingRequirement::class, 'requirement_id');
    }
}
