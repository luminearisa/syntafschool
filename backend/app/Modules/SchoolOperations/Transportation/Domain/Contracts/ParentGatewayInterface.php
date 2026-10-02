<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Wali/orang tua siswa — dasar isolasi akses orang tua (§87 Rule 14). */
interface ParentGatewayInterface
{
    public function guardiansOf(int $studentId): array;

    public function userIdsFor(int $studentId): array;

    public function childrenOfUser(int $userId): array;

    public function canActFor(int $userId, int $studentId): bool;

    public function available(): bool;
}
