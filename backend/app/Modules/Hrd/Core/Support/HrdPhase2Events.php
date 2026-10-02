<?php

namespace App\Modules\Hrd\Core\Support;

use App\Models\User;
use App\Modules\Hrd\Application\Services\HrdAuditService;
use App\Modules\Hrd\Application\Support\IntegrationOutbox;
use App\Modules\Hrd\Core\Models\HrWorkforceEvent;
use Throwable;

/**
 * Satu pintu keluar untuk seluruh peristiwa Phase 2.
 *
 * Setiap perubahan penting menulis tiga hal sekaligus: baris peristiwa yang
 * dibaca Employee 360, jejak audit yang tidak dapat diubah, dan pesan outbox
 * bagi modul lain. Jejak audit dibiarkan menggagalkan transaksi bila gagal
 * (integritas lebih penting daripada kenyamanan), sedangkan outbox tidak:
 * modul tujuan yang belum terpasang tidak boleh membatalkan pekerjaan HR.
 */
class HrdPhase2Events
{
    private const MODULE_MAP = [
        'RECRUITMENT' => 'recruitment',
        'WORKFORCE' => 'attendance',
        'LEAVE' => 'leave',
        'PAYROLL' => 'payroll',
        'PERFORMANCE' => 'performance',
        'LEARNING' => 'lms',
        'CAREER' => 'career',
        'TALENT' => 'talent',
        'ENGAGEMENT' => 'engagement',
        'GOVERNANCE' => 'hrd',
    ];

    public function __construct(
        private readonly HrdAuditService $audit,
        private readonly IntegrationOutbox $outbox,
    ) {}

    /** @param array<string, mixed> $data */
    public function emit(string $category, string $eventType, array $data = []): HrWorkforceEvent
    {
        /** @var User|null $actor */
        $actor = $data['actor'] ?? null;

        $event = HrWorkforceEvent::query()->create([
            'employee_id' => $data['employee_id'] ?? null,
            'event_type' => $eventType,
            'event_category' => $category,
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? now(),
            'title' => $data['title'] ?? $eventType,
            'description' => $data['description'] ?? null,
            'payload' => $data['payload'] ?? null,
            'actor_user_id' => $actor?->getKey(),
            'visible_to_employee' => $data['visible_to_employee'] ?? true,
        ]);

        $this->audit->logEvent($actor, $eventType, HrWorkforceEvent::class, $event->getKey(), [
            'category' => $category,
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'employee_id' => $data['employee_id'] ?? null,
            'payload' => $data['payload'] ?? null,
        ], $data['reason'] ?? null);

        $this->publish($category, $eventType, $event, $data);

        return $event;
    }

    /** @param array<string, mixed> $data */
    private function publish(string $category, string $eventType, HrWorkforceEvent $event, array $data): void
    {
        try {
            $this->outbox->push(
                self::MODULE_MAP[$category] ?? 'hrd',
                strtolower($category).'.'.$eventType,
                [
                    'employee_id' => $event->employee_id,
                    'event_id' => $event->getKey(),
                    'title' => $event->title,
                    'payload' => $data['payload'] ?? null,
                ],
                HrWorkforceEvent::class,
                $event->getKey(),
            );
        } catch (Throwable $exception) {
            // Outbox adalah jembatan, bukan syarat: kegagalan pengiriman dicatat
            // tetapi tidak membatalkan transaksi HR.
            report($exception);
        }
    }
}
