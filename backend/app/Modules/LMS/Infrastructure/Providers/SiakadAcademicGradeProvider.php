<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Score;
use App\Modules\LMS\Domain\Contracts\AcademicGradeProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Provider SIAKAD (§39–§41).
 *
 * Nilai akhir LMS diteruskan sebagai **satu penilaian** pada modul akademik
 * ERP (`assessments` + `scores`) — bukan laporan hasil belajar baru. KHS/Rapor
 * tetap milik SIAKAD; LMS hanya menyediakan bukti penilaiannya.
 *
 * Penilaian yang dibuat LMS ditandai pada kolom `extra` (`lms_gradebook_id`)
 * sehingga pengiriman ulang bersifat idempotent.
 */
class SiakadAcademicGradeProvider implements AcademicGradeProviderInterface
{
    public function key(): string
    {
        return 'siakad';
    }

    public function label(): string
    {
        return 'Modul Akademik SIAKAD';
    }

    public function isAvailable(): bool
    {
        if ((bool) config('lms.standalone', false)) {
            return false;
        }

        return (bool) config('lms.integration.push_grades', false)
            && class_exists(Assessment::class)
            && class_exists(Score::class);
    }

    /** @param array<string, mixed> $payload */
    public function push(array $payload): array
    {
        if (! $this->isAvailable()) {
            return [
                'accepted' => false,
                'external_type' => null,
                'external_id' => null,
                'external_ref' => null,
                'message' => 'Integrasi nilai ke SIAKAD belum diaktifkan (LMS_INTEGRATION_PUSH_GRADES).',
                'locked' => false,
            ];
        }

        $academic = (array) ($payload['academic'] ?? []);
        $assessment = $this->resolveAssessment($academic, $payload);

        if ($assessment === null) {
            return [
                'accepted' => false,
                'external_type' => 'assessment',
                'external_id' => null,
                'external_ref' => null,
                'message' => 'Course ini belum terhubung ke kelas & mata pelajaran SIAKAD, nilai tetap tersimpan di LMS.',
                'locked' => false,
            ];
        }

        $studentId = (int) ($payload['student']['student_id'] ?? 0);

        if ($studentId <= 0) {
            return [
                'accepted' => false,
                'external_type' => 'assessment',
                'external_id' => (string) $assessment->id,
                'external_ref' => null,
                'message' => 'Identitas siswa SIAKAD tidak ditemukan pada enrollment LMS.',
                'locked' => false,
            ];
        }

        $result = (array) ($payload['result'] ?? []);

        $score = Score::query()->updateOrCreate(
            ['assessment_id' => $assessment->id, 'student_id' => $studentId],
            [
                'score' => $result['final_grade'] ?? null,
                'grade' => $result['letter'] ?? null,
                'predicate' => $result['predicate'] ?? null,
                'notes' => $result['notes'] ?? null,
            ],
        );

        return [
            'accepted' => true,
            'external_type' => 'assessment',
            'external_id' => (string) $assessment->id,
            'external_ref' => 'score:'.$score->id,
            'message' => 'Nilai diterima modul akademik sebagai penilaian "'.$assessment->name.'".',
            'locked' => false,
        ];
    }

    /**
     * Penilaian SIAKAD untuk gradebook ini — dibuat sekali, dipakai ulang
     * (idempotent).
     *
     * @param  array<string, mixed>  $academic
     * @param  array<string, mixed>  $payload
     */
    private function resolveAssessment(array $academic, array $payload): ?Assessment
    {
        $classId = (int) ($academic['class_id'] ?? 0);
        $subjectId = (int) ($academic['academic_course_id'] ?? 0);
        $semesterId = (int) ($academic['semester_id'] ?? 0);

        if ($classId <= 0 || $subjectId <= 0 || $semesterId <= 0) {
            return null;
        }

        $gradebookId = (int) ($payload['gradebook']['id'] ?? 0);

        return DB::transaction(function () use ($classId, $subjectId, $semesterId, $academic, $payload, $gradebookId): Assessment {
            $existing = Assessment::query()
                ->where('school_class_id', $classId)
                ->where('subject_id', $subjectId)
                ->where('semester_id', $semesterId)
                ->where('extra->lms_gradebook_id', $gradebookId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return Assessment::query()->create([
                'school_class_id' => $classId,
                'subject_id' => $subjectId,
                'semester_id' => $semesterId,
                'employee_id' => $academic['employee_id'] ?? null,
                'assessment_type_id' => $this->lmsAssessmentTypeId(),
                'name' => 'LMS: '.(string) ($payload['gradebook']['name'] ?? 'Gradebook'),
                'description' => 'Nilai akhir dari modul LMS (bukti penilaian; KHS tetap dikelola SIAKAD).',
                'max_score' => (float) ($payload['gradebook']['maximum_grade'] ?? 100),
                'weight' => null,
                'date' => now()->toDateString(),
                'status' => 'draft',
                'extra' => [
                    'lms_gradebook_id' => $gradebookId,
                    'lms_course_id' => $academic['lms_course_id'] ?? null,
                    'source' => 'lms',
                ],
            ]);
        });
    }

    /** Jenis penilaian netral untuk nilai LMS ("tugas" bila ada). */
    private function lmsAssessmentTypeId(): int
    {
        $type = AssessmentType::query()->where('code', 'tugas')->first()
            ?? AssessmentType::query()->where('is_active', true)->orderBy('sort_order')->first();

        return (int) ($type?->id ?? 0);
    }
}
