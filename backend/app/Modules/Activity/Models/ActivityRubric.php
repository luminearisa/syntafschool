<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\AssessmentType;

/**
 * Rubrik penilaian aktivitas; skalanya dapat disesuaikan sekolah.
 *
 * Tabel: `activity_rubrics`
 */
class ActivityRubric extends ActivityModel
{
    protected $table = 'activity_rubrics';

    protected $casts = [
        'type' => AssessmentType::class,
        'scale' => 'array',
        'is_active' => 'boolean',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function items(): HasMany
    {
        return $this->hasMany(ActivityRubricItem::class, 'rubric_id')->orderBy('sort_order');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
