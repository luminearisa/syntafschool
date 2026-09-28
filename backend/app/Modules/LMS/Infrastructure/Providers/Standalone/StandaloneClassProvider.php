<?php

namespace App\Modules\LMS\Infrastructure\Providers\Standalone;

use App\Modules\LMS\Domain\Contracts\ClassProviderInterface;
use App\Modules\LMS\Models\LmsLearner;

/**
 * Standalone Mode: tidak ada SIAKAD, jadi referensi kelas/tahun ajaran diambil
 * dari label yang tersimpan di profil peserta (`lms_learners`). Pemilih kelas
 * di frontend tetap berfungsi tanpa tabel ERP.
 */
class StandaloneClassProvider implements ClassProviderInterface
{
    public function options(?int $academicYearId = null): array
    {
        return LmsLearner::query()
            ->where('learner_type', LmsLearner::TYPE_STUDENT)
            ->whereNotNull('class_label')
            ->orderBy('class_label')
            ->pluck('class_label')
            ->unique()
            ->values()
            ->map(fn (string $label, int $index) => [
                'id' => $index + 1,
                'name' => $label,
                'code' => null,
                'academic_year_id' => null,
                'academic_year' => null,
                'education_level' => null,
            ])
            ->all();
    }

    public function find(int $classId): ?array
    {
        $options = $this->options();

        return $options[$classId - 1] ?? null;
    }

    public function activeAcademicYearId(): ?int
    {
        return null;
    }

    public function academicYearOptions(): array
    {
        return [];
    }

    public function programOptions(): array
    {
        return LmsLearner::query()
            ->whereNotNull('program_label')
            ->pluck('program_label')
            ->unique()
            ->values()
            ->map(fn (string $label, int $index) => ['id' => $index + 1, 'name' => $label, 'code' => null])
            ->all();
    }

    public function subjectOptions(?int $educationLevelId = null): array
    {
        return [];
    }

    public function semesterOptions(?int $academicYearId = null): array
    {
        return [];
    }
}
