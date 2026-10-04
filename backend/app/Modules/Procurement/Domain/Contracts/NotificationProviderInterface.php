<?php

namespace App\Modules\Procurement\Domain\Contracts;

/**
 * Notification — pengiriman pemberitahuan lintas kanal (in-app, email, dsb.).
 */
interface NotificationProviderInterface
{
    /**
     * Kirim pemberitahuan. Implementasi wajib tidak melempar exception ke
     * pemanggil: kegagalan notifikasi tidak boleh menggagalkan transaksi bisnis.
     *
     * @param  array<string, mixed>  $payload  {event, title, body, recipients, channel, url}
     */
    public function notify(array $payload): void;
}
