<?php

namespace App\Modules\Workflow\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi in-app/email untuk peristiwa workflow.
 *
 * Sengaja membawa data minimal (kode request, judul, tautan) supaya lonceng
 * notifikasi tidak menjadi jalan pintas membaca dokumen yang tidak berhak
 * diakses penerimanya.
 */
class WorkflowAlertNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $payload = [],
        public readonly string $event = 'WORKFLOW',
        public readonly ?string $actionUrl = null,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'event' => $this->event,
            'action_url' => $this->actionUrl,
            'instance_code' => $this->payload['instance_code'] ?? null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject($this->title)
            ->line($this->body);

        if ($this->actionUrl !== null) {
            $mail->action('Buka di Syntaf School', $this->actionUrl);
        }

        return $mail;
    }
}
