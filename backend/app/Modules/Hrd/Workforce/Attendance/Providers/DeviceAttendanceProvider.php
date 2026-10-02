<?php

namespace App\Modules\Hrd\Workforce\Attendance\Providers;

use App\Modules\Hrd\Core\Domain\Contracts\AttendanceDeviceProviderInterface;

/**
 * Perangkat keras dan pemindai: QR, NFC, RFID, sidik jari, wajah, dan API
 * perangkat. Perangkat biasanya mengirim nomor pegawai perangkat, bukan id
 * internal, serta menandai arah (masuk/keluar) pada field tersendiri.
 *
 * Normalisasi idempotensi: perangkat yang menyinkronkan ulang berkas yang sama
 * mengirim `device_event_id` yang sama, sehingga tidak ada presensi ganda.
 */
class DeviceAttendanceProvider implements AttendanceDeviceProviderInterface
{
    public function __construct(private readonly string $code) {}

    public function code(): string
    {
        return $this->code;
    }

    public function supportsOffline(): bool
    {
        return true;
    }

    /** @return list<array<string, mixed>> */
    public function normalize(array $raw): array
    {
        $rows = $raw['events'] ?? $raw['records'] ?? [$raw];

        return array_map(function (array $row) {
            $employeeId = $row['employee_id'] ?? $row['user_id'] ?? $row['pin'] ?? null;
            $direction = strtoupper((string) ($row['direction'] ?? $row['event_type'] ?? 'CLOCK_IN'));

            return [
                'employee_id' => (int) $employeeId,
                'event_type' => str_contains($direction, 'OUT') ? 'CLOCK_OUT' : 'CLOCK_IN',
                'occurred_at' => $row['occurred_at'] ?? $row['timestamp'] ?? now(),
                'device_id' => $row['device_id'] ?? null,
                'location_id' => $row['location_id'] ?? null,
                'latitude' => $row['latitude'] ?? null,
                'longitude' => $row['longitude'] ?? null,
                'accuracy' => $row['accuracy'] ?? null,
                'idempotency_key' => $row['device_event_id'] ?? $row['idempotency_key'] ?? null,
                'source_reference' => $row['source_reference'] ?? ($this->code.':'.($row['device_id'] ?? 'unknown')),
                'raw' => $row,
            ];
        }, array_values($rows));
    }
}
