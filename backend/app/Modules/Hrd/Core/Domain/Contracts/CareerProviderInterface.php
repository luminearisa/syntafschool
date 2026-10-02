<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Kontrak Karier — dipakai modul ERP lain dan layanan lain di dalam HRD.
 *
 * Pemanggil tidak boleh membaca tabel HRD secara langsung: ia bergantung pada
 * antarmuka ini, sehingga HRD tetap menjadi satu-satunya tempat data diubah dan
 * penggantian implementasi (termasuk untuk pengujian) tidak menyentuh pemanggil.
 */
interface CareerProviderInterface
{
    /**
     * Jalur karier dari posisi saat ini.
     *
     * @return array<string, mixed>
     */
    public function pathFor(int $positionId): array;

    /**
     * Kesiapan pegawai menuju posisi target.
     *
     * @return array<string, mixed>
     */
    public function readiness(int $employeeId, int $targetPositionId): array;

    /**
     * Melamar posisi internal tanpa menggandakan profil kandidat.
     *
     * @return array<string, mixed>
     */
    public function applyInternal(int $employeeId, int $openingId, array $payload, ?int $actorUserId = null): array;

    /**
     * Rencana pengembangan aktif.
     *
     * @return array<string, mixed>
     */
    public function developmentPlan(int $employeeId): array;
}
