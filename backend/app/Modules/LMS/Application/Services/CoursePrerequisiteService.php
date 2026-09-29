<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Application\Validation\PrerequisiteGraphService;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCoursePrerequisite;
use App\Modules\LMS\Models\LmsCourseProgress;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Support\Facades\DB;

/**
 * Prasyarat antar-course (§18).
 *
 * Gerbang ini **tidak** menghitung apa pun sendiri: ia membaca status
 * ketuntasan yang sudah dihitung `CourseProgressService`, sehingga tidak ada
 * mesin progres kedua. Siklus ditolak sebelum aturan disimpan supaya siswa
 * tidak pernah terjebak pada rantai yang mustahil diselesaikan.
 */
class CoursePrerequisiteService
{
    use LogsLmsAudit;

    public function __construct(private readonly PrerequisiteGraphService $graph) {}

    /**
     * Status prasyarat seorang pelajar terhadap sebuah course.
     *
     * @return array{satisfied:bool,required:list<array<string,mixed>>,missing:list<array<string,mixed>>}
     */
    public function statusFor(LmsCourse $course, LmsActor $actor): array
    {
        $requirements = $course->prerequisites()->with('prerequisite:id,name,code,completion_rule')->get();

        if ($requirements->isEmpty()) {
            return ['satisfied' => true, 'required' => [], 'missing' => []];
        }

        $completedIds = $this->completedCourseIds($actor);

        $required = [];
        $missing = [];

        foreach ($requirements as $requirement) {
            $prerequisite = $requirement->prerequisite;

            if ($prerequisite === null) {
                continue;
            }

            $entry = [
                'course_id' => (int) $prerequisite->id,
                'name' => $prerequisite->name,
                'code' => $prerequisite->code,
                'is_enforced' => (bool) $requirement->is_enforced,
                'minimum_progress' => (float) $requirement->minimum_progress,
                'completed' => in_array((int) $prerequisite->id, $completedIds, true),
            ];

            $required[] = $entry;

            if ($entry['is_enforced'] && ! $entry['completed']) {
                $missing[] = $entry;
            }
        }

        return [
            'satisfied' => $missing === [],
            'required' => $required,
            'missing' => $missing,
        ];
    }

    /**
     * Apakah `prerequisiteCourseId` boleh dijadikan prasyarat course ini?
     * Mengembalikan jalur siklus bila penambahan itu membuat lingkaran.
     *
     * @return list<int>|null
     */
    public function cycleIfAttached(LmsCourse $course, int $prerequisiteCourseId): ?array
    {
        if ((int) $course->id === $prerequisiteCourseId) {
            return [(int) $course->id];
        }

        $graph = [];

        foreach (DB::table('lms_course_prerequisites')->get(['course_id', 'prerequisite_course_id']) as $row) {
            $graph[(int) $row->course_id][] = (int) $row->prerequisite_course_id;
        }

        $graph[(int) $course->id] = array_values(array_unique(array_merge(
            $graph[(int) $course->id] ?? [],
            [$prerequisiteCourseId],
        )));

        // Deteksi memakai graf yang sudah disisipkan aturan baru.
        return (new PrerequisiteCycleProbe($graph))->firstCycle();
    }

    /**
     * Tetapkan prasyarat (idempotent: aturan yang sama tidak digandakan).
     *
     * @return array{attached:bool,cycle:list<int>|null,rule:array<string,mixed>|null}
     */
    public function attach(LmsCourse $course, LmsCourse $prerequisite, LmsActor $actor, bool $enforced = true, float $minimumProgress = 100): array
    {
        if (! $actor->can('lms.course.prerequisite.manage') && ! $actor->can('lms.manage')) {
            LmsFault::forbidden('Anda tidak berhak mengatur prasyarat course.');
        }

        $cycle = $this->cycleIfAttached($course, (int) $prerequisite->id);

        if ($cycle !== null) {
            $labels = LmsCourse::query()->whereIn('id', $cycle)->pluck('name', 'id')->all();

            return [
                'attached' => false,
                'cycle' => $cycle,
                'rule' => null,
                'message' => 'Prasyarat ini membentuk siklus: '.implode(' → ', array_map(
                    fn (int $id) => $labels[$id] ?? ('#'.$id),
                    $cycle,
                )).'.',
            ];
        }

        $rule = LmsCoursePrerequisite::query()->updateOrCreate(
            ['course_id' => $course->id, 'prerequisite_course_id' => $prerequisite->id],
            [
                'is_enforced' => $enforced,
                'minimum_progress' => max(0, min(100, $minimumProgress)),
                'created_by' => $actor->id,
            ],
        );

        $this->audit('LMS_COURSE_PREREQUISITE_SET', $course, [
            'prerequisite_course_id' => (int) $prerequisite->id,
            'enforced' => $enforced,
            'minimum_progress' => $rule->minimum_progress,
        ]);

        return [
            'attached' => true,
            'cycle' => null,
            'rule' => [
                'id' => (int) $rule->id,
                'course_id' => (int) $rule->course_id,
                'prerequisite_course_id' => (int) $rule->prerequisite_course_id,
                'is_enforced' => (bool) $rule->is_enforced,
                'minimum_progress' => (float) $rule->minimum_progress,
            ],
        ];
    }

    public function detach(LmsCourse $course, LmsCourse $prerequisite, LmsActor $actor): bool
    {
        if (! $actor->can('lms.course.prerequisite.manage') && ! $actor->can('lms.manage')) {
            LmsFault::forbidden('Anda tidak berhak mengatur prasyarat course.');
        }

        $deleted = LmsCoursePrerequisite::query()
            ->where('course_id', $course->id)
            ->where('prerequisite_course_id', $prerequisite->id)
            ->delete();

        if ($deleted > 0) {
            $this->audit('LMS_COURSE_PREREQUISITE_REMOVED', $course, [
                'prerequisite_course_id' => (int) $prerequisite->id,
            ]);
        }

        return $deleted > 0;
    }

    /**
     * Course yang selesai oleh pelajar (dipakai juga oleh laporan).
     *
     * @return list<int>
     */
    public function completedCourseIds(LmsActor $actor): array
    {
        if ($actor->studentId === null && ! $actor->isStudent()) {
            return [];
        }

        $enrollments = LmsEnrollment::query()
            ->where('user_id', $actor->id)
            ->where('role', EnrollmentRole::STUDENT->value)
            ->where(function ($query): void {
                $query->where('status', 'COMPLETED')
                    ->orWhereHas('progress', fn ($progress) => $progress->where('progress_percent', '>=', 100));
            })
            ->pluck('course_id');

        $completed = $enrollments->map(fn ($id) => (int) $id)->all();

        $finished = LmsCourseProgress::query()
            ->where('user_id', $actor->id)
            ->where('progress_percent', '>=', 100)
            ->pluck('course_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($completed, $finished)));
    }
}
