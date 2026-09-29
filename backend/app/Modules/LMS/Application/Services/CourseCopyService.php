<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseCopy;
use App\Modules\LMS\Models\LmsCourseTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Salin & impor konten course (§46, §50–§51).
 *
 * Yang disalin hanya STRUKTUR & KONTEN: section, item, materi, dan (opsional)
 * kerangka aktivitas sebagai DRAFT. Yang TIDAK pernah disalin: peserta, nilai,
 * submission, diskusi, dan jejak belajar — itu milik siswa, bukan milik course.
 *
 * Setiap salinan tercatat pada `lms_course_copies` beserta statistiknya supaya
 * guru dapat menelusuri dari mana sebuah course berasal.
 */
class CourseCopyService
{
    use LogsLmsAudit;

    public const MODE_CONTENT = 'CONTENT';

    public const MODE_TEMPLATE = 'TEMPLATE';

    public const MODE_IMPORT = 'IMPORT';

    public function __construct(private readonly CourseTemplateService $templates) {}

    /**
     * Salin konten dari satu course ke course lain (§46).
     *
     * @param  array<string, mixed>  $options  include_content, include_files, include_activities, mode (APPEND|REPLACE)
     * @return array<string, mixed>
     */
    public function copyContent(LmsCourse $source, LmsCourse $target, LmsActor $actor, array $options = []): array
    {
        if ((int) $source->id === (int) $target->id) {
            LmsFault::conflict('Course sumber dan tujuan tidak boleh sama.');
        }

        $options['mode'] = strtoupper((string) ($options['mode'] ?? 'APPEND'));

        if ($options['mode'] === 'REPLACE' && $target->enrollments()->exists()) {
            LmsFault::conflict('Course tujuan sudah memiliki peserta — penghapusan struktur tidak diizinkan.');
        }

        $copy = LmsCourseCopy::query()->create([
            'source_course_id' => $source->id,
            'target_course_id' => $target->id,
            'mode' => self::MODE_CONTENT,
            'options' => $options,
            'status' => 'RUNNING',
            'copied_by' => $actor->id,
            'started_at' => now(),
        ]);

        try {
            $stats = DB::transaction(function () use ($source, $target, $actor, $options): array {
                if ($options['mode'] === 'REPLACE') {
                    foreach ($target->sections as $section) {
                        $section->delete();
                    }
                }

                $payload = $this->templates->snapshotCourse($source, $options);

                return $this->templates->applyPayload($target, $payload, $actor);
            });

            $copy->forceFill([
                'status' => 'COMPLETED',
                'stats' => $stats,
                'finished_at' => now(),
            ])->save();

            $this->audit('LMS_COURSE_CONTENT_COPIED', $copy, [
                'source_course_id' => (int) $source->id,
                'target_course_id' => (int) $target->id,
            ]);

            return ['copy' => $copy->refresh(), 'stats' => $stats];
        } catch (Throwable $e) {
            $copy->forceFill([
                'status' => 'FAILED',
                'error' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }
    }

    /**
     * Bangun course baru dari template (§48) — course target dibuat sebagai DRAFT.
     *
     * @param  array<string, mixed>  $attributes  name, code, category_id, level, language, visibility
     * @return array<string, mixed>
     */
    public function createCourseFromTemplate(LmsCourseTemplate $template, array $attributes, LmsActor $actor): array
    {
        $name = trim((string) ($attributes['name'] ?? ''));

        if ($name === '') {
            LmsFault::invalid('Nama course wajib diisi.', 'name');
        }

        $course = LmsCourse::query()->create([
            'name' => $name,
            'code' => $attributes['code'] ?? 'LMS-'.strtoupper(uniqid()),
            'slug' => $attributes['slug'] ?? Str::slug($name).'-'.strtolower(uniqid()),
            'category_id' => $attributes['category_id'] ?? $template->category_id,
            'course_type' => $attributes['course_type'] ?? 'ACADEMIC',
            'level' => $attributes['level'] ?? 'BEGINNER',
            'language' => $attributes['language'] ?? 'id',
            'status' => 'DRAFT',
            'visibility' => $attributes['visibility'] ?? 'ENROLLED_ONLY',
            'short_description' => $attributes['short_description'] ?? null,
            'description' => $attributes['description'] ?? $template->description,
            'created_by' => $actor->id,
        ]);

        $stats = $this->templates->apply($template, $course, ['mode' => 'APPEND'], $actor);

        $copy = LmsCourseCopy::query()->create([
            'source_course_id' => $template->source_course_id,
            'target_course_id' => $course->id,
            'mode' => self::MODE_TEMPLATE,
            'options' => ['template_id' => $template->id],
            'stats' => $stats,
            'status' => 'COMPLETED',
            'copied_by' => $actor->id,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->audit('LMS_COURSE_CREATED_FROM_TEMPLATE', $course, [
            'template_id' => (int) $template->id,
            'sections' => $stats['sections'] ?? 0,
        ]);

        return ['course' => $course->refresh(), 'stats' => $stats, 'copy' => $copy];
    }

    /** @return list<array<string, mixed>> riwayat salin sebuah course (sebagai sumber atau tujuan) */
    public function history(LmsCourse $course, int $limit = 20): array
    {
        return LmsCourseCopy::query()
            ->where(fn ($query) => $query
                ->where('source_course_id', $course->id)
                ->orWhere('target_course_id', $course->id))
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (LmsCourseCopy $copy) => $this->row($copy))
            ->all();
    }

    public function find(int $id): LmsCourseCopy
    {
        $copy = LmsCourseCopy::query()->find($id);

        if ($copy === null) {
            LmsFault::notFound('Catatan salin tidak ditemukan.');
        }

        return $copy;
    }

    /** @return array<string, mixed> */
    public function row(LmsCourseCopy $copy): array
    {
        return [
            'id' => (int) $copy->id,
            'source_course_id' => $copy->source_course_id === null ? null : (int) $copy->source_course_id,
            'target_course_id' => $copy->target_course_id === null ? null : (int) $copy->target_course_id,
            'mode' => $copy->mode,
            'status' => $copy->status,
            'options' => $copy->options,
            'stats' => $copy->stats,
            'error' => $copy->error,
            'copied_by' => $copy->copied_by === null ? null : (int) $copy->copied_by,
            'started_at' => $copy->started_at?->toIso8601String(),
            'finished_at' => $copy->finished_at?->toIso8601String(),
        ];
    }
}
