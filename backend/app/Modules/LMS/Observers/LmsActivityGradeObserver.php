<?php

namespace App\Modules\LMS\Observers;

use App\Modules\LMS\Application\Services\AssessmentResultService;
use App\Modules\LMS\Models\LmsActivityGrade;
use Throwable;

/**
 * Nilai aktivitas (Phase 2) otomatis tercermin di Gradebook Phase 3
 * (§13, §61: Assignment → Submission → Grade → Assessment Result → Gradebook).
 *
 * Kegagalan sinkronisasi tidak membatalkan penilaian aktivitas; nilainya tetap
 * tersimpan dan dapat ditarik ulang lewat tombol "Tarik nilai dari aktivitas".
 */
class LmsActivityGradeObserver
{
    public function __construct(private readonly AssessmentResultService $results) {}

    public function saved(LmsActivityGrade $grade): void
    {
        if ($grade->grade === null && $grade->raw_grade === null) {
            return;
        }

        try {
            $this->results->pullFromActivityGrade($grade);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
