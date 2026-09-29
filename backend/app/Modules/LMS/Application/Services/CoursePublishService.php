<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Application\Validation\ContentValidationService;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Enums\CourseStatus;
use App\Modules\LMS\Domain\Enums\CourseVisibility;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsCourse;
use Illuminate\Validation\ValidationException;

/**
 * Penerbitan & pengarsipan course (§7, §36).
 *
 * Course tidak boleh terbit setengah matang, jadi kelayakan diperiksa lebih
 * dulu dan hasilnya dipakai UI sebagai daftar periksa — bukan sekadar error
 * saat tombol Publish ditekan.
 */
class CoursePublishService
{
    use LogsLmsAudit;

    public function __construct(private readonly ContentValidationService $validation) {}

    /**
     * Daftar periksa kelayakan terbit.
     *
     * @return array{ready: bool, checks: list<array<string, mixed>>}
     */
    public function readiness(LmsCourse $course): array
    {
        $sections = (int) $course->sections()->count();
        $items = (int) $course->items()->count();
        $activities = (int) $course->items()->where('item_type', 'ACTIVITY')->count();
        $students = (int) $course->enrollments()->where('role', 'STUDENT')->count();
        $teachers = (int) $course->teachers()->count();

        $checks = [
            $this->check('identity', 'Nama course terisi', filled($course->name)),
            $this->check('description', 'Deskripsi singkat terisi',
                filled($course->short_description ?? $course->description), required: false),
            $this->check('category', 'Kategori dipilih', $course->category_id !== null, required: false),
            $this->check('teacher', 'Guru pengampu ditetapkan', $teachers > 0 || $course->teacher_id !== null),
            $this->check('content', 'Minimal satu bagian berisi materi', $sections > 0 && $items > 0),
            $this->check('completion', 'Semua materi punya aturan penyelesaian',
                $course->items()->whereNull('completion_type')->count() === 0),
            $this->check('schedule', 'Tanggal mulai & selesai konsisten',
                $course->start_date === null || $course->end_date === null || $course->end_date->gte($course->start_date)),
            $this->check('students', 'Peserta sudah terdaftar atau course bersifat publik',
                $students > 0 || $course->visibility === CourseVisibility::PUBLIC, required: false),
        ];

        $blocking = array_filter($checks, fn (array $check) => ! $check['ok'] && $check['required']);

        // Validasi mendalam (§17): siklus prasyarat, materi rusak, kuis tanpa
        // soal, aturan penyelesaian mustahil. Hanya temuan ERROR yang menahan.
        $validation = $this->validation->validateCourse($course);

        return [
            'ready' => $blocking === [] && $validation['publishable'],
            'checks' => $checks,
            'validation' => $validation['report'],
            'validation_scopes' => $validation['scopes'],
            'stats' => [
                'sections' => $sections,
                'items' => $items,
                'activities' => $activities,
                'resources' => $items - $activities,
                'students' => $students,
            ],
        ];
    }

    public function publish(LmsCourse $course, LmsActor $actor, bool $force = false): LmsCourse
    {
        $readiness = $this->readiness($course);

        if (! $readiness['ready'] && ! $force) {
            $problems = array_map(
                fn (array $check) => $check['label'],
                array_filter($readiness['checks'], fn (array $check) => ! $check['ok'] && $check['required']),
            );

            foreach ($readiness['validation']['issues'] ?? [] as $issue) {
                if (($issue['blocks_publication'] ?? false) === true) {
                    $problems[] = $issue['message'].($issue['fix'] !== null ? ' ('.$issue['fix'].')' : '');
                }
            }

            throw ValidationException::withMessages([
                'course' => 'Course belum siap diterbitkan: '.implode('; ', array_unique($problems)),
                'validation' => $problems,
            ]);
        }

        $course->forceFill([
            'status' => CourseStatus::PUBLISHED->value,
            'published_at' => $course->published_at ?? now(),
            'archived_at' => null,
        ])->save();

        $this->audit('LMS_COURSE_PUBLISHED', $course, [
            'forced' => $force,
            'checks' => $readiness['checks'],
            'validation_counts' => $readiness['validation']['counts'] ?? null,
            'validation_issues' => array_map(
                fn (array $issue) => $issue['code'],
                $readiness['validation']['issues'] ?? [],
            ),
        ]);

        return $course->fresh();
    }

    public function unpublish(LmsCourse $course, LmsActor $actor): LmsCourse
    {
        $course->forceFill([
            'status' => CourseStatus::DRAFT->value,
            'published_at' => null,
        ])->save();

        $this->audit('LMS_COURSE_UNPUBLISHED', $course);

        return $course->fresh();
    }

    public function archive(LmsCourse $course, LmsActor $actor): LmsCourse
    {
        $course->forceFill([
            'status' => CourseStatus::ARCHIVED->value,
            'archived_at' => now(),
        ])->save();

        $this->audit('LMS_COURSE_ARCHIVED', $course);

        return $course->fresh();
    }

    public function restore(LmsCourse $course, LmsActor $actor): LmsCourse
    {
        $course->forceFill([
            'status' => CourseStatus::DRAFT->value,
            'archived_at' => null,
        ])->save();

        $this->audit('LMS_COURSE_RESTORED', $course);

        return $course->fresh();
    }

    /** @return array<string, mixed> */
    protected function check(string $key, string $label, bool $ok, bool $required = true): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'required' => $required];
    }
}
