<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Models\ClassSubject;
use App\Models\Employee;
use App\Modules\LMS\Domain\Contracts\TeacherProviderInterface;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsTeacher;
use Illuminate\Database\Eloquent\Builder;

/** Integrated Mode: guru dibaca dari `employees` + `class_subjects`. */
class ErpTeacherProvider implements TeacherProviderInterface
{
    public function find(int $teacherId): ?LmsTeacher
    {
        $employee = Employee::query()->find($teacherId);

        return $employee ? $this->toTeacher($employee) : null;
    }

    public function forUser(LmsActor $actor): ?LmsTeacher
    {
        if ($actor->employeeId === null) {
            return null;
        }

        return $this->find($actor->employeeId);
    }

    public function forClassSubject(int $classId, int $academicCourseId, ?int $academicYearId = null): ?LmsTeacher
    {
        $employeeId = ClassSubject::query()
            ->where('school_class_id', $classId)
            ->where('subject_id', $academicCourseId)
            ->when($academicYearId !== null, fn (Builder $q) => $q->where('academic_year_id', $academicYearId))
            ->value('employee_id');

        return $employeeId !== null ? $this->find((int) $employeeId) : null;
    }

    public function search(string $term, int $limit = 25): array
    {
        $term = trim($term);

        return Employee::query()
            ->where('is_teacher', true)
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner
                    ->where('full_name', 'like', "%{$term}%")
                    ->orWhere('nip', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"),
            ))
            ->orderBy('full_name')
            ->limit($limit)
            ->get()
            ->map(fn (Employee $employee) => $this->toTeacher($employee))
            ->all();
    }

    protected function toTeacher(Employee $employee): LmsTeacher
    {
        return new LmsTeacher(
            id: (int) $employee->id,
            userId: $employee->user?->id !== null ? (int) $employee->user->id : null,
            name: (string) $employee->full_name,
            email: $employee->email,
            nip: $employee->nip,
            photoPath: $employee->photo_path,
        );
    }
}
