<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi modul PPDB.
 *
 * Memakai kanal `database` bawaan Laravel (sistem notifikasi existing) —
 * tidak ada notification engine baru.
 */
class PpdbActivityNotification extends Notification
{
    use Queueable;

    public const TYPE_ACCOUNT_CREATED = 'account_created';
    public const TYPE_REGISTRATION_SUBMITTED = 'registration_submitted';
    public const TYPE_DOCUMENT_REJECTED = 'document_rejected';
    public const TYPE_REVISION_REQUESTED = 'revision_requested';
    public const TYPE_REGISTRATION_VERIFIED = 'registration_verified';
    public const TYPE_REGISTRATION_CANCELLED = 'registration_cancelled';
    public const TYPE_PAYMENT_PAID = 'payment_paid';
    public const TYPE_PAYMENT_FAILED = 'payment_failed';
    public const TYPE_PAYMENT_REMINDER = 'payment_reminder';
    public const TYPE_SELECTION_SCHEDULED = 'selection_scheduled';
    public const TYPE_SELECTION_RESULT = 'selection_result';
    public const TYPE_RE_REGISTRATION_OPEN = 're_registration_open';
    public const TYPE_ANNOUNCEMENT = 'announcement';
    public const TYPE_APPEAL_SUBMITTED = 'appeal_submitted';
    public const TYPE_APPEAL_DECIDED = 'appeal_decided';

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