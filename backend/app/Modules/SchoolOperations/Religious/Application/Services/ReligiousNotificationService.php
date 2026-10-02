<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\NotificationGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;

/**
 * Notifikasi keagamaan (§64, §86): pengingat shalat, iqamah, kajian,
 * Jumat, tugas, Ramadan, dan perubahan/pembatalan jadwal.
 */
class ReligiousNotificationService
{
    public function __construct(
        protected ReligiousContext $context,
        protected NotificationGatewayInterface $notifications,
    ) {
    }

    /** Kirim pengingat berdasarkan jenis (§86). */
    public function remind(string $type, array $payload = []): array
    {
        $definition = (array) config('religious.notifications.types.'.strtoupper($type), []);

        if ($definition === []) {
            return ['sent' => false, 'reason' => 'Jenis notifikasi tidak dikenal: '.$type];
        }

        $roles = $payload['roles'] ?? ($definition['roles'] ?? []);
        $message = $this->compose($type, $definition, $payload);

        $result = $roles !== []
            ? $this->notifications->sendToRoles($roles, $message)
            : $this->notifications->send($message);

        $this->context->audit('religious.notification.sent', null, [
            'type' => strtoupper($type), 'result' => $result,
        ]);

        return [
            'type' => strtoupper($type),
            'message' => $message,
            'result' => $result,
            'provider_available' => $this->notifications->available(),
            'reason' => $this->notifications->unavailableReason(),
        ];
    }

    protected function compose(string $type, array $definition, array $payload): array
    {
        $template = (string) ($definition['template'] ?? 'Notifikasi keagamaan');
        $replacements = [
            ':prayer' => $payload['prayer_type'] ?? '-',
            ':time' => $payload['time'] ?? '-',
            ':mosque' => $payload['mosque'] ?? '-',
            ':title' => $payload['title'] ?? '-',
            ':date' => $payload['date'] ?? now()->toDateString(),
            ':activity' => $payload['activity'] ?? '-',
        ];

        return [
            'title' => (string) ($definition['title'] ?? 'Pengingat Keagamaan'),
            'body' => strtr($template, $replacements),
            'message' => strtr($template, $replacements),
            'type' => 'RELIGIOUS_'.strtoupper($type),
            'priority' => $definition['priority'] ?? 'NORMAL',
            'user_ids' => $payload['user_ids'] ?? [],
            'data' => $payload,
            'schedule_at' => $payload['schedule_at'] ?? null,
        ];
    }

    /** Pengingat iqamah terjadwal (§64). */
    public function scheduleIqamahReminder(array $payload): array
    {
        return $this->notifications->schedule([
            'title' => 'Pengingat Iqamah',
            'body' => 'Iqamah '.($payload['prayer_type'] ?? '').' pukul '.($payload['iqamah_time'] ?? '').'.',
            'type' => 'RELIGIOUS_IQAMAH',
            'schedule_at' => $payload['schedule_at'] ?? null,
            'data' => $payload,
        ]);
    }

    /** Beri tahu perubahan/pembatalan jadwal (§86). */
    public function notifyScheduleChange(array $payload): array
    {
        return $this->remind('SCHEDULE_CHANGE', $payload);
    }

    /** Beri tahu penugasan personel (§64: Duty Assignment). */
    public function notifyDutyAssignment(array $payload): array
    {
        return $this->remind('DUTY_ASSIGNMENT', $payload);
    }

    public function capabilities(): array
    {
        return [
            'available' => $this->notifications->available(),
            'reason' => $this->notifications->unavailableReason(),
            'types' => array_keys((array) config('religious.notifications.types', [])),
        ];
    }
}
