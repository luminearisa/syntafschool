<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Jadwal keagamaan (modul Religious).
 *
 * Meneruskan ke ReligiousLifeProviderInterface milik modul Keagamaan agar jadwal tidak diduplikasi (§19, §85).
 */
class ReligiousLifeProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke ReligiousLifeProviderInterface milik modul Keagamaan agar jadwal tidak diduplikasi (§19, §85).';

    protected const TARGET_CONTRACT = 'App\\Modules\\SchoolOperations\\Religious\\Domain\\Contracts\\ReligiousLifeProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function prayerTimesFor(string $date, ?int $mosqueId = null): array
    {
        $value = $this->call('prayerTimesFor', [$date, $mosqueId]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function nextPrayer(?int $mosqueId = null, ?string $at = null): ?array
    {
        $value = $this->call('nextPrayer', [$mosqueId, $at]);

        return $value === null ? null : (array) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function todayActivities(?int $mosqueId = null, ?string $date = null): array
    {
        $value = $this->call('todayActivities', [$mosqueId, $date]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function isRamadan(?string $date = null): bool
    {
        $value = $this->call('isRamadan', [$date]);

        return $value === null ? false : (bool) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function mosquesFor(?int $campusId = null): array
    {
        $value = $this->call('mosquesFor', [$campusId]);

        return $this->wrap($value);
    }
}
