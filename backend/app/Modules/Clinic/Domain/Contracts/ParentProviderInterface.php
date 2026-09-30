<?php

namespace App\Modules\Clinic\Domain\Contracts;

/**
 * Relasi keluarga: siapa orang tua/wali yang sah untuk seorang siswa.
 *
 * Dipakai untuk notifikasi dan portal orang tua. Modul Clinic tidak pernah
 * menyimpulkan sendiri relasi keluarga — selalu bertanya ke kontrak ini,
 * sehingga "orang tua A" tidak mungkin melihat "anak B".
 */
interface ParentProviderInterface
{
    /**
     * Akun pengguna (users.id) milik wali siswa yang punya akun aktif.
     *
     * @return list<int>
     */
    public function guardianUserIdsForStudent(int $studentId): array;

    /**
     * Data wali (untuk kontak darurat & surat): nama + telepon.
     *
     * @return list<array{guardian_id: int, name: string, phone: ?string, email: ?string, relation: ?string, has_account: bool}>
     */
    public function guardiansForStudent(int $studentId): array;

    /**
     * Semua siswa yang menjadi tanggungan akun wali ini.
     *
     * @return list<int>
     */
    public function studentIdsForGuardianUser(int $userId): array;

    /** Apakah akun ini benar-benar wali dari siswa tersebut. */
    public function isGuardianOf(int $userId, int $studentId): bool;
}
