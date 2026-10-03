<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Domain\Contracts;

/** Student master remains owned by the Student/Academic domain. */
interface StudentProviderInterface extends ProviderAvailabilityInterface
{
    /** @return array<string, mixed>|null */
    public function snapshot(int $studentId): ?array;

    /** @return list<array<string, mixed>> */
    public function search(string $term, int $limit = 20): array;
}
