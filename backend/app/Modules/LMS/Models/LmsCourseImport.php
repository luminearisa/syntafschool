<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\ImportConflictStrategy;
use App\Modules\LMS\Domain\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat impor course (§14). */
class LmsCourseImport extends BaseModel
{
    protected $table = 'lms_course_imports';

    protected $casts = [
        'status' => ImportStatus::class,
        'conflict_strategy' => ImportConflictStrategy::class,
        'summary' => 'array',
        'validation' => 'array',
        'preview' => 'array',
        'conflicts' => 'array',
        'imported_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'target_course_id');
    }

    public function toPayload(): array
    {
        return [
            'id' => (int) $this->id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'conflict_strategy' => $this->conflict_strategy?->value,
            'source_name' => $this->source_name,
            'course_id' => $this->target_course_id !== null ? (int) $this->target_course_id : null,
            'summary' => $this->summary,
            'validation' => $this->validation,
            'preview' => $this->preview,
            'conflicts' => $this->conflicts,
            'error_message' => $this->error_message,
            'imported_at' => $this->imported_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
