<?php

namespace App\Modules\LMS\Domain\Contracts;

use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsLearner;

/**
 * Direktori siswa. LMS tidak pernah menulis ke tabel siswa ERP — hanya membaca
 * supaya enrollment akademik dapat dibuat otomatis (§6, §17).
 */
interface StudentProviderInterface
{
    public function find(int $studentId): ?LmsLearner;

    /** Profil siswa milik sebuah akun (siswa yang login). */
    public function forUser(LmsActor $actor): ?LmsLearner;

    /** Siswa aktif pada satu rombel & tahun ajaran (sumber auto-enrollment). */
    public function inClass(int $classId, ?int $academicYearId = null): array;

    /** Anak-anak dari seorang wali (§28). */
    public function childrenOf(LmsActor $actor): array;

    public function isGuardianOf(LmsActor $actor, int $studentId): bool;

    /**
     * Pencarian siswa untuk pendaftaran manual/bulk (§18).
     *
     * @return list<LmsLearner>
     */
    public function search(string $term, ?int $classId = null, int $limit = 25): array;
}
