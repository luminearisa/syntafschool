<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\ActivityGradeSource;
use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan nilai aktivitas (§46–47).
 *
 * Ini **bukan** nilai akademik SIAKAD. Modul LMS hanya menghasilkan assessment
 * result; pengiriman ke ERP dilakukan lewat `LmsIntegrationService` secara
 * eksplisit sehingga nilai rapor tidak pernah berubah diam-diam.
 */
class LmsActivityGrade extends BaseModel
{
    use LogsLmsActivity, ResolvesIdentity;

    protected $table = 'lms_activity_grades';

    protected $casts = [
        'source' => ActivityGradeSource::class,
        'grade' => 'decimal:2',
        'raw_grade' => 'decimal:2',
        'maximum_grade' => 'decimal:2',
        'original_grade' => 'decimal:2',
        'passed' => 'boolean',
        'graded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LmsActivity::class, 'activity_id');
    }

    public function learningItem(): BelongsTo
    {
        return $this->belongsTo(LmsLearningItem::class, 'learning_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'graded_by');
    }

    public function scopeForCourse(Builder $query, int $courseId): Builder
    {
        return $query->where('course_id', $courseId);
    }

    public function percent(): float
    {
        $maximum = (float) $this->maximum_grade;

        return $maximum > 0 ? round(((float) $this->grade) / $maximum * 100, 2) : 0.0;
    }
}
