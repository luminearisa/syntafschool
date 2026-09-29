<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Modules\LMS\Domain\Contracts\EnrollmentProviderInterface;

/**
 * Standalone Mode: LMS sendiri sumber resmi keikutsertaan.
 *
 * `isAuthoritative()` bernilai `false` supaya sinkronisasi tidak menunggu
 * sumber luar dan tidak pernah menangguhkan pendaftaran LMS (§5, §47).
 */
class StandaloneEnrollmentProvider implements EnrollmentProviderInterface
{
    public function key(): string
    {
        return 'standalone';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function isAuthoritative(): bool
    {
        return false;
    }

    public function officialPlacements(?int $academicYearId = null, ?int $classId = null): array
    {
        return [];
    }

    public function placementFor(int $studentId, ?int $classId = null, ?int $academicYearId = null): ?array
    {
        return null;
    }
}
