<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Asrama & akomodasi (modul Activity).
 *
 * Meneruskan ke kontrak Boarding untuk akomodasi multi-hari (§50).
 */
class ActivityBoardingProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Boarding untuk akomodasi multi-hari (§50).';

    protected const TARGET_CONTRACT = 'App\\Modules\\Activity\\Domain\\Contracts\\BoardingProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function obligationsFor(int $studentId, string $date): array
    {
        $value = $this->call('obligationsFor', [$studentId, $date]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function placementsFor(int $studentId): array
    {
        $value = $this->call('placementsFor', [$studentId]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function weeklyObligations(): array
    {
        $value = $this->call('weeklyObligations', []);

        return $this->wrap($value);
    }
}
