<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Modules\Supervisi\Domain\Enums\ProgramStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Program supervisi tahunan/semester (dokumen wajib Permendikbud 15/2018). */
class SupProgram extends BaseModel
{
    use SoftDeletes;

    protected $table = 'sup_programs';

    protected $casts = [
        'status' => ProgramStatus::class,
        'technique_plan' => 'array',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'published_at' => 'datetime',
    ];

    public function targets(): HasMany
    {
        return $this->hasMany(SupProgramTarget::class, 'program_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SupAssignment::class, 'program_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(SupSchedule::class, 'program_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(SupProgramEvaluation::class, 'program_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(\App\Models\AcademicYear::class, 'academic_year_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Semester::class, 'semester_id');
    }
}
