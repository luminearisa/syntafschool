<?php

namespace App\Modules\LMS\Infrastructure\Providers\Standalone;

use App\Modules\LMS\Domain\Contracts\AcademicCourseProviderInterface;
use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsTeacher;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;

/**
 * Standalone Mode: LMS course itu sendiri adalah course akademik — tidak ada
 * SIAKAD yang perlu ditanyai (§2). Karena itu seluruh penautan akademik
 * mengembalikan data ringkas dari tabel LMS.
 */
class StandaloneAcademicCourseProvider implements AcademicCourseProviderInterface
{
    public function __construct(private readonly StudentProviderInterface $students) {}

    public function find(int $academicCourseId): ?array
    {
        $course = LmsCourse::query()->find($academicCourseId);

        return $course ? $this->summaryOf($course) : null;
    }

    public function forTeacher(LmsActor $actor): array
    {
        return LmsEnrollment::query()
            ->with('course:id,code,name')
            ->where('user_id', $actor->id)
            ->whereIn('role', ['TEACHER', 'ASSISTANT'])
            ->get()
            ->filter(fn (LmsEnrollment $enrollment) => $enrollment->course !== null)
            ->map(fn (LmsEnrollment $enrollment) => [
                'academic_course_id' => (int) $enrollment->course_id,
                'academic_course_name' => $enrollment->course->name,
                'academic_course_code' => $enrollment->course->code,
                'class_id' => null,
                'class_name' => null,
                'academic_year_id' => null,
            ])
            ->values()
            ->all();
    }

    public function forStudent(int $studentId, ?int $academicYearId = null): array
    {
        $learner = $this->students->find($studentId);

        if ($learner === null || $learner->userId === null) {
            return [];
        }

        return LmsEnrollment::query()
            ->with('course:id,code,name')
            ->where('user_id', $learner->userId)
            ->where('role', 'STUDENT')
            ->get()
            ->filter(fn (LmsEnrollment $enrollment) => $enrollment->course !== null)
            ->map(fn (LmsEnrollment $enrollment) => [
                'academic_course_id' => (int) $enrollment->course_id,
                'academic_course_name' => $enrollment->course->name,
                'academic_course_code' => $enrollment->course->code,
                'class_id' => null,
                'class_name' => null,
                'academic_year_id' => null,
                'teacher_id' => null,
            ])
            ->values()
            ->all();
    }

    public function teacherFor(int $classId, int $academicCourseId, ?int $academicYearId = null): ?LmsTeacher
    {
        $teacherId = LmsCourse::query()->whereKey($academicCourseId)->value('teacher_id');

        if ($teacherId === null) {
            return null;
        }

        $row = LmsEnrollment::query()
            ->where('course_id', $academicCourseId)
            ->where('role', 'TEACHER')
            ->with('user')
            ->first();

        if ($row?->user === null) {
            return null;
        }

        return new LmsTeacher(
            id: (int) $row->user->id,
            userId: (int) $row->user->id,
            name: (string) $row->user->name,
            email: $row->user->email,
        );
    }

    public function summary(?int $academicCourseId, ?int $classId, ?int $academicYearId): ?array
    {
        if ($academicCourseId === null) {
            return null;
        }

        $course = LmsCourse::query()->find($academicCourseId);

        return $course ? $this->summaryOf($course) : null;
    }

    /** @return array<string, mixed> */
    protected function summaryOf(LmsCourse $course): array
    {
        return [
            'academic_course_id' => (int) $course->id,
            'code' => $course->code,
            'name' => (string) $course->name,
            'credit' => null,
            'class_id' => null,
            'class_name' => null,
            'academic_year_id' => null,
            'teacher_name' => null,
            'teacher_id' => $course->teacher_id !== null ? (int) $course->teacher_id : null,
        ];
    }
}
