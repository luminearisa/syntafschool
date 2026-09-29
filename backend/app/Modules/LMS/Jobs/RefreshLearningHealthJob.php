<?php

namespace App\Modules\LMS\Jobs;

use App\Modules\LMS\Application\Services\LearningHealthService;
use App\Modules\LMS\Application\Services\LmsGateway;
use App\Modules\LMS\Domain\Enums\NotificationEvent;
use App\Modules\LMS\Models\LmsCourse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Menyegarkan indikator kesehatan belajar (§21, §11).
 *
 * Dijalankan berkala supaya guru melihat status ON_TRACK/AT_RISK/INACTIVE tanpa
 * memaksa hitungan berat di setiap permintaan halaman.
 */
class RefreshLearningHealthJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly bool $notifyAtRisk = false) {}

    public function uniqueId(): string
    {
        return 'lms-health-refresh';
    }

    public function handle(LearningHealthService $health): void
    {
        $updated = $health->refreshActiveCourses();

        if (! $this->notifyAtRisk || config('lms.automation.notify_at_risk', true) !== true) {
            return;
        }

        foreach (LmsCourse::query()->where('status', 'PUBLISHED')->get() as $course) {
            foreach ($health->summaryForCourse($course, 5)['at_risk'] ?? [] as $row) {
                $guardians = app(LmsGateway::class)
                    ->parents()
                    ->guardianUserIdsForStudent((int) ($row['student_id'] ?? 0));

                if ($guardians === []) {
                    continue;
                }

                app(LmsGateway::class)->notify(
                    $guardians,
                    NotificationEvent::LEARNING_AT_RISK,
                    [
                        'title' => 'Perlu perhatian belajar',
                        'body' => 'Progres belajar ananda pada course '.$course->name.' perlu pendampingan.',
                        'context' => ['course_id' => (int) $course->id, 'student_id' => $row['student_id'] ?? null],
                    ],
                    null,
                    'at-risk:'.$course->id.':'.($row['user_id'] ?? 'x').':'.now()->toDateString(),
                );
            }
        }
    }
}
