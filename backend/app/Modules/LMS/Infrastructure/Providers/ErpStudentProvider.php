<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Models\ClassStudent;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\LMS\Domain\Contracts\ClassProviderInterface;
use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsLearner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Integrated Mode: siswa dibaca dari `students` + `class_students`.
 * LMS tidak pernah menulis ke tabel ini (§6 larangan duplikasi data).
 */
class ErpStudentProvider implements StudentProviderInterface
{
    public function __construct(private readonly ClassProviderInterface $classes) {}

    public function find(int $studentId): ?LmsLearner
    {
        $student = Student::query()->find($studentId);

        return $student ? $this->toLearner($student) : null;
    }

    public function forUser(LmsActor $actor): ?LmsLearner
    {
        if ($actor->studentId !== null) {
            return $this->find($actor->studentId);
        }

        $studentId = User::query()
            ->whereKey($actor->id)
            ->where('userable_type', Student::class)
            ->value('userable_id');

        if ($studentId === null) {
            return null;
        }

        $student = Student::query()->find($studentId);

        return $student ? $this->toLearner($student) : null;
    }

    public function inClass(int $classId, ?int $academicYearId = null): array
    {
        $academicYearId ??= $this->classes->activeAcademicYearId();

        $students = Student::query()
            ->whereHas('classStudents', function (Builder $q) use ($classId, $academicYearId): void {
                $q->where('school_class_id', $classId);
                if ($academicYearId !== null) {
                    $q->where('academic_year_id', $academicYearId);
                }
            })
            ->orderBy('full_name')
            ->get();

        return $this->mapLearners($students, $academicYearId);
    }

    public function childrenOf(LmsActor $actor): array
    {
        $guardianIds = Guardian::query()
            ->where(function (Builder $q) use ($actor): void {
                $q->where('id', function ($sub) use ($actor): void {
                    $sub->select('guardian_id')->from('ppdb_parent_accounts')
                        ->where('user_id', $actor->id)->whereNotNull('guardian_id')->limit(1);
                });

                if ($actor->email !== null && $actor->email !== '') {
                    $q->orWhere('email', $actor->email);
                }
            })
            ->pluck('id');

        if ($guardianIds->isEmpty()) {
            return [];
        }

        $students = Student::query()
            ->whereHas('guardians', fn (Builder $q) => $q->whereIn('guardians.id', $guardianIds))
            ->orderBy('full_name')
            ->get();

        $relations = Guardian::query()
            ->whereIn('id', $guardianIds)
            ->with(['students' => fn ($q) => $q->select('students.id')])
            ->get();

        $primary = [];
        foreach ($relations as $guardian) {
            foreach ($guardian->students as $student) {
                $primary[$student->id] = [
                    'guardian_id' => $guardian->id,
                    'relation' => $guardian->full_name,
                    'is_primary' => (bool) $student->pivot->is_primary,
                ];
            }
        }

        return $this->mapLearners($students, $this->classes->activeAcademicYearId(), $primary);
    }

    public function isGuardianOf(LmsActor $actor, int $studentId): bool
    {
        foreach ($this->childrenOf($actor) as $child) {
            if ($child->id === $studentId) {
                return true;
            }
        }

        return false;
    }

    public function search(string $term, ?int $classId = null, int $limit = 25): array
    {
        $term = trim($term);

        $students = Student::query()
            ->when($classId !== null, fn (Builder $q) => $q->whereHas(
                'classStudents',
                fn (Builder $inner) => $inner->where('school_class_id', $classId),
            ))
            ->when($term !== '', fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner
                    ->where('full_name', 'like', "%{$term}%")
                    ->orWhere('nis', 'like', "%{$term}%")
                    ->orWhere('nisn', 'like', "%{$term}%"),
            ))
            ->orderBy('full_name')
            ->limit($limit)
            ->get();

        return $this->mapLearners($students, $this->classes->activeAcademicYearId());
    }

    /**
     * @param  Collection<int, Student>  $students
     * @return list<LmsLearner>
     */
    protected function mapLearners(Collection $students, ?int $academicYearId = null, array $guardianMeta = []): array
    {
        if ($students->isEmpty()) {
            return [];
        }

        $ids = $students->pluck('id')->all();

        $userIds = User::query()
            ->where('userable_type', Student::class)
            ->whereIn('userable_id', $ids)
            ->pluck('id', 'userable_id');

        $placements = ClassStudent::query()
            ->with('schoolClass:id,name,academic_year_id')
            ->whereIn('student_id', $ids)
            ->when($academicYearId !== null, fn (Builder $q) => $q->where('academic_year_id', $academicYearId))
            ->orderByDesc('academic_year_id')
            ->get()
            ->keyBy('student_id');

        return $students->map(function (Student $student) use ($userIds, $placements, $guardianMeta, $academicYearId): LmsLearner {
            $placement = $placements->get($student->id);
            $meta = $guardianMeta[$student->id] ?? [];

            return new LmsLearner(
                id: (int) $student->id,
                userId: $userIds->get($student->id) !== null ? (int) $userIds->get($student->id) : null,
                name: (string) $student->full_name,
                code: $student->nis,
                classId: $placement?->school_class_id !== null ? (int) $placement->school_class_id : null,
                className: $placement?->schoolClass?->name,
                programId: $student->program_id !== null ? (int) $student->program_id : null,
                programName: $student->program?->name,
                academicYearId: $placement?->academic_year_id !== null
                    ? (int) $placement->academic_year_id
                    : $academicYearId,
                photoPath: $student->photo_path,
                guardianId: $meta['guardian_id'] ?? null,
                relation: $meta['relation'] ?? null,
                isPrimaryGuardian: $meta['is_primary'] ?? false,
            );
        })->values()->all();
    }

    protected function toLearner(Student $student): LmsLearner
    {
        return $this->mapLearners(collect([$student]))[0];
    }
}
