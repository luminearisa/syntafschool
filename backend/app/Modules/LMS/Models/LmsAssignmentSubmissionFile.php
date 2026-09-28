<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Berkas lampiran submission / umpan balik (§6) — memakai disk Laravel yang sama. */
class LmsAssignmentSubmissionFile extends BaseModel
{
    protected $table = 'lms_assignment_submission_files';

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(LmsAssignmentSubmission::class, 'submission_id');
    }

    public function isFeedback(): bool
    {
        return $this->purpose === 'FEEDBACK';
    }

    public function humanSize(): string
    {
        $size = (int) $this->file_size;

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($size < 1024) {
                return $size.' '.$unit;
            }

            $size = (int) round($size / 1024);
        }

        return $size.' TB';
    }
}
