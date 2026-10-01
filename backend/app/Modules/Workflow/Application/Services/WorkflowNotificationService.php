<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Workflow\Domain\Enums\NotificationEvent;
use App\Modules\Workflow\Domain\Enums\NotificationStatus;
use App\Modules\Workflow\Domain\Support\NotificationMessage;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowNotification;
use App\Modules\Workflow\Models\WorkflowStepInstance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Notifikasi workflow dengan jaminan **idempotensi** (§67, §68).
 *
 * Kunci idempoten dibentuk dari (kejadian, instance, step, penerima, penanda
 * tambahan seperti tingkat pengingat). Baris dengan kunci sama hanya boleh ada
 * satu — karena kolomnya unik, pengiriman ulang akibat retry webhook, job, atau
 * tombol yang ditekan dua kali akan tercatat sebagai `SKIPPED`, bukan terkirim
 * dua kali.
 */
class WorkflowNotificationService
{
    public function __construct(
        private readonly NotificationProviderInterface $provider,
    ) {
    }

    /**
     * Kirim satu kejadian ke banyak penerima.
     *
     * @param  list<User|int>  $recipients
     * @param  array<string, mixed>  $payload
     * @param  list<string>|null  $channels
     * @return list<WorkflowNotification>
     */
    public function dispatch(
        NotificationEvent $event,
        WorkflowInstance $instance,
        array $recipients,
        string $title,
        string $body,
        array $payload = [],
        ?WorkflowStepInstance $step = null,
        ?array $channels = null,
        string $keySuffix = '',
        ?string $actionUrl = null,
    ): array {
        $channels = $channels !== null && $channels !== []
            ? array_values(array_map('strtoupper', $channels))
            : array_values(array_map('strtoupper', (array) config('workflow.notifications.channels', ['IN_APP'])));

        $created = [];

        foreach ($recipients as $recipient) {
            $user = $recipient instanceof User
                ? $recipient
                : User::query()->find((int) $recipient);

            if ($user === null || $user->is_active === false) {
                continue;
            }

            foreach ($channels as $channel) {
                $created[] = $this->send(
                    $event,
                    $channel,
                    $user,
                    $title,
                    $body,
                    $instance,
                    $step,
                    $payload,
                    $keySuffix,
                    $actionUrl,
                );
            }
        }

        return $created;
    }

    public function send(
        NotificationEvent $event,
        string $channel,
        User $recipient,
        string $title,
        string $body,
        ?WorkflowInstance $instance = null,
        ?WorkflowStepInstance $step = null,
        array $payload = [],
        string $keySuffix = '',
        ?string $actionUrl = null,
    ): WorkflowNotification {
        $channel = strtoupper($channel);
        $key = $this->idempotencyKey($event, $instance, $step, $recipient, $channel, $keySuffix);

        // Idempotensi ditegakkan di basis data (unique index), bukan hanya di
        // memori: dua worker paralel pun tidak bisa mengirim dua kali.
        try {
            $notification = WorkflowNotification::query()->create([
                'instance_id' => $instance?->id,
                'step_instance_id' => $step?->id,
                'event' => $event->value,
                'channel' => $channel,
                'recipient_id' => $recipient->id,
                'recipient_address' => $channel === 'EMAIL' ? $recipient->email : $recipient->phone,
                'payload' => $payload + [
                    'title' => $title,
                    'body' => $body,
                    'instance_code' => $instance?->code,
                    'action_url' => $actionUrl,
                ],
                'idempotency_key' => $key,
                'status' => NotificationStatus::PENDING->value,
            ]);
        } catch (QueryException) {
            $notification = WorkflowNotification::query()->where('idempotency_key', $key)->firstOrFail();
            $notification->forceFill([
                'status' => NotificationStatus::SKIPPED->value,
                'error' => 'Duplikat dicegah oleh idempotency key.',
            ])->save();

            return $notification;
        }

        if (! $this->provider->supports($channel)) {
            $notification->forceFill([
                'status' => NotificationStatus::FAILED->value,
                'attempts' => 1,
                'failed_at' => now(),
                'error' => "Kanal {$channel} tidak didukung provider notifikasi.",
            ])->save();

            return $notification;
        }

        $message = new NotificationMessage(
            event: $event->value,
            channel: $channel,
            recipient: $recipient,
            title: $title,
            body: $body,
            payload: $payload,
            idempotencyKey: $key,
            actionUrl: $actionUrl,
        );

        try {
            $sent = $this->provider->send($message);

            $notification->forceFill([
                'status' => $sent ? NotificationStatus::SENT->value : NotificationStatus::FAILED->value,
                'attempts' => 1,
                'sent_at' => $sent ? now() : null,
                'failed_at' => $sent ? null : now(),
                'error' => $sent ? null : 'Provider menolak pengiriman.',
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('workflow.notification.failed', [
                'event' => $event->value,
                'recipient' => $recipient->id,
                'error' => $e->getMessage(),
            ]);

            $notification->forceFill([
                'status' => NotificationStatus::FAILED->value,
                'attempts' => 1,
                'failed_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 290),
            ])->save();
        }

        return $notification;
    }

    /** Kunci idempoten notifikasi (§68). */
    public function idempotencyKey(
        NotificationEvent $event,
        ?WorkflowInstance $instance,
        ?WorkflowStepInstance $step,
        User $recipient,
        string $channel,
        string $suffix = '',
    ): string {
        return hash('sha256', implode('|', [
            $event->value,
            (string) ($instance?->id ?? 0),
            (string) ($step?->id ?? 0),
            (string) $recipient->id,
            strtoupper($channel),
            $suffix,
        ]));
    }

    /**
     * Penerima default untuk sebuah instance: requester, approver aktif, dan
     * para pengamat (observer) yang didefinisikan versi workflow.
     *
     * @return list<int>
     */
    public function defaultRecipients(WorkflowInstance $instance, bool $includeApprovers = true): array
    {
        $ids = [(int) $instance->requester_id];

        if ($includeApprovers) {
            $ids = array_merge(
                $ids,
                $instance->approvals()->where('status', 'PENDING')->pluck('approver_id')->all(),
            );
        }

        $observers = (array) (($instance->version?->config['observers'] ?? $instance->definition?->observers ?? []));

        foreach ($observers as $observer) {
            if (is_numeric($observer)) {
                $ids[] = (int) $observer;
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
