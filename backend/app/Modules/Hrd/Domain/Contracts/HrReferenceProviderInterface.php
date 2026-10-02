<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak referensi (§18, §41, §65).
 *
 * Jenis organisasi, worker type, status kepegawaian, alasan keluar, dan jenjang
 * pendidikan adalah konfigurasi. Modul lain membacanya lewat kontrak ini supaya
 * daftar pilihan di layar mereka tidak pernah berbeda dari daftar yang dipakai
 * HRD.
 */
interface HrReferenceProviderInterface
{
    /**
     * Nilai referensi aktif pada satu kelompok.
     *
     * @return list<array{code: string, label: string, sort_order: int, meta: array<string, mixed>}>
     */
    public function items(string $groupCode): array;

    /** Label yang dapat dibaca manusia untuk sebuah kode (aman bila tidak ada). */
    public function label(string $groupCode, ?string $code): ?string;

    /** Apakah kode ini sah dan aktif pada kelompok tersebut? */
    public function isValid(string $groupCode, ?string $code): bool;

    /**
     * Nilai bawaan kelompok, mis. worker type default untuk pegawai baru.
     *
     * @return string|null
     */
    public function defaultCode(string $groupCode): ?string;
}
