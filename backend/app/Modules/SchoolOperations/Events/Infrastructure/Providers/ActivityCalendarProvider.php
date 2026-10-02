<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Kalender sekolah (modul Calendar).
 *
 * Meneruskan ke kontrak Calendar agar event tampil pada kalender terkait (§15).
 */
class ActivityCalendarProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Calendar agar event tampil pada kalender terkait (§15).';

    protected const TARGET_CONTRACT = 'App\\Modules\\LMS\\Domain\\Contracts\\CalendarProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function key(): string
    {
        $value = $this->call('key', []);

        return $value === null ? '' : (string) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function isAvailable(): bool
    {
        $value = $this->call('isAvailable', []);

        return $value === null ? false : (bool) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function eventsBetween(string $from, string $to, ?int $classId = null, ?int $academicYearId = null): array
    {
        $value = $this->call('eventsBetween', [$from, $to, $classId, $academicYearId]);

        return $this->wrap($value);
    }
}
