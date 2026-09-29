<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\CourseStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsCertificate;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseArchive;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Support\Facades\DB;

/**
 * Arsip course (§49–§51).
 *
 * Mengarsipkan TIDAK menghapus apa pun: course menjadi read-only, snapshot
 * ringkasannya disimpan, dan seluruh riwayat belajar siswa tetap utuh. Karena
 * itu arsip selalu dapat dipulihkan (`restore()`), dan status course
 * dikembalikan persis seperti sebelum diarsipkan.
 */
class CourseArchiveService
{
    use LogsLmsAudit;

    /** Arsipkan course beserta ringkasannya (§49). */
    public function archive(LmsCourse $course, string $reason, LmsActor $actor): LmsCourseArchive
    {
        if ($course->isArchived()) {
            LmsFault::conflict('Course ini sudah diarsipkan.');
        }

        $archive = DB::transaction(function () use ($course, $reason, $actor): LmsCourseArchive {
            $previousStatus = $course->status->value;

            $archive = LmsCourseArchive::query()->create([
                'course_id' => $course->id,
                'reason' => $reason,
                'snapshot' => $this->snapshot($course, $previousStatus),
                'students_count' => (int) $course->students()->count(),
                'items_count' => (int) $course->items()->count(),
                'completions_count' => (int) LmsEnrollment::query()
                    ->where('course_id', $course->id)
                    ->whereNotNull('completed_at')
                    ->count(),
                'archived_by' => $actor->id,
                'archived_at' => now(),
            ]);

            $course->forceFill([
                'status' => CourseStatus::ARCHIVED,
                'archived_at' => now(),
            ])->save();

            return $archive;
        });

        $this->audit('LMS_COURSE_ARCHIVED', $course, ['archive_id' => (int) $archive->id, 'reason' => $reason]);

        return $archive;
    }

    /** Pulihkan course dari arsip — status dikembalikan seperti semula (§50). */
    public function restore(LmsCourseArchive $archive, LmsActor $actor): LmsCourse
    {
        if (! $archive->isActive()) {
            LmsFault::conflict('Arsip ini sudah dipulihkan.');
        }

        $course = $archive->course;

        if ($course === null) {
            LmsFault::notFound('Course pada arsip ini sudah tidak ada.');
        }

        DB::transaction(function () use ($archive, $course, $actor): void {
            $previous = CourseStatus::tryFrom((string) ($archive->snapshot['previous_status'] ?? '')) ?? CourseStatus::DRAFT;

            $course->forceFill([
                'status' => $previous === CourseStatus::ARCHIVED ? CourseStatus::DRAFT : $previous,
                'archived_at' => null,
            ])->save();

            $archive->forceFill([
                'restored_by' => $actor->id,
                'restored_at' => now(),
            ])->save();
        });

        $this->audit('LMS_COURSE_RESTORED', $course, ['archive_id' => (int) $archive->id]);

        return $course->refresh();
    }

    /**
     * Riwayat arsip sebuah course (terbaru lebih dahulu).
     *
     * @return list<array<string, mixed>>
     */
    public function history(LmsCourse $course, int $limit = 20): array
    {
        return LmsCourseArchive::query()
            ->where('course_id', $course->id)
            ->orderByDesc('archived_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (LmsCourseArchive $archive) => $this->row($archive))
            ->all();
    }

    /** Arsip yang masih aktif (belum dipulihkan). */
    public function active(): array
    {
        return LmsCourseArchive::query()
            ->with('course')
            ->whereNull('restored_at')
            ->orderByDesc('archived_at')
            ->get()
            ->map(fn (LmsCourseArchive $archive) => $this->row($archive) + [
                'course_name' => $archive->course?->name,
            ])
            ->all();
    }

    /** Ringkasan satu arsip untuk pratinjau pemulihan (§50). */
    public function summary(LmsCourseArchive $archive): array
    {
        return $this->row($archive) + [
            'course' => $archive->course === null ? null : [
                'id' => (int) $archive->course->id,
                'name' => $archive->course->name,
                'status' => $archive->course->status->value,
            ],
            'snapshot' => $archive->snapshot,
            'can_restore' => $archive->isActive(),
        ];
    }

    /**
     * Bersihkan arsip lama tanpa menyentuh data belajar (§51).
     *
     * Hanya baris arsip yang sudah dipulihkan dan lebih tua dari batas yang
     * dihapus; course & riwayat siswa tidak pernah ikut terhapus.
     */
    public function prune(int $keepDays, LmsActor $actor): int
    {
        $cutoff = now()->subDays(max(1, $keepDays));

        $deleted = LmsCourseArchive::query()
            ->whereNotNull('restored_at')
            ->where('restored_at', '<', $cutoff)
            ->delete();

        if ($deleted > 0) {
            $this->audit('LMS_COURSE_ARCHIVES_PRUNED', null, ['deleted' => $deleted, 'keep_days' => $keepDays]);
        }

        return (int) $deleted;
    }

    // ------------------------------------------------------------------ internal

    /** @return array<string, mixed> */
    private function snapshot(LmsCourse $course, string $previousStatus): array
    {
        return [
            'course_name' => $course->name,
            'course_code' => $course->code,
            'previous_status' => $previousStatus,
            'visibility' => $course->visibility?->value ?? null,
            'sections_count' => (int) $course->sections()->count(),
            'items_count' => (int) $course->items()->count(),
            'activities_count' => (int) $course->items()->where('item_type', 'ACTIVITY')->count(),
            'students_count' => (int) $course->students()->count(),
            'certificates_count' => (int) LmsCertificate::query()->where('course_id', $course->id)->count(),
            'archived_at' => now()->toIso8601String(),
            'generated_by' => 'CourseArchiveService',
        ];
    }

    /** @return array<string, mixed> */
    private function row(LmsCourseArchive $archive): array
    {
        return [
            'id' => (int) $archive->id,
            'course_id' => (int) $archive->course_id,
            'reason' => $archive->reason,
            'students_count' => (int) $archive->students_count,
            'items_count' => (int) $archive->items_count,
            'completions_count' => (int) $archive->completions_count,
            'archived_at' => $archive->archived_at?->toIso8601String(),
            'archived_by' => $archive->archived_by === null ? null : (int) $archive->archived_by,
            'restored_at' => $archive->restored_at?->toIso8601String(),
            'is_active' => $archive->isActive(),
        ];
    }
}
