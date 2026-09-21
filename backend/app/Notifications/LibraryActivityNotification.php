<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi modul Library.
 *
 * Memakai kanal `database` bawaan Laravel (tidak ada notification engine
 * baru). Jenis notifikasi dibedakan lewat `type` di dalam `data` sehingga
 * job terjadwal dapat memeriksa duplikat dengan mudah.
 */
class LibraryActivityNotification extends Notification
{
    use Queueable;

    public const TYPE_DUE_SOON = 'loan_due_soon';
    public const TYPE_OVERDUE = 'loan_overdue';
    public const TYPE_RESERVATION_AVAILABLE = 'reservation_available';
    public const TYPE_RESERVATION_EXPIRED = 'reservation_expired';
    public const TYPE_MEMBERSHIP_EXPIRING = 'membership_expiring';
    public const TYPE_FINE_CREATED = 'fine_created';

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public array $context = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
        ] + $this->context;
    }
}