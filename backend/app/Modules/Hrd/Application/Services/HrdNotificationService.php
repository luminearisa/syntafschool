<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Hrd\Domain\Contracts\UserAccountProviderInterface;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrNotification;

/**
 * Notifikasi HRD (§55).
 *
 * Urutannya penting: baris internal ditulis **lebih dulu**, lalu penyedia kanal
 * dipanggil. Bila kanal WhatsApp belum terpasang atau surel gagal, peringatan
 * tetap ada di dalam aplikasi — tidak ada tenggat kontrak yang lolos diam-diam.
 *
 * Peristiwa yang didukung sengaja berupa daftar tetap (bukan string bebas), agar
 * modul lain dapat berlangganan tanpa menebak nama peristiwa.
 */
class HrdNotificationService
{
    public const EVENTS = [
        'employee.created', 'employee.activated', 'employee.status_changed', 'employee.terminated',
        'contract.expiring', 'probation.ending', 'document.expiring', 'certification.expiring',
        'transaction.submitted', 'transaction.approved', 'transaction.rejected', 'transaction.applied',
        'onboarding.task_due', 'onboarding.task_overdue', 'onboarding.completed',
        'offboarding.started', 'offboarding.task_due', 'offboarding.clearance_pending',
        'request.submitted', 'request.approved', 'request.rejected', 'request.sla_breach',
        'data_quality.critical',
    ];

    public function __construct(
        private readonly NotificationProviderInterface $provider,
        private readonly UserAccountProviderInterface $users,
        private readonly HrdAuditService $audit,
    ) {}

    /**
     * Kirim notifikasi ke pegawai terkait dan/atau peran tertentu.
     *
     * @param  list<int>  $userIds
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $payload
     */
    public function notify(
        string $event,
        string $title,
        string $body,
        array $userIds = [],
        array $roles = [],
        ?HrEmployee $employee = null,
        array $payload = [],
        string $channel = 'database',
    ): int {
        $recipients = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $recipientRoles = array_values(array_unique($roles));

        // Catat lebih dulu: pengingat tidak boleh hilang bila kanal gagal.
        foreach ($recipients as $userId) {
            $this->record($event, $title, $body, $userId, null, $employee, $payload, $channel);
        }

        foreach ($recipientRoles as $role) {
            foreach ($this->users->usersWithRole($role) as $userId) {
                if (! in_array($userId, $recipients, true)) {
                    $recipients[] = $userId;
                }

                $this->record($event, $title, $body, $userId, $role, $employee, $payload, $channel);
            }
        }

        if ($recipients !== [] || $recipientRoles !== []) {
            $this->provider->notify($recipients, $recipientRoles, $event, ['title' => $title, 'body' => $body] + $payload);
        }

        return count($recipients);
    }

    /** Notifikasi ke HR (peran global) — pengingat tenggat, temuan kualitas data. */
    public function notifyHr(string $event, string $title, string $body, array $payload = [], array $roles = []): int
    {
        $roles = $roles === [] ? (array) config('hrd.integration.notify_roles', ['HR_ADMIN', 'HR_MANAGER']) : $roles;

        return $this->notify($event, $title, $body, [], $roles, null, $payload);
    }

    /** Kotak masuk pegawai (self-service). */
    public function inbox(User $user, int $limit = 30)
    {
        return HrNotification::query()
            ->where('recipient_id', $user->getKey())
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function markRead(User $user, int $notificationId): bool
    {
        return HrNotification::query()
            ->where('recipient_id', $user->getKey())
            ->whereKey($notificationId)
            ->update(['status' => 'read', 'read_at' => now()]) > 0;
    }

    public function unreadCount(User $user): int
    {
        return HrNotification::query()->where('recipient_id', $user->getKey())->unread()->count();
    }

    /** @param array<string, mixed> $payload */
    private function record(string $event, string $title, string $body, ?int $userId, ?string $role, ?HrEmployee $employee, array $payload, string $channel): void
    {
        HrNotification::query()->create([
            'event' => $event,
            'subject_type' => $employee === null ? null : HrEmployee::class,
            'subject_id' => $employee?->getKey(),
            'employee_id' => $employee?->getKey(),
            'recipient_id' => $userId,
            'recipient_role' => $role,
            'channel' => $channel,
            'title' => mb_substr($title, 0, 250),
            'body' => $body,
            'status' => 'sent',
            'sent_at' => now(),
            'meta' => $payload === [] ? null : $payload,
        ]);

        if ($userId !== null) {
            $this->audit->logEvent(
                null,
                'notification.sent',
                HrNotification::class,
                null,
                ['event' => $event, 'recipient_id' => $userId, 'channel' => $channel] + $payload,
            );
        }
    }
}
