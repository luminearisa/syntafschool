<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Models\ClassStudent;
use App\Modules\LMS\Domain\Contracts\ClassProviderInterface;
use App\Modules\LMS\Domain\Contracts\EnrollmentProviderInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Integrated Mode: keikutsertaan resmi berasal dari penempatan kelas SIAKAD
 * (`class_students`).
 *
 * Pemetaan status akademik → status LMS (§5):
 *   aktif   → ACTIVE
 *   pindah  → SUSPENDED   (data belajar tetap utuh, tidak dihapus)
 *   keluar  → SUSPENDED
 */
class ErpAcademicEnrollmentProvider implements EnrollmentProviderInterface
{
    /** @var array<string, string> */
    private const STATUS_MAP = [
        'aktif' => 'ACTIVE',
        'pindah' => 'SUSPENDED',
        'keluar' => 'SUSPENDED',
    ];

    public function __construct(private readonly ClassProviderInterface $classes) {}

    public function key(): string
    {
        return 'erp';
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('class_students');
    }

    public function isAuthoritative(): bool
    {
        return true;
    }

    public function officialPlacements(?int $academicYearId = null, ?int $classId = null): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $academicYearId ??= $this->classes->activeAcademicYearId();

        return ClassStudent::query()
            ->when($academicYearId !== null, fn (Builder $q) => $q->where('academic_year_id', $academicYearId))
            ->when($classId !== null, fn (Builder $q) => $q->where('school_class_id', $classId))
            ->get()
            ->map(fn (ClassStudent $row) => [
                'student_id' => (int) $row->student_id,
                'class_id' => $row->school_class_id !== null ? (int) $row->school_class_id : null,
                'academic_year_id' => $row->academic_year_id !== null ? (int) $row->academic_year_id : null,
                'semester_id' => null,
                'status' => $this->mapStatus((string) $row->status),
                'source_id' => 'class_students:'.$row->id,
            ])
            ->all();
    }

    public function placementFor(int $studentId, ?int $classId = null, ?int $academicYearId = null): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $row = ClassStudent::query()
            ->where('student_id', $studentId)
            ->when($classId !== null, fn (Builder $q) => $q->where('school_class_id', $classId))
            ->when($academicYearId !== null, fn (Builder $q) => $q->where('academic_year_id', $academicYearId))
            ->orderByDesc('academic_year_id')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'status' => $this->mapStatus((string) $row->status),
            'source_id' => 'class_students:'.$row->id,
            'academic_year_id' => $row->academic_year_id !== null ? (int) $row->academic_year_id : null,
            'semester_id' => null,
        ];
    }

    private function mapStatus(string $status): string
    {
        return self::STATUS_MAP[strtolower($status)] ?? 'SUSPENDED';
    }
}
