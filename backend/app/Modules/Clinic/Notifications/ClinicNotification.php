<?php

namespace App\Modules\Clinic\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Satu kelas notifikasi untuk seluruh peristiwa klinik.
 *
 * Isi pesan disusun pemanggil setelah disaring kebijakan privasi, sehingga
 * kanal (database/mail) tidak pernah melihat catatan klinis mentah. Ini menjaga
 * prinsip "minimum necessary information": orang tua menerima kabar bahwa
 * anaknya dilayani, bukan salinan rekam medis.
 */
class ClinicNotification extends Notification
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
        return $this->payload + ['type' => $this->payload['type'] ?? 'clinic.notice'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) ($this->payload['title'] ?? 'Pemberitahuan Klinik'))
            ->line((string) ($this->payload['body'] ?? ''))
            ->action('Buka Portal Kesehatan', url((string) ($this->payload['url'] ?? '/')));
    }
}
