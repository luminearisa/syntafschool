<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Kontrak Cuti — dipakai modul ERP lain dan layanan lain di dalam HRD.
 *
 * Pemanggil tidak boleh membaca tabel HRD secara langsung: ia bergantung pada
 * antarmuka ini, sehingga HRD tetap menjadi satu-satunya tempat data diubah dan
 * penggantian implementasi (termasuk untuk pengujian) tidak menyentuh pemanggil.
 */
interface LeaveProviderInterface
{
    /**
     * Saldo cuti yang dapat dipakai.
     */
    public function availableBalance(int $employeeId, string $leaveTypeCode, ?string $asOf = null): float;

    /**
     * Mengajukan cuti.
     *
     * @return array<string, mixed>
     */
    public function requestLeave(int $employeeId, array $payload, ?int $actorUserId = null): array;

    /**
     * Menyetujui cuti.
     *
     * @return array<string, mixed>
     */
    public function approve(int $requestId, int $actorUserId, ?string $notes = null): array;

    /**
     * Hari cuti pada rentang tertentu.
     *
     * @return array<string, mixed>
     */
    public function leaveDaysInPeriod(int $employeeId, string $from, string $to): array;

    /**
     * Hari cuti tanpa upah untuk payroll.
     */
    public function unpaidLeaveDays(int $employeeId, string $from, string $to): float;
}
