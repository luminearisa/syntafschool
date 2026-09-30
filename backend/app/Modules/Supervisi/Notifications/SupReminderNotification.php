<?php

namespace App\Modules\Supervisi\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Pengingat terjadwal supervisi (Fase 4).
 *
 * Satu kelas untuk seluruh jenis pengingat: isi pesan disusun oleh
 * `ReminderService` (judul, isi, tautan, jenis, kunci idempotensi) sehingga
 * kanal notifikasi tidak perlu tahu urusan modul.
 */
class SupReminderNotification extends Notification
{
    use Queueable;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly array $payload,
        public readonly array $channels = ['database'],
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->payload + ['type' => $this->payload['type'] ?? 'supervisi.reminder'];
    }
}
