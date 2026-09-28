<?php

namespace App\Modules\LMS\Domain\Contracts;

/**
 * Direktori rombel/kelas ERP (hanya baca).
 */
interface ClassProviderInterface
{
    /**
     * Opsi kelas untuk pemilih di frontend.
     *
     * @return list<array<string, mixed>>
     */
    public function options(?int $academicYearId = null): array;

    /** @return array<string, mixed>|null */
    public function find(int $classId): ?array;

    public function activeAcademicYearId(): ?int;

    /** Tahun ajaran untuk pemilih (nama + rentang tanggal). */
    public function academicYearOptions(): array;

    /** Program/peminatan untuk pemilih. */
    public function programOptions(): array;

    /** Mapel ERP (academic course) untuk pemilih. */
    public function subjectOptions(?int $educationLevelId = null): array;

    /** Semester untuk pemilih. */
    public function semesterOptions(?int $academicYearId = null): array;
}
