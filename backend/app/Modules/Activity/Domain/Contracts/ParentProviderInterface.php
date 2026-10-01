<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Penghubung orang tua → anak.
 *
 * Dipakai untuk dua hal saja: menentukan siapa yang boleh memberi persetujuan,
 * dan menentukan apa yang boleh dilihat orang tua di portal.
 */
interface ParentProviderInterface
{
    /** @return list<array> wali siswa (guardian + akun pengguna bila ada) */
    public function guardiansOf(int $studentId): array;

    /** @return list<int> id pengguna yang berhak mewakili siswa */
    public function userIdsFor(int $studentId): array;

    /** @return list<int> id siswa yang berada di bawah pengguna ini */
    public function childrenOfUser(int $userId): array;

    /** Apakah pengguna benar-benar wali dari siswa tersebut. */
    public function canActFor(int $userId, int $studentId): bool;
}
