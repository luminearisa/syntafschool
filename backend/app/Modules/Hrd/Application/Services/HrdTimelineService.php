<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeTimelineEvent;
use Illuminate\Support\Collection;

/**
 * Linimasa pegawai (§24).
 *
 * Satu riwayat untuk seluruh peristiwa kepegawaian: masuk kerja, onboarding
 * selesai, transfer, promosi, sertifikat baru, kontrak baru. Baris di sini
 * **diturunkan dari peristiwa domain** — bukan diketik manual — sehingga tidak ada
 * dua riwayat yang dapat saling bertentangan.
 *
 * `dedupe_key` membuat pencatatan idempoten: listener yang dijalankan ulang oleh
 * antrean, atau aksi yang dipanggil dua kali, tidak menghasilkan baris ganda.
 */
class HrdTimelineService
{
    public function __construct(private readonly HrdAuditService $audit) {}

    /**
     * Catat satu peristiwa linimasa.
     *
     * @param  array<string, mixed>  $meta
     */
    public function record(
        HrEmployee|int $employee,
        string $eventCode,
        string $title,
        array $options = [],
    ): ?HrEmployeeTimelineEvent {
        $employeeId = $employee instanceof HrEmployee ? (int) $employee->getKey() : $employee;

        $dedupeKey = $options['dedupe_key'] ?? $this->buildDedupeKey($employeeId, $eventCode, $options['source_id'] ?? null);

        if ($dedupeKey !== null) {
            $existing = HrEmployeeTimelineEvent::query()->where('dedupe_key', $dedupeKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $event = HrEmployeeTimelineEvent::query()->create([
            'employee_id' => $employeeId,
            'event_code' => $eventCode,
            'category' => $options['category'] ?? $this->categoryFor($eventCode),
            'title' => $title,
            'description' => $options['description'] ?? null,
            'occurred_at' => $options['occurred_at'] ?? now(),
            'effective_date' => $options['effective_date'] ?? null,
            'source_type' => $options['source_type'] ?? null,
            'source_id' => $options['source_id'] ?? null,
            'visibility' => $options['visibility'] ?? 'hr',
            'icon' => $options['icon'] ?? $this->iconFor($eventCode),
            'meta' => $options['meta'] ?? null,
            'actor_id' => isset($options['actor']) && $options['actor'] instanceof User ? $options['actor']->getKey() : null,
            'dedupe_key' => $dedupeKey,
        ]);

        $this->audit->logEvent(
            $options['actor'] ?? null,
            'timeline.recorded',
            HrEmployeeTimelineEvent::class,
            $event->getKey(),
            ['event_code' => $eventCode, 'employee_id' => $employeeId],
        );

        return $event;
    }

    /**
     * Linimasa seorang pegawai.
     *
     * @return Collection<int, HrEmployeeTimelineEvent>
     */
    public function forEmployee(HrEmployee|int $employee, string $visibilityLevel = 'hr', ?string $fromDate = null, ?string $toDate = null): Collection
    {
        $employeeId = $employee instanceof HrEmployee ? (int) $employee->getKey() : $employee;

        return HrEmployeeTimelineEvent::query()
            ->where('employee_id', $employeeId)
            ->visibleTo($visibilityLevel)
            ->when($fromDate !== null, fn ($q) => $q->where('occurred_at', '>=', $fromDate))
            ->when($toDate !== null, fn ($q) => $q->where('occurred_at', '<=', $toDate.' 23:59:59'))
            ->chronological()
            ->get();
    }

    /** Linimasa unit: peristiwa dari seluruh pegawai dalam satu unit (§43). */
    public function forOrganization(int $organizationId, string $visibilityLevel = 'hr', int $limit = 100): Collection
    {
        return HrEmployeeTimelineEvent::query()
            ->with(['employee.person:id,full_name', 'employee:id,employee_number,person_id'])
            ->whereIn('employee_id', HrEmployee::query()->where('organization_id', $organizationId)->select('id'))
            ->visibleTo($visibilityLevel)
            ->chronological()
            ->limit($limit)
            ->get();
    }

    /** Peristiwa pada rentang tanggal untuk kalender HR (§71). */
    public function between(string $fromDate, string $toDate, ?int $organizationId = null): Collection
    {
        return HrEmployeeTimelineEvent::query()
            ->with(['employee.person:id,full_name'])
            ->whereBetween('occurred_at', [$fromDate.' 00:00:00', $toDate.' 23:59:59'])
            ->when($organizationId !== null, fn ($q) => $q->whereIn('employee_id', HrEmployee::query()->where('organization_id', $organizationId)->select('id')))
            ->orderBy('occurred_at')
            ->get();
    }

    private function buildDedupeKey(int $employeeId, string $eventCode, mixed $sourceId): ?string
    {
        if ($sourceId === null) {
            return null;
        }

        return "{$employeeId}:{$eventCode}:{$sourceId}";
    }

    private function categoryFor(string $eventCode): string
    {
        return match (true) {
            str_starts_with($eventCode, 'employee.') => 'lifecycle',
            str_starts_with($eventCode, 'assignment.'), str_starts_with($eventCode, 'transfer.') => 'assignment',
            str_starts_with($eventCode, 'employment.'), str_starts_with($eventCode, 'contract.'), str_starts_with($eventCode, 'probation.') => 'employment',
            str_starts_with($eventCode, 'document.') => 'document',
            str_starts_with($eventCode, 'skill.') => 'skill',
            str_starts_with($eventCode, 'certification.') => 'certification',
            str_starts_with($eventCode, 'education.') => 'education',
            str_starts_with($eventCode, 'request.') => 'request',
            str_starts_with($eventCode, 'onboarding.'), str_starts_with($eventCode, 'offboarding.') => 'lifecycle',
            default => 'system',
        };
    }

    private function iconFor(string $eventCode): string
    {
        return match (true) {
            str_contains($eventCode, 'created'), str_contains($eventCode, 'hired') => 'user-plus',
            str_contains($eventCode, 'promoted') => 'trending-up',
            str_contains($eventCode, 'transferred'), str_contains($eventCode, 'mutated') => 'move-right',
            str_contains($eventCode, 'document') => 'file-text',
            str_contains($eventCode, 'certification') => 'award',
            str_contains($eventCode, 'contract') => 'file-signature',
            str_contains($eventCode, 'terminated'), str_contains($eventCode, 'resigned') => 'user-minus',
            str_contains($eventCode, 'verified') => 'shield-check',
            default => 'activity',
        };
    }
}
