<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Jobs;

use App\Modules\SchoolOperations\Competitions\Domain\Contracts\NotificationProviderInterface;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Idempotent notification delivery through the shared Notification provider. */
class SendCompetitionNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $notificationId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function failed(Throwable $exception): void
    {
        CompetitionNotification::query()->whereKey($this->notificationId)->update([
            'status' => 'FAILED',
            'failed_reason' => $exception->getMessage(),
            'updated_at' => now(),
        ]);
    }

    public function handle(NotificationProviderInterface $notifications): void
    {
        $notification = CompetitionNotification::query()->find($this->notificationId);
        if ($notification === null || $notification->status !== 'PENDING') {
            return;
        }
        if (! $notifications->isAvailable()) {
            $notification->forceFill(['status' => 'FAILED', 'failed_reason' => $notifications->unavailableReason()])->save();

            return;
        }
        $recipientIds = $notification->recipient_type === 'USER' && $notification->recipient_id !== null
            ? [(int) $notification->recipient_id]
            : [];
        $result = $notifications->send($recipientIds, [
            'event' => $notification->notification_event,
            'channel' => $notification->channel,
            'subject' => $notification->subject,
            'body' => $notification->body,
            'payload' => $notification->payload,
            'dedupe_key' => $notification->idempotency_key,
        ]);
        $sent = (int) ($result['sent'] ?? 0) > 0;
        $notification->forceFill([
            'status' => $sent ? 'SENT' : 'FAILED',
            'provider_reference' => $result['reference'] ?? null,
            'sent_at' => $sent ? now() : null,
            'failed_reason' => $sent ? null : ($result['reason'] ?? 'Provider tidak mengonfirmasi pengiriman.'),
        ])->save();
    }
}
