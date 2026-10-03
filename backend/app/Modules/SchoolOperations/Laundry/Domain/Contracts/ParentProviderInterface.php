<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Contracts;

/**
 * Orang tua/wali (§1, §63). Sumber kebenaran: modul Parent — dasar isolasi
 * akses wali: hanya anak yang menjadi authorized dependent (Rule 20).
 */
interface ParentProviderInterface
{
    /** @return list<array> wali dari satu santri */
    public function guardiansOf(int $studentId): array;

    /** @return list<int> user id akun wali untuk notifikasi */
    public function userIdsFor(int $studentId): array;

    /** @return list<int> student id anak dari satu akun */
    public function childrenOfUser(int $userId): array;

    public function canActFor(int $userId, int $studentId): bool;

    public function available(): bool;
}
