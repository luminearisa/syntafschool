<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Providers;

use App\Modules\SchoolOperations\Religious\Application\Services\PersonnelService;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\ReligiousPersonnelProviderInterface;
use App\Modules\SchoolOperations\Religious\Models\ReligiousPersonnelProfile;

/**
 * Kontrak personel keagamaan untuk modul lain (§16).
 * Modul HRD, Academic, dan Event memakainya untuk menanyakan ketersediaan
 * imam/muadzin/khatib tanpa mengakses tabel internal modul ini.
 */
class ReligiousPersonnelProviderImpl implements ReligiousPersonnelProviderInterface
{
    public function __construct(protected PersonnelService $personnel)
    {
    }

    public function personnel(int $personnelId): ?array
    {
        $profile = ReligiousPersonnelProfile::query()->find($personnelId);

        return $profile ? [
            'id' => $profile->getKey(),
            'code' => $profile->code,
            'full_name' => $profile->full_name,
            'person_type' => $profile->person_type,
            'employee_id' => $profile->employee_id,
            'student_id' => $profile->student_id,
            'specialization' => $profile->specialization,
            'qualification' => $profile->qualification,
            'status' => $profile->status,
        ] : null;
    }

    public function imams(array $filters = []): array
    {
        return $this->personnel->imams($filters);
    }

    public function muadzins(array $filters = []): array
    {
        return $this->personnel->muadzins($filters);
    }

    public function khatibs(array $filters = []): array
    {
        return $this->personnel->khatibs($filters);
    }

    public function availability(int $personnelId, string $date): array
    {
        return $this->personnel->availability($personnelId, $date);
    }

    public function hasConflict(int $personnelId, string $date, string $startTime, string $endTime, ?int $exceptAssignmentId = null): bool
    {
        $conflict = $this->personnel->conflictFor($personnelId, [
            'effective_from' => $date,
            'prayer_type' => 'ANY',
        ]);

        return $conflict !== null;
    }
}
