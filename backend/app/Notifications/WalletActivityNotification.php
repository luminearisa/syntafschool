<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi in-app modul wallet (kanal `database`, sama seperti modul lain).
 *
 * `type` dipakai frontend untuk memilih ikon/tautan dan untuk mencegah dobel
 * kirim (`WalletNotificationService::alreadySent()`).
 */
class WalletActivityNotification extends Notification
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
            'wallet_id' => $this->context['wallet_id'] ?? null,
            'reference_type' => $this->context['reference_type'] ?? null,
            'reference_id' => $this->context['reference_id'] ?? null,
            'amount' => $this->context['amount'] ?? null,
            'route' => $this->context['route'] ?? null,
        ];
    }
}
