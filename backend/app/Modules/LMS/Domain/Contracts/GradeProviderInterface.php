<?php

namespace App\Modules\LMS\Domain\Contracts;

use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityGrade;
use Illuminate\Support\Collection;

/**
 * Abstraksi catatan nilai aktivitas (§46).
 *
 * Phase 2 hanya membutuhkannya sebagai sumber data Gradebook; Phase 3
 * (Gradebook & Assessment) akan bergantung pada kontrak ini, bukan pada model
 * konkret. Implementasi bawaan: `LmsGradeProvider`.
 */
interface GradeProviderInterface
{
    /**
     * Tulis (upsert) nilai satu siswa untuk satu aktivitas.
     *
     * @param  array{
     *     grade?: float|null, raw_grade?: float|null, maximum_grade?: float|null,
     *     original_grade?: float|null, passed?: bool|null, feedback?: string|null,
     *     source?: string|null, graded_by?: int|null, student_id?: int|null,
     *     enrollment_id?: int|null, metadata?: array<string, mixed>|null
     * }  $data
     */
    public function record(LmsActivity $activity, int $userId, array $data): LmsActivityGrade;

    /** Seluruh nilai pada satu aktivitas (untuk tabel penilaian guru). */
    public function forActivity(LmsActivity $activity): Collection;

    /** Seluruh nilai aktivitas seorang siswa pada satu course. */
    public function forStudent(int $courseId, int $userId): Collection;

    /** Ringkasan nilai per aktivitas pada sebuah course. */
    public function courseSummary(int $courseId, int $userId): array;

    public function forActivityUser(LmsActivity $activity, int $userId): ?LmsActivityGrade;
}
