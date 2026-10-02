<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Domain\Contracts\AttendanceProviderInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Presensi belum terpasang (§66): rekap kehadiran kosong, tetapi status pegawai
 * tetap dapat berubah dan permintaan menonaktifkan presensi menunggu di outbox.
 */
class UnavailableAttendanceProvider implements AttendanceProviderInterface
{
    public function summaryFor(int $employeeId, string $fromDate, string $toDate): array
    {
        return ['employee_id' => $employeeId, 'from' => $fromDate, 'to' => $toDate, 'available' => false, 'present' => 0, 'absent' => 0];
    }

    public function deactivateEmployee(int $employeeId, string $fromDate): bool
    {
        return false;
    }

    public function onLeaveOn(string $date): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('employee_attendances');
    }
}
