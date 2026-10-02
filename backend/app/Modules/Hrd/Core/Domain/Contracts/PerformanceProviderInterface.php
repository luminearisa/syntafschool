<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Kontrak Kinerja — dipakai modul ERP lain dan layanan lain di dalam HRD.
 *
 * Pemanggil tidak boleh membaca tabel HRD secara langsung: ia bergantung pada
 * antarmuka ini, sehingga HRD tetap menjadi satu-satunya tempat data diubah dan
 * penggantian implementasi (termasuk untuk pengujian) tidak menyentuh pemanggil.
 */
interface PerformanceProviderInterface
{
    /**
     * Rating terakhir yang telah difinalkan.
     *
     * @return array<string, mixed>
     */
    public function latestRating(int $employeeId, ?string $cycleCode = null): ?array;

    /**
     * Sebaran rating untuk kalibrasi.
     *
     * @return array<string, mixed>
     */
    public function ratingDistribution(string $cycleCode, ?int $organizationId = null): array;

    /**
     * Kemajuan sasaran pegawai.
     *
     * @return array<string, mixed>
     */
    public function goalProgress(int $employeeId, ?string $cycleCode = null): array;

    /**
     * Kesenjangan kompetensi untuk rencana pengembangan.
     *
     * @return array<string, mixed>
     */
    public function competencyGaps(int $employeeId): array;

    /**
     * Apakah pegawai sedang menjalani PIP.
     */
    public function pipActive(int $employeeId): bool;
}
