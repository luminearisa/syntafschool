<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak keterampilan pegawai (§30, §65, §84).
 *
 * Ini pintu masuk menuju HR berbasis keterampilan pada fase berikutnya:
 * LMS memakainya untuk menyarankan pelatihan, Supervisi untuk menyusun rencana
 * pengembangan, dan perencanaan karier untuk menghitung kesenjangan keterampilan
 * terhadap syarat jabatan.
 */
interface EmployeeSkillProviderInterface
{
    /**
     * Seluruh keterampilan pegawai beserta tingkat kemahirannya.
     *
     * @return list<array<string, mixed>>
     */
    public function skillsFor(int $employeeId): array;

    /**
     * Keterampilan pegawai pada tingkat minimal tertentu.
     *
     * @return list<array<string, mixed>>
     */
    public function skillsAtLevel(int $employeeId, string $minimumLevel): array;

    /**
     * Keterampilan pegawai pada satu unit (untuk pemetaan kompetensi unit).
     *
     * @return list<array<string, mixed>>
     */
    public function skillsInOrganization(int $organizationId, ?string $skillCode = null): array;

    /**
     * Kesenjangan keterampilan pegawai terhadap syarat jabatannya.
     *
     * @return array{required: list<string>, held: list<string>, missing: list<string>}
     */
    public function skillGap(int $employeeId, ?int $jobId = null): array;

    /**
     * Pegawai yang memiliki keterampilan tertentu minimal pada tingkat tertentu —
     * dasar penugasan tim mendadak (mis. "siapa bisa P3K?").
     *
     * @return list<array<string, mixed>>
     */
    public function holdersOfSkill(string $skillCode, string $minimumLevel = 'BEGINNER'): array;
}
