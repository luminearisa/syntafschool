<?php

namespace App\Modules\LMS\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi generik LMS (§10).
 *
 * Satu kelas untuk seluruh peristiwa pembelajaran: isi notifikasi dibentuk
 * pemanggil (`LmsNotifier`) sehingga menambah peristiwa baru tidak berarti
 * menambah kelas baru — cukup satu entri pada `NotificationEvent`.
 *
 * Kanal mengikuti kebiasaan modul lain: `database` selalu ada, `mail` hanya
 * bila kanal diminta dan akun memiliki alamat email.
 */
class LmsEventNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $channels  kanal Laravel (`database`, `mail`)
     */
    public function __construct(
        public string $event,
        public string $title,
        public string $body,
        public ?string $url = null,
        public array $context = [],
        public array $channels = ['database'],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (in_array('mail', $this->channels, true) && filled($notifiable->email ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title)
            ->greeting('Yth. '.($notifiable->name ?? 'Pengguna LMS').',')
            ->line($this->body);

        if ($this->url !== null) {
            $message->action('Buka LMS', $this->url);
        }

        return $message->salutation('Salam, Tim LMS');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'context' => $this->context,
        ];
    }
}
