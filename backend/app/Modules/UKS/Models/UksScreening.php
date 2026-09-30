<?php

namespace App\Modules\UKS\Models;

use App\Models\AcademicYear;
use App\Models\BaseModel;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Modules\UKS\Domain\Enums\ScreeningResult;
use App\Modules\UKS\Domain\Enums\ScreeningType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UksScreening extends BaseModel
{
    protected $table = 'uks_screenings';

    protected $casts = [
        'screened_at' => 'date',
        'height_cm' => 'decimal:1',
        'weight_kg' => 'decimal:1',
        'bmi' => 'decimal:2',
        'screening_type' => ScreeningType::class,
        'result' => ScreeningResult::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function screenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'screened_by');
    }
}
