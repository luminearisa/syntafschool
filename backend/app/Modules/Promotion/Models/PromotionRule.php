<?php

namespace App\Modules\Promotion\Models;

use App\Models\AcademicYear;
use App\Models\EducationLevel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Aturan kenaikan kelas untuk satu tahun ajaran (opsional dipersempit per jenjang).
 *
 * Satu tahun ajaran bisa memiliki aturan umum (`education_level_id` null) dan
 * aturan khusus jenjang; yang khusus mengalahkan yang umum.
 */
class PromotionRule extends PromotionModel
{
    use SoftDeletes;

    protected $table = 'promotion_rules';

    protected $casts = [
        'min_average_score' => 'decimal:2',
        'min_attendance_percent' => 'decimal:2',
        'max_unpassed_subjects' => 'integer',
        'min_passing_grade' => 'decimal:2',
        'violation_point_limit' => 'integer',
        'is_active' => 'boolean',
    ];

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
