<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Kontrak Kompetensi — dipakai modul ERP lain dan layanan lain di dalam HRD.
 *
 * Pemanggil tidak boleh membaca tabel HRD secara langsung: ia bergantung pada
 * antarmuka ini, sehingga HRD tetap menjadi satu-satunya tempat data diubah dan
 * penggantian implementasi (termasuk untuk pengujian) tidak menyentuh pemanggil.
 */
interface CompetencyProviderInterface
{
    /**
     * Katalog kompetensi beserta indikator perilaku.
     *
     * @return array<string, mixed>
     */
    public function catalog(?string $category = null): array;

    /**
     * Menilai kompetensi pegawai.
     *
     * @return array<string, mixed>
     */
    public function assess(int $employeeId, array $payload, int $actorUserId): array;

    /**
     * Kesenjangan kompetensi terhadap standar posisi.
     *
     * @return array<string, mixed>
     */
    public function gap(int $employeeId, ?int $positionId = null): array;

    /**
     * Profil keterampilan dari fondasi Phase 1.
     *
     * @return array<string, mixed>
     */
    public function skillsFor(int $employeeId): array;
}
