<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Kontrak Talenta — dipakai modul ERP lain dan layanan lain di dalam HRD.
 *
 * Pemanggil tidak boleh membaca tabel HRD secara langsung: ia bergantung pada
 * antarmuka ini, sehingga HRD tetap menjadi satu-satunya tempat data diubah dan
 * penggantian implementasi (termasuk untuk pengujian) tidak menyentuh pemanggil.
 */
interface TalentProviderInterface
{
    /**
     * Hasil tinjauan talenta terakhir.
     *
     * @return array<string, mixed>
     */
    public function review(int $employeeId, ?string $cycleCode = null): ?array;

    /**
     * Matriks kinerja x potensi (dapat ditelusuri ke sumbernya).
     *
     * @return array<string, mixed>
     */
    public function nineBox(?int $organizationId = null, ?string $cycleCode = null): array;

    /**
     * Anggota kumpulan talenta.
     *
     * @return array<string, mixed>
     */
    public function poolMembers(string $poolCode): array;

    /**
     * Daftar pegawai potensi tinggi.
     *
     * @return array<string, mixed>
     */
    public function highPotentials(?int $organizationId = null): array;

    /**
     * Kesiapan suksesi (fondasi Phase 3).
     *
     * @return array<string, mixed>
     */
    public function successionReady(?int $positionId = null): array;
}
