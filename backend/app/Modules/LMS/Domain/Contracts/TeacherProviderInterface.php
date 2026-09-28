<?php

namespace App\Modules\LMS\Domain\Contracts;

use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsTeacher;

interface TeacherProviderInterface
{
    public function find(int $teacherId): ?LmsTeacher;

    /** Profil guru milik sebuah akun. */
    public function forUser(LmsActor $actor): ?LmsTeacher;

    /**
     * Guru pengampu sebuah mapel di kelas tertentu (dari `class_subjects`),
     * dipakai `LmsIntegrationService` untuk menetapkan pengajar otomatis.
     */
    public function forClassSubject(int $classId, int $academicCourseId, ?int $academicYearId = null): ?LmsTeacher;

    /**
     * @return list<LmsTeacher>
     */
    public function search(string $term, int $limit = 25): array;
}
