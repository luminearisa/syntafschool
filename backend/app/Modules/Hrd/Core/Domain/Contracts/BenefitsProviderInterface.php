<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Kontrak Benefit — dipakai modul ERP lain dan layanan lain di dalam HRD.
 *
 * Pemanggil tidak boleh membaca tabel HRD secara langsung: ia bergantung pada
 * antarmuka ini, sehingga HRD tetap menjadi satu-satunya tempat data diubah dan
 * penggantian implementasi (termasuk untuk pengujian) tidak menyentuh pemanggil.
 */
interface BenefitsProviderInterface
{
    /**
     * Rencana benefit yang layak bagi pegawai.
     *
     * @return array<string, mixed>
     */
    public function eligiblePlans(int $employeeId): array;

    /**
     * Mendaftarkan pegawai pada benefit.
     *
     * @return array<string, mixed>
     */
    public function enroll(int $employeeId, int $planId, array $payload, ?int $actorUserId = null): array;

    /**
     * Benefit aktif yang berjalan.
     *
     * @return array<string, mixed>
     */
    public function activeEnrollments(int $employeeId): array;

    /**
     * Potongan benefit bulan berjalan.
     */
    public function monthlyDeduction(int $employeeId, string $periodCode): float;
}
