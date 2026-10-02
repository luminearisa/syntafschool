<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Kontrak Presensi — dipakai modul ERP lain dan layanan lain di dalam HRD.
 *
 * Pemanggil tidak boleh membaca tabel HRD secara langsung: ia bergantung pada
 * antarmuka ini, sehingga HRD tetap menjadi satu-satunya tempat data diubah dan
 * penggantian implementasi (termasuk untuk pengujian) tidak menyentuh pemanggil.
 */
interface AttendanceProviderInterface
{
    /**
     * Mencatat jam masuk dari sumber mana pun.
     *
     * @return array<string, mixed>
     */
    public function recordClockIn(int $employeeId, array $payload): array;

    /**
     * Mencatat jam keluar.
     *
     * @return array<string, mixed>
     */
    public function recordClockOut(int $employeeId, array $payload): array;

    /**
     * Rekap kehadiran untuk payroll dan analitik.
     *
     * @return array<string, mixed>
     */
    public function attendanceSummary(int $employeeId, string $from, string $to): array;

    /**
     * Hari tidak hadir yang dapat dipotong payroll.
     */
    public function absenceDays(int $employeeId, string $from, string $to): float;

    /**
     * Total menit keterlambatan.
     */
    public function lateMinutes(int $employeeId, string $from, string $to): int;

    /**
     * Jam lembur terverifikasi.
     */
    public function overtimeHours(int $employeeId, string $from, string $to): float;
}
