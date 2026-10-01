<?php

namespace App\Modules\Bk\Domain\Contracts;

/**
 * Relasi keluarga untuk Modul BK.

Dipakai untuk dua hal yang berbeda: mengirim **permintaan persetujuan** kepada
wali yang sah, dan memverifikasi bahwa sebuah akun benar-benar wali dari siswa
tersebut (dasar portal orang tua). Pemeriksaan selalu ke basis data.
 */
interface ParentProviderInterface
{

    /** @return list<int> id pengguna (akun) wali yang sah untuk siswa ini */
    public function guardianUserIdsForStudent(int $studentId): array;

    /**
     * @return list<array{guardian_id: int, name: string, phone: ?string, email: ?string,
     *     relation: ?string, is_primary: bool, has_account: bool}>
     */
    public function guardiansForStudent(int $studentId): array;

    /** @return list<int> id siswa yang boleh diakses akun wali ini */
    public function studentIdsForGuardianUser(int $userId): array;

    /** Apakah akun ini benar-benar wali dari siswa tersebut (dicek ke basis data). */
    public function isGuardianOf(int $userId, int $studentId): bool;
}
