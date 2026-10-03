<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Infrastructure\Providers;

use App\Modules\SchoolOperations\Competitions\Domain\Contracts\AchievementProviderInterface;

/** Safe default until an Achievement module adapter is configured. */
class UnavailableAchievementProvider implements AchievementProviderInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return 'Achievement provider belum dikonfigurasi.';
    }

    public function record(array $achievement): array
    {
        return ['created' => false, 'reason' => $this->unavailableReason()];
    }
}
