<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Kontrak Rekrutmen — dipakai modul ERP lain dan layanan lain di dalam HRD.
 *
 * Pemanggil tidak boleh membaca tabel HRD secara langsung: ia bergantung pada
 * antarmuka ini, sehingga HRD tetap menjadi satu-satunya tempat data diubah dan
 * penggantian implementasi (termasuk untuk pengujian) tidak menyentuh pemanggil.
 */
interface RecruitmentProviderInterface
{
    /**
     * Ringkasan permintaan tenaga kerja.
     *
     * @return array<string, mixed>
     */
    public function requisition(int $id): ?array;

    /**
     * Jumlah formasi yang sedang dibuka per organisasi.
     *
     * @return array<string, mixed>
     */
    public function openHeadcount(int $organizationId): array;

    /**
     * Ringkasan corong rekrutmen untuk analitik.
     *
     * @return array<string, mixed>
     */
    public function pipelineSummary(int $requisitionId): array;

    /**
     * Pencarian kandidat tanpa menggandakan orang.
     *
     * @return array<string, mixed>
     */
    public function candidateByEmail(string $email): ?array;

    /**
     * Lowongan yang boleh dilamar pegawai internal.
     *
     * @return array<string, mixed>
     */
    public function openingsForInternalMobility(int $employeeId): array;
}
