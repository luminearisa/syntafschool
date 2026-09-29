<?php

namespace App\Modules\LMS\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Keputusan banding nilai diberitahukan ke siswa (§20, §46). */
class LmsGradeReviewDecidedNotification extends Notification
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
        return $this->payload + ['type' => 'lms.grade.review_decided'];
    }
}
