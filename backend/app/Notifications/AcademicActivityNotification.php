<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi akademik in-app (kanal `database`).
 *
 * Dipakai untuk memberi tahu akun wali murid saat rapor diterbitkan (termasuk
 * bila ada nilai di bawah KKTP) atau saat siswa alpa beberapa hari berturut.
 * `type` dipakai aplikasi mobile/SDK untuk memilih ikon & tautan; mengikuti
 * pola modul lain (mis. Boarding) agar tidak perlu tabel rute terpisah.
 */
class AcademicActivityNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public array $context = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'student_id' => $this->context['student_id'] ?? null,
            'reference_type' => $this->context['reference_type'] ?? null,
            'reference_id' => $this->context['reference_id'] ?? null,
            'route' => $this->context['route'] ?? null,
            'meta' => $this->context['meta'] ?? null,
        ];
    }
}
