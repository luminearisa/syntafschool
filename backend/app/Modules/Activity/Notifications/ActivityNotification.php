<?php

namespace App\Modules\Activity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi aktivitas (kanal database).
 *
 * Bentuknya sengaja ringkas: judul, isi, dan tautan. Tidak ada lampiran berkas
 * atau data pribadi yang terbawa \— bila sekolah ingin surel/push, tinggal
 * menambah kanal di konfigurasi dan provider notifikasi yang sesuai.
 */
class ActivityNotification extends Notification
{
    use Queueable;

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly array $payload) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return (array) config('activity.notification.channels', ['database']);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->payload['event'] ?? 'activity.notification',
            'title' => $this->payload['title'] ?? 'Aktivitas siswa',
            'body' => $this->payload['body'] ?? '',
            'url' => $this->payload['url'] ?? null,
            'program_id' => $this->payload['program_id'] ?? null,
            'student_id' => $this->payload['student_id'] ?? null,
        ];
    }
}
