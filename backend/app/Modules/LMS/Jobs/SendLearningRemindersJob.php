<?php

namespace App\Modules\LMS\Jobs;

use App\Modules\LMS\Application\Services\LmsGateway;
use App\Modules\LMS\Domain\Enums\NotificationEvent;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityCompletion;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pengingat tenggat & keterlambatan (§10, §11).
 *
 * Idempotent: kunci notifikasi memuat tanggal, sehingga pengingat yang sama
 * tidak terkirim dua kali walau job dijalankan ulang.
 */
class SendLearningRemindersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly bool $overdue = false) {}

    public function uniqueId(): string
    {
        return 'lms-reminders-'.($this->overdue ? 'overdue' : 'due');
    }

    public function handle(LmsGateway $gateway): void
    {
        $days = $this->overdue
            ? (int) config('lms.automation.overdue_reminder_days', 3)
            : (int) config('lms.automation.due_reminder_days', 2);

        $activities = LmsActivity::query()
            ->whereNotNull('due_at')
            ->when(
                $this->overdue,
                fn ($query) => $query->where('due_at', '<', now())->where('due_at', '>=', now()->subDays(max(1, $days))),
                fn ($query) => $query->where('due_at', '>=', now())->where('due_at', '<=', now()->addDays(max(1, $days))),
            )
            ->where('status', 'PUBLISHED')
            ->with('course:id,name')
            ->get();

        $sent = 0;

        foreach ($activities as $activity) {
            $enrollments = LmsEnrollment::query()
                ->where('course_id', $activity->course_id)
                ->where('role', 'STUDENT')
                ->whereIn('status', ['ACTIVE', 'COMPLETED'])
                ->get();

            $completed = LmsActivityCompletion::query()
                ->where('learning_item_id', $activity->learning_item_id)
                ->where('status', 'COMPLETE')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $recipients = $enrollments
                ->reject(fn (LmsEnrollment $enrollment) => in_array((int) $enrollment->user_id, $completed, true))
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($recipients === []) {
                continue;
            }

            $result = $gateway->notify(
                $recipients,
                $this->overdue ? NotificationEvent::ACTIVITY_OVERDUE : NotificationEvent::ACTIVITY_DUE_SOON,
                [
                    'title' => $this->overdue ? 'Tenggat terlewat: '.$activity->title : 'Tenggat dekat: '.$activity->title,
                    'body' => ($activity->course?->name ?? 'Course').' — '.$activity->title
                        .($activity->due_at !== null ? ' (tenggat '.$activity->due_at->translatedFormat('d M Y H:i').')' : ''),
                    'context' => ['activity_id' => (int) $activity->id, 'course_id' => (int) $activity->course_id],
                ],
                null,
                ($this->overdue ? 'overdue' : 'due').':'.$activity->id.':'.now()->toDateString(),
            );

            $sent += $result['sent'] ?? 0;
        }

        cache()->put('lms:reminders:last_run', now()->toIso8601String(), now()->addDay());
        cache()->put('lms:reminders:sent', $sent, now()->addDay());
    }
}
