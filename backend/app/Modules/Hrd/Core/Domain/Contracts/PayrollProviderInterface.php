<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Kontrak Payroll — dipakai modul ERP lain dan layanan lain di dalam HRD.
 *
 * Pemanggil tidak boleh membaca tabel HRD secara langsung: ia bergantung pada
 * antarmuka ini, sehingga HRD tetap menjadi satu-satunya tempat data diubah dan
 * penggantian implementasi (termasuk untuk pengujian) tidak menyentuh pemanggil.
 */
interface PayrollProviderInterface
{
    /**
     * Menjalankan penggajian satu periode.
     *
     * @return array<string, mixed>
     */
    public function runPeriod(array $payload, ?int $actorUserId = null): array;

    /**
     * Mengunci penggajian menjadi snapshot.
     *
     * @return array<string, mixed>
     */
    public function finalizeRun(int $runId, int $actorUserId): array;

    /**
     * Upah bersih yang dibayarkan pada periode.
     */
    public function netPay(int $employeeId, string $periodCode): ?float;

    /**
     * Berkas slip gaji (dokumen privat).
     */
    public function payslipPath(int $employeeId, string $periodCode): ?string;

    /**
     * Mengirim jurnal payroll ke Finance.
     *
     * @return array<string, mixed>
     */
    public function postToFinance(int $runId, int $actorUserId): array;
}
