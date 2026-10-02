<?php

namespace App\Modules\Hrd\Learning\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hrd\Learning\Models\HrTrainingCatalog;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Learning\Models\HrTrainingAssessment;
use App\Modules\Hrd\Models\HrEmployee;

/**
 * Phase 2 — tabel `hr_training_completions` (domain Learning).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrTrainingCompletion extends HrdModel
{
    protected $table = 'hr_training_completions';

    protected $guarded = [];
    protected $casts = [
        'completed_at' => 'datetime',
        'score' => 'decimal:2',
        'issued_at' => 'date:Y-m-d',
        'expires_at' => 'date:Y-m-d',
        'skill_updates' => 'array',
        'updated_skills' => 'boolean',
        'verified_at' => 'datetime',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
    public function assessments(): HasMany
    {
        return $this->hasMany(HrTrainingAssessment::class, 'completion_id');
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(HrTrainingCatalog::class, 'training_id');
    }
}
