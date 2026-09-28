<?php

namespace App\Modules\LMS\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Pemberitahuan pengumuman course (§24).
 *
 * Memakai kanal `database` yang sudah dipakai modul lain (tabel `notifications`
 * dari migration Library), jadi tidak ada sistem notifikasi baru.
 */
class LmsCourseAnnouncementNotification extends Notification
{
    use Queueable;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly array $payload,
        public readonly array $channels = ['database'],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->payload + ['type' => 'lms.announcement'];
    }
}
