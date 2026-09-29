<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Contracts\AcademicGradeProviderInterface;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsGradebook;

/**
 * Jembatan gradebook → modul akademik (§39–§41).
 *
 * Tugasnya hanya dua: menyusun payload sesuai kontrak dan memilih provider
 * yang tepat. Tidak ada query SIAKAD di sini, sehingga Integrated dan
 * Standalone mode memakai kelas yang sama (§63).
 */
class AcademicIntegrationService
{
    public function __construct(
        private readonly FinalGradeService $finalGrades,
        private readonly GradeCalculationService $calculation,
    ) {}

    /**
     * Payload nilai akhir seorang siswa (§40).
     *
     * @return array<string, mixed>
     */
    public function payload(LmsGradebook $gradebook, int $userId): array
    {
        $gradebook->loadMissing('course');
        $course = $gradebook->course;
        $enrollment = LmsEnrollment::query()
            ->where('course_id', $gradebook->course_id)
            ->where('user_id', $userId)
            ->where('role', EnrollmentRole::STUDENT->value)
            ->first();

        $grade = $this->finalGrades->forStudent($gradebook, $userId);
        $teacher = $course?->teachers()->where('is_primary', true)->first()
            ?? $course?->teachers()->first();

        return [
            'contract' => 'academic_grade.v1',
            'generated_at' => now()->toIso8601String(),
            'gradebook' => [
                'id' => (int) $gradebook->id,
                'name' => (string) $gradebook->name,
                'maximum_grade' => (float) $gradebook->maximum_grade,
                'passing_grade' => $gradebook->passingGrade(),
                'grading_method' => $gradebook->grading_method->value,
                'config_hash' => $gradebook->configHash(),
            ],
            'course' => [
                'lms_course_id' => (int) $gradebook->course_id,
                'title' => $course?->name,
            ],
            'academic' => [
                'lms_course_id' => (int) $gradebook->course_id,
                'academic_course_id' => $course?->academic_course_id,
                'class_id' => $course?->class_id,
                'academic_year_id' => $course?->academic_year_id,
                'semester_id' => $course?->semester_id,
                'employee_id' => $teacher?->employee_id ?? $course?->teacher_id,
                'academic_year' => $course?->academic_year_id,
                'semester' => $course?->semester_id,
            ],
            'student' => [
                'user_id' => $userId,
                'student_id' => $enrollment?->student_id,
                'external_student_id' => $enrollment?->student_id,
                'enrollment_id' => $enrollment?->id === null ? null : (int) $enrollment->id,
            ],
            'result' => [
                'final_grade' => $grade['final_score'],
                'normalized_score' => $grade['normalized'],
                'percentage' => $grade['percentage'],
                'letter' => $grade['letter'],
                'predicate' => $grade['predicate'],
                'passed' => $grade['passed'],
                'notes' => $this->notes($grade),
            ],
            'assessment_breakdown' => $grade['blocks'],
            'components' => $this->finalGrades->componentSnapshot($grade),
        ];
    }

    /** Provider yang dipakai instalasi ini (§40, §63). */
    public function provider(): AcademicGradeProviderInterface
    {
        return app(AcademicGradeProviderInterface::class);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $provider = $this->provider();

        return [
            'standalone' => (bool) config('lms.standalone', false),
            'provider' => $provider->key(),
            'provider_label' => $provider->label(),
            'available' => $provider->isAvailable(),
            'push_enabled' => (bool) config('lms.integration.push_grades', false),
            'message' => $provider->isAvailable()
                ? 'Nilai akhir LMS dapat dikirim ke modul akademik.'
                : 'LMS berjalan sebagai sumber nilai mandiri; pengiriman ke SIAKAD tidak aktif.',
        ];
    }

    /** Ringkas rincian nilai untuk kolom catatan SIAKAD. */
    private function notes(array $grade): string
    {
        $parts = [];

        foreach ($grade['blocks'] as $block) {
            if ($block['value'] === null) {
                continue;
            }

            $parts[] = sprintf('%s %s%% = %s', $block['name'], rtrim(rtrim(number_format((float) $block['weight'], 2, '.', ''), '0'), '.'), number_format((float) $block['value'], 2));
        }

        return $parts === [] ? 'Nilai akhir LMS.' : 'Rincian LMS: '.implode('; ', $parts).'.';
    }
}
