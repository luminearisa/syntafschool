<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Infrastructure\Providers;

use App\Modules\Activity\Domain\Contracts\ParentProviderInterface as ActivityParentProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\ParentProviderInterface as CompetitionParentProviderInterface;

/** Delegates guardian authority checks to the ERP Parent provider. */
class ActivityParentProvider implements CompetitionParentProviderInterface
{
    public function __construct(private readonly ActivityParentProviderInterface $parents) {}

    public function userIdsFor(int $studentId): array
    {
        return $this->parents->userIdsFor($studentId);
    }

    public function canActFor(int $userId, int $studentId): bool
    {
        return $this->parents->canActFor($userId, $studentId);
    }

    public function studentIdsForUser(int $userId): array
    {
        return $this->parents->childrenOfUser($userId);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }
}
