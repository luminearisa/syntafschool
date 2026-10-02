<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\{NotificationGatewayInterface, ParentGatewayInterface};
use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Models\TransportationNotification;

/**
 * Notifikasi transportasi (§39/§86): mencatat setiap pengiriman, mendelegasikan
 * pengiriman nyata ke penyedia notifikasi yang ada, dan bersifat idempoten
 * sehingga event ganda tidak menghasilkan notifikasi ganda.
 */
class TransportationNotificationService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly NotificationGatewayInterface $gateway,
        private readonly ParentGatewayInterface $parents,
    ) {
    }

    /** Kirim notifikasi kepada daftar pengguna dan catat jejaknya. */
    public function dispatch(
        string $event,
        array $recipientUserIds,
        string $title,
        string $body,
        array $options = []
    ): array {
        $key = $options['idempotency_key'] ?? null;
        $channel = strtoupper($options['channel'] ?? 'IN_APP');

        $outcome = $this->context->idempotent('NOTIFICATION', $key, function () use ($event, $recipientUserIds, $title, $body, $options, $channel): array {
            $record = TransportationNotification::query()->create([
                'event_type' => $event,
                'audience' => strtoupper($options['audience'] ?? 'PARENT'),
                'recipient_user_id' => $recipientUserIds[0] ?? null,
                'student_id' => $options['student_id'] ?? null,
                'trip_id' => $options['trip_id'] ?? null,
                'incident_id' => $options['incident_id'] ?? null,
                'channel' => $channel,
                'title' => $title,
                'body' => $body,
                'payload' => $options['payload'] ?? null,
                'status' => 'PENDING',
            ]);

            $result = ['sent' => 0, 'provider' => 'none'];
            if (in_array($channel, ['IN_APP', 'PUSH'], true)) {
                $result = $this->gateway->send($recipientUserIds, [
                    'type' => 'transportation',
                    'event' => $event,
                    'title' => $title,
                    'body' => $body,
                    'payload' => $options['payload'] ?? [],
                ]);
            }

            $sent = (int) ($result['sent'] ?? 0);
            $record->forceFill([
                'status' => $sent > 0 ? 'SENT' : 'SKIPPED',
                'provider_reference' => $result['provider'] ?? null,
                'sent_at' => $sent > 0 ? now() : null,
            ])->save();

            $this->context->publish($event, $record, [
                'audience' => $record->audience,
                'recipients' => count($recipientUserIds),
                'channel' => $channel,
            ]);

            return [
                'notification_id' => $record->id,
                'status' => $record->status,
                'sent' => $sent,
                'channel' => $channel,
                'recipients' => count($recipientUserIds),
            ];
        }, [
            'source' => 'NOTIFICATION',
            'external_reference' => $options['external_reference'] ?? null,
        ]);

        return ($outcome['result'] ?? []) + ['duplicate' => (bool) ($outcome['duplicate'] ?? false)];
    }

    /** Notifikasi ke wali siswa (kanal sesuai preferensi; bawaan IN_APP). */
    public function toGuardians(string $event, int $studentId, string $title, string $body, array $options = []): array
    {
        $userIds = $this->parents->userIdsFor($studentId);
        $options['student_id'] = $studentId;

        if ($userIds === []) {
            // Tetap dicatat agar sekolah dapat menindaklanjuti (mis. wali tanpa akun aplikasi).
            $record = TransportationNotification::query()->create([
                'event_type' => $event,
                'audience' => 'PARENT',
                'student_id' => $studentId,
                'trip_id' => $options['trip_id'] ?? null,
                'incident_id' => $options['incident_id'] ?? null,
                'channel' => strtoupper($options['channel'] ?? 'IN_APP'),
                'title' => $title,
                'body' => $body,
                'payload' => $options['payload'] ?? null,
                'status' => 'SKIPPED',
                'failure_reason' => 'Wali belum memiliki akun pengguna.',
            ]);

            return ['notification_id' => $record->id, 'status' => 'SKIPPED', 'sent' => 0, 'channel' => $record->channel,
                'reason' => 'Wali belum memiliki akun pengguna.'];
        }

        return $this->dispatch($event, $userIds, $title, $body, $options);
    }

    /** Notifikasi operasional untuk admin/dispatcher. */
    public function toAdmins(string $event, string $title, string $body, array $options = []): array
    {
        $recipients = array_values(array_filter(array_map('intval', $options['recipients'] ?? [])));

        return $this->dispatch($event, $recipients, $title, $body, $options + ['audience' => 'ADMIN']);
    }
}
