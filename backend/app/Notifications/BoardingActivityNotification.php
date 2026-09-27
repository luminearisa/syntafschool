<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi in-app modul boarding (kanal `database`, sama seperti modul lain).
 *
 * `type` dipakai frontend untuk memilih ikon/tautan dan untuk mencegah dobel
 * kirim (`BoardingNotificationService::alreadySent()`); `route` dikirim apa
 * adanya supaya portal orang tua dan dashboard musyrif membuka halaman yang
 * sama tanpa menyalin tabel rute ke dalam notifikasi.
 */
class BoardingActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public array $context = [],
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'student_id' => $this->context['student_id'] ?? null,
            'reference_type' => $this->context['reference_type'] ?? null,
            'reference_id' => $this->context['reference_id'] ?? null,
            'status' => $this->context['status'] ?? null,
            'route' => $this->context['route'] ?? null,
        ];
    }
}
