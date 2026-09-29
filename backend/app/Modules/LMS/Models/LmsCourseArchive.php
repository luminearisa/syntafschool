<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arsip course (§49). Arsip tidak menghapus riwayat belajar; ia hanya
 * menjadikan course read-only dan menyimpan ringkasannya.
 */
class LmsCourseArchive extends BaseModel
{
    protected $table = 'lms_course_archives';

    protected $casts = [
        'snapshot' => 'array',
        'students_count' => 'integer',
        'items_count' => 'integer',
        'completions_count' => 'integer',
        'archived_at' => 'datetime',
        'restored_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function isActive(): bool
    {
        return $this->restored_at === null;
    }
}
