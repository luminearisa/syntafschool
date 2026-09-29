<?php

namespace App\Modules\LMS\Domain\Contracts;

/**
 * Kontrak integrasi nilai ke modul akademik/SIAKAD (§40).
 *
 * Gradebook **tidak pernah** menyentuh tabel SIAKAD secara langsung: ia hanya
 * menyiapkan payload sesuai kontrak ini, lalu provider yang tahu caranya
 * menulis ke SIAKAD. Pada standalone mode provider "kosong" dipakai sehingga
 * LMS tetap menjadi sumber kebenaran nilai (§63).
 */
interface AcademicGradeProviderInterface
{
    /** Kunci provider (mis. "siakad", "standalone"). */
    public function key(): string;

    public function label(): string;

    /** Apakah integrasi akademik benar-benar tersedia pada instalasi ini? */
    public function isAvailable(): bool;

    /**
     * Kirim satu payload nilai akhir (§40).
     *
     * Wajib **idempotent**: payload dengan hash sama tidak boleh membuat
     * penilaian baru di SIAKAD (§41, §53).
     *
     * @param  array<string, mixed>  $payload
     * @return array{accepted: bool, external_type: string|null, external_id: string|null, external_ref: string|null, message: string, locked: bool}
     */
    public function push(array $payload): array;
}
