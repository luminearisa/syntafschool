<?php

namespace App\Modules\Hrd\Workforce\Attendance\Providers;

use App\Modules\Hrd\Core\Domain\Contracts\AttendanceDeviceProviderInterface;

/**
 * Sumber yang diisi manusia: entri manual HR, web clock, dan aplikasi mobile.
 * Muatan yang diterima sudah berbentuk peristiwa; penyedia ini hanya
 * memastikan bentuknya seragam dan menandai bahwa perangkat tidak offline.
 */
class HumanInputAttendanceProvider implements AttendanceDeviceProviderInterface
{
    public function __construct(private readonly string $code) {}

    public function code(): string
    {
        return $this->code;
    }

    public function supportsOffline(): bool
    {
        return $this->code === 'mobile';
    }

    /** @return list<array<string, mixed>> */
    public function normalize(array $raw): array
    {
        $events = isset($raw['employee_id']) ? [$raw] : ($raw['events'] ?? []);

        return array_map(fn (array $event) => [
            'employee_id' => (int) $event['employee_id'],
            'event_type' => strtoupper((string) ($event['event_type'] ?? 'CLOCK_IN')),
            'occurred_at' => $event['occurred_at'] ?? now(),
            'latitude' => $event['latitude'] ?? null,
            'longitude' => $event['longitude'] ?? null,
            'accuracy' => $event['accuracy'] ?? null,
            'location_id' => $event['location_id'] ?? null,
            'device_id' => $event['device_id'] ?? null,
            'is_remote' => (bool) ($event['is_remote'] ?? ($this->code === 'mobile' && ($event['latitude'] ?? null) !== null)),
            'idempotency_key' => $event['idempotency_key'] ?? null,
            'source_reference' => $event['source_reference'] ?? null,
            'raw' => $event,
        ], array_values($events));
    }
}
