<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Contracts\AcademicCourseProviderInterface;
use App\Modules\LMS\Domain\Contracts\ClassProviderInterface;
use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Contracts\TeacherProviderInterface;
use App\Modules\LMS\Domain\Contracts\UserProviderInterface;
use App\Modules\LMS\Domain\Enums\CourseStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsTeacher;
use App\Modules\LMS\Models\LmsCourse;

/**
 * Jembatan LMS ↔ SIAKAD (§6, §17).
 *
 * Semua pengetahuan tentang "bagaimana ERP menyimpan data akademik" berhenti
 * di kontrak-kontrak yang disuntikkan ke kelas ini. Ketika `LMS_STANDALONE=true`,
 * kontrak dipenuhi implementasi mandiri dan kelas ini tetap bekerja tanpa
 * SIAKAD.
 */
class LmsIntegrationService
{
    public function __construct(
        private readonly UserProviderInterface $users,
        private readonly ClassProviderInterface $classes,
        private readonly AcademicCourseProviderInterface $academic,
        private readonly TeacherProviderInterface $teachers,
        private readonly StudentProviderInterface $students,
    ) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        return [
            'mode' => $this->users->mode(),
            'standalone' => $this->users->isStandalone(),
            'label' => $this->users->isStandalone() ? 'Standalone Mode' : 'Integrated Mode',
            'academic_source' => $this->users->isStandalone() ? 'LMS (mandiri)' : 'SIAKAD / ERP',
            'self_enrollment' => (bool) config('lms.enrollment.self_enrollment', true),
            'guest_preview' => (bool) config('lms.guest.preview_items', true),
        ];
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return [
            'status' => $this->status(),
            'academic_years' => $this->classes->academicYearOptions(),
            'semesters' => $this->classes->semesterOptions(),
            'programs' => $this->classes->programOptions(),
            'classes' => $this->classes->options(),
            'subjects' => $this->classes->subjectOptions(),
        ];
    }

    /**
     * Mata pelajaran yang terdaftar pada sebuah rombel (agar guru tidak
     * mengetik ulang hubungan kelas ↔ mapel yang sudah ada di SIAKAD).
     *
     * @return list<array<string, mixed>>
     */
    public function subjectsForClass(int $classId, ?int $academicYearId = null): array
    {
        if (! method_exists($this->classes, 'subjectsForClass')) {
            return [];
        }

        return $this->classes->subjectsForClass($classId, $academicYearId);
    }

    /** Guru pengampu mapel di kelas (dipakai wizard pembuatan course). */
    public function resolveTeacher(array $data): ?LmsTeacher
    {
        $classId = isset($data['class_id']) && $data['class_id'] !== null ? (int) $data['class_id'] : null;
        $academicCourseId = isset($data['academic_course_id']) && $data['academic_course_id'] !== null
            ? (int) $data['academic_course_id']
            : null;

        if ($classId === null || $academicCourseId === null) {
            return null;
        }

        return $this->academic->teacherFor(
            $classId,
            $academicCourseId,
            isset($data['academic_year_id']) && $data['academic_year_id'] !== null ? (int) $data['academic_year_id'] : null,
        );
    }

    /**
     * Cakupan konversi SIAKAD → LMS: mapel apa saja yang sudah punya course LMS.
     *
     * Guru melihat miliknya sendiri; admin melihat seluruh penautan yang belum
     * dibuat — inilah daftar kerja "buat course untuk mapel yang saya ampu".
     *
     * @return list<array<string, mixed>>
     */
    public function academicCoverage(LmsActor $actor): array
    {
        $rows = $actor->isAdmin()
            ? LmsCourse::query()
                ->whereNotNull('academic_course_id')
                ->get(['id', 'academic_course_id', 'class_id', 'academic_year_id', 'name', 'code', 'status'])
                ->map(fn (LmsCourse $course) => [
                    'academic_course_id' => (int) $course->academic_course_id,
                    'academic_course_name' => $course->name,
                    'academic_course_code' => $course->code,
                    'class_id' => $course->class_id !== null ? (int) $course->class_id : null,
                    'class_name' => null,
                    'academic_year_id' => $course->academic_year_id !== null ? (int) $course->academic_year_id : null,
                    'lms_course_id' => (int) $course->id,
                    'lms_course_status' => $course->status?->value,
                ])
                ->all()
            : $this->academic->forTeacher($actor);

        $withLms = [];

        foreach ($rows as $row) {
            $course = LmsCourse::query()
                ->where('academic_course_id', $row['academic_course_id'])
                ->when(($row['class_id'] ?? null) !== null, fn ($q) => $q->where('class_id', $row['class_id']))
                ->first(['id', 'status']);

            $withLms[] = $row + [
                'lms_course_id' => $course?->id !== null ? (int) $course->id : null,
                'lms_course_status' => $course?->status?->value,
                'has_lms_course' => $course !== null,
            ];
        }

        return $withLms;
    }

    /**
     * Ringkasan kesehatan integrasi untuk dashboard admin (§29).
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $linked = (int) LmsCourse::query()->whereNotNull('academic_course_id')->count();
        $unlinked = (int) LmsCourse::query()->whereNull('academic_course_id')->count();
        $draft = (int) LmsCourse::query()->where('status', CourseStatus::DRAFT->value)->count();

        return [
            ...$this->status(),
            'courses_linked' => $linked,
            'courses_unlinked' => $unlinked,
            'courses_draft' => $draft,
            'classes_available' => count($this->classes->options()),
            'academic_years_available' => count($this->classes->academicYearOptions()),
        ];
    }

    /** Anak-anak seorang wali (§28). */
    public function children(LmsActor $actor): array
    {
        return array_map(fn ($learner) => $learner->toArray(), $this->students->childrenOf($actor));
    }
}
