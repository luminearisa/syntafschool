<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Domain\Contracts;

/** Achievement module is the source of truth for student achievements. */
interface AchievementProviderInterface extends ProviderAvailabilityInterface
{
    /** @return array{created:bool, reference?:string|null, reason?:string|null} */
    public function record(array $achievement): array;
}
