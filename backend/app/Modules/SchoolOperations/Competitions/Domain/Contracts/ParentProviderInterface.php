<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Domain\Contracts;

/** Parent/guardian identity and authority are delegated to the Parent domain. */
interface ParentProviderInterface extends ProviderAvailabilityInterface
{
    /** @return list<int> */
    public function userIdsFor(int $studentId): array;

    public function canActFor(int $userId, int $studentId): bool;

    /** @return list<int> */
    public function studentIdsForUser(int $userId): array;
}
