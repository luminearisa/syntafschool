<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Application\Services\CompetencyService;
use App\Modules\Hrd\Domain\Contracts\EmployeeCertificationProviderInterface;
use App\Modules\Hrd\Domain\Contracts\EmployeeSkillProviderInterface;

/**
 * Keterampilan & sertifikasi untuk modul lain (§30, §65, §84).
 *
 * Satu kelas untuk dua kontrak karena keduanya membaca data kompetensi pegawai
 * yang sama; LMS, Supervisi, dan perencanaan karier memakainya untuk menyarankan
 * pengembangan dan menghitung kelayakan.
 */
class CompetencyModuleProvider implements EmployeeSkillProviderInterface, EmployeeCertificationProviderInterface
{
    public function __construct(private readonly CompetencyService $competency) {}

    public function skillsFor(int $employeeId): array
    {
        return $this->competency->skillsFor($employeeId);
    }

    public function skillsAtLevel(int $employeeId, string $minimumLevel): array
    {
        return $this->competency->skillsAtLevel($employeeId, $minimumLevel);
    }

    public function skillsInOrganization(int $organizationId, ?string $skillCode = null): array
    {
        return $this->competency->skillsInOrganization($organizationId, $skillCode);
    }

    public function skillGap(int $employeeId, ?int $jobId = null): array
    {
        return $this->competency->skillGap($employeeId, $jobId);
    }

    public function holdersOfSkill(string $skillCode, string $minimumLevel = 'BEGINNER'): array
    {
        return $this->competency->holdersOfSkill($skillCode, $minimumLevel);
    }

    public function certificationsFor(int $employeeId): array
    {
        return $this->competency->certificationsFor($employeeId);
    }

    public function validFor(int $employeeId, ?string $onDate = null): array
    {
        return $this->competency->validFor($employeeId, $onDate);
    }

    public function expiring(int $days, ?int $organizationId = null): array
    {
        return $this->competency->expiringCertifications($days, $organizationId);
    }

    public function holds(string $certificationCode, int $employeeId, ?string $onDate = null): bool
    {
        return $this->competency->holds($certificationCode, $employeeId, $onDate);
    }

    public function missingMandatory(string $certificationCode): array
    {
        return $this->competency->missingMandatory($certificationCode);
    }
}
