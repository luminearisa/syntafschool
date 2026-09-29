<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Modules\LMS\Domain\Contracts\CalendarProviderInterface;

/**
 * Standalone Mode: tidak ada kalender akademik eksternal.
 *
 * LMS tetap menampilkan tenggat aktivitas, jadwal kelas langsung, dan agenda
 * lain dari datanya sendiri — provider ini hanya menyatakan bahwa sumber luar
 * tidak ada (bukan error).
 */
class StandaloneCalendarProvider implements CalendarProviderInterface
{
    public function key(): string
    {
        return 'standalone';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function eventsBetween(string $from, string $to, ?int $classId = null, ?int $academicYearId = null): array
    {
        return [];
    }
}
