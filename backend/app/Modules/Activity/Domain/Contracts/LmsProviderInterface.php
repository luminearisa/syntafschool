<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * LMS — sumber kebenaran materi dan penilaian pembelajaran (§58).
 *
 * Program aktivitas hanya **memetakan** dirinya ke kursus LMS dan menyelaraskan
 * keanggotaan. Materi, kuis, dan nilai pembelajaran tetap di LMS.
 */
interface LmsProviderInterface
{
    public function course(int $courseId): ?array;

    /** @param list<int> $studentIds */
    public function enroll(int $courseId, array $studentIds, array $options = []): array;

    /** @param list<int> $studentIds */
    public function unenroll(int $courseId, array $studentIds): array;

    public function progress(int $courseId, int $studentId): ?array;

    /** Status kesiapan integrasi LMS di sekolah ini. */
    public function isAvailable(): bool;
}
