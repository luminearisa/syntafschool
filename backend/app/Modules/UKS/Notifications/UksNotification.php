<?php

namespace App\Modules\UKS\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Satu kelas notifikasi untuk seluruh peristiwa UKS.
 *
 * Isi pesan disusun pemanggil (judul, isi, tautan, jenis) sehingga kanal
 * notifikasi tidak perlu tahu urusan modul. Kunci idempotensi mencegah
 * pengingat yang sama terkirim dua kali pada hari yang sama.
 */
class UksNotification extends Notification
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
        return $this->payload + ['type' => $this->payload['type'] ?? 'uks.notice'];
    }
}
