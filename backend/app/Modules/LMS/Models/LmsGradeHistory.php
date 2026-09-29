<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\GradeHistoryAction;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Riwayat nilai (§19, §53) — **append only**.
 *
 * Model ini menolak update & delete pada level Eloquent supaya koreksi nilai
 * tidak pernah menghapus jejak lama, berapa pun jalur kodenya.
 */
class LmsGradeHistory extends BaseModel
{
    protected $table = 'lms_grade_histories';

    public $timestamps = false;

    protected $casts = [
        'action' => GradeHistoryAction::class,
        'previous_value' => 'array',
        'new_value' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Riwayat nilai bersifat immutable dan tidak dapat diubah.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Riwayat nilai bersifat immutable dan tidak dapat dihapus.');
        });
    }

    public function gradebook(): BelongsTo
    {
        return $this->belongsTo(LmsGradebook::class, 'gradebook_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LmsGradeItem::class, 'grade_item_id');
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(LmsAssessmentResult::class, 'assessment_result_id');
    }
}
