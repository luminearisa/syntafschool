<?php

namespace App\Modules\LMS\Application\Services;

use App\Models\User;
use App\Modules\LMS\Models\LmsUser;
use App\Modules\LMS\Notifications\LmsGradeChangedNotification;
use App\Modules\LMS\Notifications\LmsGradeReleasedNotification;
use App\Modules\LMS\Notifications\LmsGradeReviewDecidedNotification;
use App\Modules\LMS\Notifications\LmsGradeReviewRequestedNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Pengiriman pemberitahuan nilai (§46).
 *
 * Menumpang notification engine ERP yang sudah ada: hanya kanal `database`
 * (dan kanal lain yang dikonfigurasi) — tidak ada sistem notifikasi baru.
 */
class GradeNotifier
{
    /** @param list<int> $userIds @param array<string, mixed> $payload */
    public function gradeReleased(array $userIds, array $payload): int
    {
        return $this->send(
            $userIds,
            new LmsGradeReleasedNotification($payload, (array) config('lms.notifications.grade_channels', ['database'])),
        );
    }

    /** @param list<int> $userIds @param array<string, mixed> $payload */
    public function gradeChanged(array $userIds, array $payload): int
    {
        return $this->send(
            $userIds,
            new LmsGradeChangedNotification($payload, (array) config('lms.notifications.grade_channels', ['database'])),
        );
    }

    /** @param list<int> $userIds @param array<string, mixed> $payload */
    public function reviewRequested(array $userIds, array $payload): int
    {
        return $this->send(
            $userIds,
            new LmsGradeReviewRequestedNotification($payload, (array) config('lms.notifications.grade_channels', ['database'])),
        );
    }

    /** @param list<int> $userIds @param array<string, mixed> $payload */
    public function reviewDecided(array $userIds, array $payload): int
    {
        return $this->send(
            $userIds,
            new LmsGradeReviewDecidedNotification($payload, (array) config('lms.notifications.grade_channels', ['database'])),
        );
    }

    /** @param list<int> $userIds */
    public function send(array $userIds, Notification $notification): int
    {
        $ids = array_values(array_unique(array_filter($userIds, fn ($id) => (int) $id > 0)));

        if ($ids === []) {
            return 0;
        }

        $model = config('lms.standalone', false) ? LmsUser::class : User::class;

        $notifiables = $model::query()
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn ($user) => method_exists($user, 'notify'))
            ->all();

        if ($notifiables === []) {
            return 0;
        }

        NotificationFacade::send($notifiables, $notification);

        return count($notifiables);
    }
}
