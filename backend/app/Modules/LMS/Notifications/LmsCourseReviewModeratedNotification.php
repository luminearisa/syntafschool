<?php

namespace App\Modules\LMS\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Hasil moderasi ulasan course (§31) — alasan penolakan ikut disertakan. */
class LmsCourseReviewModeratedNotification extends Notification
{
    use Queueable;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly array $payload,
        public readonly array $channels = ['database'],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->payload + ['type' => 'lms.review.moderated'];
    }
}
