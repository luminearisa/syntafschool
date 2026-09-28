<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Program;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Subject;
use App\Modules\LMS\Domain\Contracts\ClassProviderInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Integrated Mode: direktori rombel & referensi akademik (§6).
 * Semua metode hanya membaca — tidak ada penulisan ke tabel SIAKAD.
 */
class ErpClassProvider implements ClassProviderInterface
{
    public function options(?int $academicYearId = null): array
    {
        return SchoolClass::query()
            ->with(['academicYear:id,name', 'educationLevel:id,name'])
            ->when($academicYearId !== null, fn (Builder $q) => $q->where('academic_year_id', $academicYearId))
            ->where('is_active', true)
            ->orderByDesc('academic_year_id')
            ->orderBy('name')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'id' => (int) $class->id,
                'name' => (string) $class->name,
                'code' => $class->code,
                'academic_year_id' => $class->academic_year_id !== null ? (int) $class->academic_year_id : null,
                'academic_year' => $class->academicYear?->name,
                'education_level' => $class->educationLevel?->name,
            ])
            ->all();
    }

    public function find(int $classId): ?array
    {
        $class = SchoolClass::query()->with(['academicYear:id,name', 'educationLevel:id,name'])->find($classId);

        if ($class === null) {
            return null;
        }

        return [
            'id' => (int) $class->id,
            'name' => (string) $class->name,
            'code' => $class->code,
            'academic_year_id' => $class->academic_year_id !== null ? (int) $class->academic_year_id : null,
            'academic_year' => $class->academicYear?->name,
            'level' => $class->educationLevel?->name,
            'student_count' => (int) $class->classStudents()->count(),
        ];
    }

    public function activeAcademicYearId(): ?int
    {
        $active = AcademicYear::query()->where('is_active', true)->value('id');

        return $active !== null
            ? (int) $active
            : (AcademicYear::query()->orderByDesc('start_date')->value('id') !== null
                ? (int) AcademicYear::query()->orderByDesc('start_date')->value('id')
                : null);
    }

    public function academicYearOptions(): array
    {
        return AcademicYear::query()
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'start_date', 'end_date', 'is_active'])
            ->map(fn (AcademicYear $year) => [
                'id' => (int) $year->id,
                'name' => (string) $year->name,
                'start_date' => $year->start_date?->format('Y-m-d'),
                'end_date' => $year->end_date?->format('Y-m-d'),
                'is_active' => (bool) $year->is_active,
            ])
            ->all();
    }

    public function programOptions(): array
    {
        return Program::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (Program $program) => [
                'id' => (int) $program->id,
                'name' => (string) $program->name,
                'code' => $program->code ?? null,
            ])
            ->all();
    }

    public function subjectOptions(?int $educationLevelId = null): array
    {
        return Subject::query()
            ->when($educationLevelId !== null, fn (Builder $q) => $q->where('education_level_id', $educationLevelId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'credit', 'category'])
            ->map(fn (Subject $subject) => [
                'id' => (int) $subject->id,
                'code' => $subject->code,
                'name' => (string) $subject->name,
                'credit' => $subject->credit !== null ? (float) $subject->credit : null,
                'category' => $subject->category,
            ])
            ->all();
    }

    public function semesterOptions(?int $academicYearId = null): array
    {
        return Semester::query()
            ->when($academicYearId !== null, fn (Builder $q) => $q->where('academic_year_id', $academicYearId))
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'academic_year_id', 'start_date', 'end_date', 'is_active'])
            ->map(fn (Semester $semester) => [
                'id' => (int) $semester->id,
                'name' => (string) $semester->name,
                'academic_year_id' => (int) $semester->academic_year_id,
                'start_date' => $semester->start_date?->format('Y-m-d'),
                'end_date' => $semester->end_date?->format('Y-m-d'),
                'is_active' => (bool) $semester->is_active,
            ])
            ->all();
    }

    /** Mata pelajaran yang tercatat diampu sebuah rombel (untuk pemilih course). */
    public function subjectsForClass(int $classId, ?int $academicYearId = null): array
    {
        return ClassSubject::query()
            ->with(['subject:id,code,name', 'employee:id,full_name'])
            ->where('school_class_id', $classId)
            ->when($academicYearId !== null, fn (Builder $q) => $q->where('academic_year_id', $academicYearId))
            ->get()
            ->map(fn (ClassSubject $row) => [
                'id' => (int) $row->subject_id,
                'code' => $row->subject?->code,
                'name' => $row->subject?->name,
                'teacher_id' => $row->employee_id !== null ? (int) $row->employee_id : null,
                'teacher_name' => $row->employee?->full_name,
            ])
            ->all();
    }
}
