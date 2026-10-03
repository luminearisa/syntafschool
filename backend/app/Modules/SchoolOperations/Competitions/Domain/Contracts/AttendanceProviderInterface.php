<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Domain\Contracts;

/** Attendance stays owned by the shared attendance module. */
interface AttendanceProviderInterface extends ProviderAvailabilityInterface
{
    /** @return array<string, mixed>|null */
    public function studentContext(int $studentId): ?array;
}
