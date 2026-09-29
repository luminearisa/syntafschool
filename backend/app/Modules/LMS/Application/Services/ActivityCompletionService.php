<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityCompletion;
use App\Modules\LMS\Models\LmsEnrollment;

/**
 * Integrasi aktivitas ke mesin penyelesaian Phase 1 (§40).
 *
 * Setiap aktivitas memetakan satu peristiwa menjadi "selesai":
 *   ASSIGNMENT → submitted        QUIZ    → submitted
 *   FORUM      → jumlah kiriman   LESSON  → seluruh halaman wajib
 *   CHOICE     → response         SURVEY  → submitted
 *
 * Penyelesaian tetap disimpan pada `lms_activity_completions` (Phase 1)
 * sehingga progress course, prasyarat, dan kalender otomatis ikut bergerak.
 */
class ActivityCompletionService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly CourseCompletionService $completions,
        private readonly CourseProgressService $progress,
        private readonly ActivityGradeService $grades,
    ) {}

    /** Peristiwa yang menyelesaikan aktivitas (§40). */
    public function requiredEvent(LmsActivity $activity): string
    {
        return match ($activity->activity_type) {
            ActivityType::ASSIGNMENT => 'submitted',
            ActivityType::QUIZ => 'submitted',
            ActivityType::FORUM => 'posted',
            ActivityType::LESSON => 'completed',
            ActivityType::CHOICE => 'responded',
            ActivityType::SURVEY => 'submitted',
            ActivityType::SCORM => 'completed',
            default => 'submitted',
        };
    }

    /**
     * Tandai aktivitas selesai untuk seorang peserta dan (bila ada) simpan nilainya.
     *
     * @param  array<string, mixed>  $meta
     */
    public function complete(
        LmsActivity $activity,
        LmsEnrollment $enrollment,
        ?float $grade = null,
        string $source = 'activity',
        ?int $actorId = null,
        array $meta = [],
    ): ?LmsActivityCompletion {
        $item = $activity->learningItem;

        if ($item === null) {
            return null;
        }

        $completion = $this->completions->markComplete($enrollment, $item, $grade, $source, $actorId);

        if ($meta !== []) {
            $completion->forceFill(['metadata' => array_merge((array) $completion->metadata, $meta)])->save();
        }

        if ($grade !== null && $activity->isGraded()) {
            $this->grades->record($activity, $enrollment->user_id, [
                'grade' => $grade,
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'graded_by' => $actorId,
                'source' => $activity->activity_type?->value,
            ]);
        }

        $this->progress->recompute($enrollment);

        $this->audit('LMS_ACTIVITY_COMPLETED', $activity, [
            'user_id' => $enrollment->user_id,
            'source' => $source,
            'grade' => $grade,
        ]);

        return $completion;
    }

    /** Batalkan penyelesaian (mis. guru mengembalikan submission). */
    public function revoke(LmsActivity $activity, LmsEnrollment $enrollment): void
    {
        $item = $activity->learningItem;

        if ($item !== null) {
            $this->completions->unmark($enrollment, $item->id);
            $this->progress->recompute($enrollment);
        }
    }

    public function isComplete(LmsActivity $activity, LmsEnrollment $enrollment): bool
    {
        $item = $activity->learningItem;

        return $item !== null && $this->completions->isComplete($enrollment, $item->id);
    }

    /** Aturan penyelesaian efektif aktivitas (item → aktivitas). */
    public function completionType(LmsActivity $activity): CompletionType
    {
        return $activity->completion_rule
            ?? $activity->learningItem?->completion_type
            ?? CompletionType::SUBMIT;
    }
}
