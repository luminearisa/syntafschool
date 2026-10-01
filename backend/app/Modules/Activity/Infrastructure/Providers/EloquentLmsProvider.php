<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Models\LmsCourse;
use App\Modules\Activity\Domain\Contracts\LmsProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pemetaan aktivitas ↔ kursus LMS (§58).
 *
 * Materi, kuis, dan nilai pembelajaran tetap milik LMS. Yang dikerjakan di sini
 * hanya dua: membaca kursus yang dipetakan, dan menyelaraskan keanggotaan siswa
 * agar mereka otomatis punya akses materi.
 */
class EloquentLmsProvider implements LmsProviderInterface
{
    public function course(int $courseId): ?array
    {
        $course = LmsCourse::query()->find($courseId);

        if ($course === null) {
            return null;
        }

        return [
            'id' => (int) $course->id,
            'code' => $course->code,
            'name' => (string) $course->name,
            'status' => $course->status,
        ];
    }

    public function enroll(int $courseId, array $studentIds, array $options = []): array
    {
        if (! $this->isAvailable()) {
            return ['enrolled' => 0, 'skipped' => count($studentIds), 'reason' => 'LMS belum aktif.'];
        }

        $enrolled = 0;

        foreach (array_unique($studentIds) as $studentId) {
            $exists = DB::table('lms_enrollments')
                ->where('course_id', $courseId)
                ->where('student_id', $studentId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('lms_enrollments')->insert([
                'course_id' => $courseId,
                'student_id' => $studentId,
                'role' => $options['role'] ?? 'student',
                'enrollment_method' => 'activity',
                'status' => 'active',
                'enrolled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $enrolled++;
        }

        return ['enrolled' => $enrolled, 'skipped' => 0, 'course_id' => $courseId];
    }

    public function unenroll(int $courseId, array $studentIds): array
    {
        if (! $this->isAvailable()) {
            return ['unenrolled' => 0, 'reason' => 'LMS belum aktif.'];
        }

        $affected = DB::table('lms_enrollments')
            ->where('course_id', $courseId)
            ->whereIn('student_id', $studentIds)
            ->update(['status' => 'suspended', 'updated_at' => now()]);

        return ['unenrolled' => $affected, 'course_id' => $courseId];
    }

    public function progress(int $courseId, int $studentId): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $row = DB::table('lms_enrollments')
            ->where('course_id', $courseId)
            ->where('student_id', $studentId)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'status' => $row->status,
            'progress_percent' => $row->progress_percent === null ? null : (float) $row->progress_percent,
            'completed_at' => $row->completed_at,
        ];
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('lms_courses') && Schema::hasTable('lms_enrollments');
    }
}
