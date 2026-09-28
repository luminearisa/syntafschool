<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\Subject;
use App\Modules\LMS\Domain\Contracts\AcademicCourseProviderInterface;
use App\Modules\LMS\Domain\Contracts\ClassProviderInterface;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsTeacher;
use Illuminate\Database\Eloquent\Builder;

/**
 * Integrated Mode: "course" versi SIAKAD = `subjects` + `class_subjects` (§6).
 *
 * Di sinilah penautan MAT101 → LMS Course MAT101-XIPA1-2026 terjadi: LMS hanya
 * membaca, SIAKAD tetap menjadi sumber kebenaran jadwal & pengampu.
 */
class ErpAcademicCourseProvider implements AcademicCourseProviderInterface
{
    public function __construct(private readonly ClassProviderInterface $classes) {}

    public function find(int $academicCourseId): ?array
    {
        $subject = Subject::query()->find($academicCourseId);

        if ($subject === null) {
            return null;
        }

        return [
            'id' => (int) $subject->id,
            'code' => $subject->code,
            'name' => (string) $subject->name,
            'credit' => $subject->credit !== null ? (float) $subject->credit : null,
            'category' => $subject->category,
            'education_level_id' => $subject->education_level_id !== null ? (int) $subject->education_level_id : null,
        ];
    }

    public function forTeacher(LmsActor $actor): array
    {
        if ($actor->employeeId === null) {
            return [];
        }

        return ClassSubject::query()
            ->with(['subject:id,code,name', 'schoolClass:id,name,code,academic_year_id'])
            ->where('employee_id', $actor->employeeId)
            ->orderByDesc('academic_year_id')
            ->get()
            ->map(fn (ClassSubject $row) => [
                'academic_course_id' => (int) $row->subject_id,
                'academic_course_name' => $row->subject?->name,
                'academic_course_code' => $row->subject?->code,
                'class_id' => (int) $row->school_class_id,
                'class_name' => $row->schoolClass?->name,
                'academic_year_id' => (int) $row->academic_year_id,
            ])
            ->all();
    }

    public function forStudent(int $studentId, ?int $academicYearId = null): array
    {
        $academicYearId ??= $this->classes->activeAcademicYearId();

        $classIds = ClassStudent::query()
            ->where('student_id', $studentId)
            ->when($academicYearId !== null, fn (Builder $q) => $q->where('academic_year_id', $academicYearId))
            ->pluck('school_class_id');

        if ($classIds->isEmpty()) {
            return [];
        }

        return ClassSubject::query()
            ->with(['subject:id,code,name', 'schoolClass:id,name'])
            ->whereIn('school_class_id', $classIds)
            ->when($academicYearId !== null, fn (Builder $q) => $q->where('academic_year_id', $academicYearId))
            ->get()
            ->map(fn (ClassSubject $row) => [
                'academic_course_id' => (int) $row->subject_id,
                'academic_course_name' => $row->subject?->name,
                'academic_course_code' => $row->subject?->code,
                'class_id' => (int) $row->school_class_id,
                'class_name' => $row->schoolClass?->name,
                'academic_year_id' => (int) $row->academic_year_id,
                'teacher_id' => $row->employee_id !== null ? (int) $row->employee_id : null,
            ])
            ->all();
    }

    public function teacherFor(int $classId, int $academicCourseId, ?int $academicYearId = null): ?LmsTeacher
    {
        $employee = ClassSubject::query()
            ->where('school_class_id', $classId)
            ->where('subject_id', $academicCourseId)
            ->when($academicYearId !== null, fn (Builder $q) => $q->where('academic_year_id', $academicYearId))
            ->with('employee')
            ->first()?->employee;

        if ($employee === null) {
            return null;
        }

        return new LmsTeacher(
            id: (int) $employee->id,
            userId: $employee->user?->id !== null ? (int) $employee->user->id : null,
            name: (string) $employee->full_name,
            email: $employee->email,
            nip: $employee->nip,
            photoPath: $employee->photo_path,
        );
    }

    public function summary(?int $academicCourseId, ?int $classId, ?int $academicYearId): ?array
    {
        if ($academicCourseId === null) {
            return null;
        }

        $subject = Subject::query()->find($academicCourseId);

        if ($subject === null) {
            return null;
        }

        $teacherName = null;

        if ($classId !== null) {
            $teacherName = ClassSubject::query()
                ->where('school_class_id', $classId)
                ->where('subject_id', $academicCourseId)
                ->when($academicYearId !== null, fn (Builder $q) => $q->where('academic_year_id', $academicYearId))
                ->with('employee:id,full_name')
                ->first()?->employee?->full_name;
        }

        return [
            'academic_course_id' => (int) $subject->id,
            'code' => $subject->code,
            'name' => (string) $subject->name,
            'credit' => $subject->credit !== null ? (float) $subject->credit : null,
            'class_id' => $classId,
            'class_name' => $classId !== null ? $this->classes->find($classId)['name'] ?? null : null,
            'academic_year_id' => $academicYearId,
            'teacher_name' => $teacherName,
            'teacher_id' => Employee::query()
                ->whereHas('classSubjects', fn (Builder $q) => $q
                    ->where('school_class_id', $classId)
                    ->where('subject_id', $academicCourseId))
                ->value('id'),
        ];
    }
}
