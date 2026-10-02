<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak dokumen pegawai (§2, §65, §31).
 *
 * Modul lain hanya boleh tahu **apakah** sebuah dokumen wajib sudah ada dan sah
 * — bukan isi berkasnya. Pertanyaan seperti "apakah guru ini sudah menyerahkan
 * ijazah yang terverifikasi?" dijawab di sini; pengunduhan berkas tetap melalui
 * endpoint ber-otorisasi milik HRD (§52).
 */
interface EmployeeDocumentProviderInterface
{
    /**
     * Daftar dokumen pegawai — hanya metadata, tanpa tautan berkas.
     *
     * @return list<array<string, mixed>>
     */
    public function documentsFor(int $employeeId, ?string $category = null): array;

    /** Apakah pegawai memiliki dokumen jenis ini yang masih berlaku & terverifikasi? */
    public function hasValidDocument(int $employeeId, string $documentTypeCode): bool;

    /**
     * Dokumen yang akan/ sudah kedaluwarsa dalam sekian hari (`$days` negatif
     * untuk yang sudah lewat).
     *
     * @return list<array<string, mixed>>
     */
    public function expiring(int $days, ?int $organizationId = null): array;

    /**
     * Dokumen wajib yang belum dimiliki pegawai — dipakai pemeriksa kelengkapan.
     *
     * @return list<string> kode jenis dokumen
     */
    public function missingMandatoryTypes(int $employeeId): array;
}
