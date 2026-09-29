<?php

namespace App\Modules\LMS\Application\Services;

use App\Models\User;
use App\Modules\LMS\Models\LmsUser;
use App\Modules\LMS\Notifications\LmsBadgeAwardedNotification;
use App\Modules\LMS\Notifications\LmsCertificateIssuedNotification;
use App\Modules\LMS\Notifications\LmsCourseReviewModeratedNotification;
use App\Modules\LMS\Notifications\LmsLiveSessionScheduledNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Pemberitahuan Phase 4 (§14, §21, §27, §31).
 *
 * Sama seperti `GradeNotifier`: menumpang notification engine ERP yang sudah ada
 * dan hanya memakai kanal yang dikonfigurasi — tidak menambah sistem notifikasi
 * baru. Semua kanal default `database` sehingga LMS tetap jalan tanpa konfigurasi
 * mail/push.
 */
class LmsNotifier
{
    /** @param list<int> $userIds @param array<string, mixed> $payload */
    public function badgeAwarded(array $userIds, array $payload): int
    {
        return $this->send(
            $userIds,
            new LmsBadgeAwardedNotification($payload, (array) config('lms.notifications.badge_channels', ['database'])),
        );
    }

    /** @param list<int> $userIds @param array<string, mixed> $payload */
    public function certificateIssued(array $userIds, array $payload): int
    {
        return $this->send(
            $userIds,
            new LmsCertificateIssuedNotification($payload, (array) config('lms.notifications.certificate_channels', ['database'])),
        );
    }

    /** @param list<int> $userIds @param array<string, mixed> $payload */
    public function liveSessionScheduled(array $userIds, array $payload): int
    {
        return $this->send(
            $userIds,
            new LmsLiveSessionScheduledNotification($payload, (array) config('lms.notifications.live_channels', ['database'])),
        );
    }

    /** @param list<int> $userIds @param array<string, mixed> $payload */
    public function reviewModerated(array $userIds, array $payload): int
    {
        return $this->send(
            $userIds,
            new LmsCourseReviewModeratedNotification($payload, (array) config('lms.notifications.review_channels', ['database'])),
        );
    }

    /**
     * Kirim satu notifikasi ke sekumpulan id pengguna.
     *
     * Model penerima mengikuti mode yang aktif (`users` atau `lms_users`) supaya
     * tidak ada notifikasi yang diam-diam menunjuk tabel yang salah.
     *
     * @param  list<int>  $userIds
     */
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
