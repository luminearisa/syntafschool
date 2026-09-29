<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityCompetency;
use App\Modules\LMS\Models\LmsCompetency;
use App\Modules\LMS\Models\LmsCompetencyFramework;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseCompetency;
use Illuminate\Support\Facades\DB;

/**
 * Kerangka kompetensi & pemetaannya (§29).
 *
 * Struktur: Competency Framework → Competency → Course → Activity.
 * Nilai dan kompetensi tetap dua konsep berbeda (§30); kelas ini hanya
 * mengelola kerangka dan pemetaannya, bukan penetapan status siswa.
 */
class CompetencyService
{
    use LogsLmsAudit;

    /** @return list<array<string, mixed>> */
    public function frameworks(): array
    {
        return LmsCompetencyFramework::query()
            ->with('competencies')
            ->orderBy('name')
            ->get()
            ->map(fn (LmsCompetencyFramework $framework) => $this->frameworkPayload($framework))
            ->all();
    }

    /** @return array<string, mixed> */
    public function frameworkPayload(LmsCompetencyFramework $framework): array
    {
        $framework->loadMissing('competencies.children');

        return [
            'id' => (int) $framework->id,
            'code' => $framework->code,
            'name' => $framework->name,
            'description' => $framework->description,
            'status' => $framework->status,
            'competency_count' => $framework->competencies->count(),
            'competencies' => $framework->competencies
                ->whereNull('parent_id')
                ->map(fn (LmsCompetency $competency) => $this->competencyPayload($competency))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function competencyPayload(LmsCompetency $competency): array
    {
        $competency->loadMissing('children');

        return [
            'id' => (int) $competency->id,
            'framework_id' => (int) $competency->framework_id,
            'parent_id' => $competency->parent_id === null ? null : (int) $competency->parent_id,
            'code' => $competency->code,
            'name' => $competency->name,
            'description' => $competency->description,
            'status' => $competency->status,
            'sort_order' => (int) $competency->sort_order,
            'children' => $competency->children->map(fn (LmsCompetency $child) => [
                'id' => (int) $child->id,
                'code' => $child->code,
                'name' => $child->name,
                'description' => $child->description,
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function createFramework(array $data, LmsActor $actor): LmsCompetencyFramework
    {
        $framework = LmsCompetencyFramework::query()->create([
            'code' => $data['code'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'ACTIVE',
            'created_by' => $actor->id,
        ]);

        $this->audit('lms.competency.framework_created', $framework, ['name' => $framework->name]);

        return $framework->refresh();
    }

    /** @param array<string, mixed> $data */
    public function createCompetency(LmsCompetencyFramework $framework, array $data, LmsActor $actor): LmsCompetency
    {
        $competency = LmsCompetency::query()->create([
            'framework_id' => $framework->id,
            'parent_id' => $data['parent_id'] ?? null,
            'code' => $data['code'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? ((int) $framework->competencies()->max('sort_order') + 1),
            'status' => $data['status'] ?? 'ACTIVE',
        ]);

        $this->audit('lms.competency.created', $competency, ['framework_id' => (int) $framework->id]);

        return $competency->refresh();
    }

    /** @param array<string, mixed> $data */
    public function updateCompetency(LmsCompetency $competency, array $data, LmsActor $actor): LmsCompetency
    {
        if (array_key_exists('parent_id', $data) && (int) $data['parent_id'] === (int) $competency->id) {
            LmsFault::invalid('Kompetensi tidak dapat menjadi induk dirinya sendiri.', 'parent_id');
        }

        $competency->fill($data)->save();

        $this->audit('lms.competency.updated', $competency, ['name' => $competency->name]);

        return $competency;
    }

    /**
     * Petakan kompetensi ke course (§29) — idempoten.
     *
     * @param  list<int|string>  $competencyIds
     */
    public function mapToCourse(LmsCourse $course, array $competencyIds, LmsActor $actor): array
    {
        $ids = array_map('intval', $competencyIds);

        $valid = LmsCompetency::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (count($valid) !== count(array_unique($ids))) {
            LmsFault::invalid('Ada kompetensi yang tidak dikenal.', 'competency_ids');
        }

        DB::transaction(function () use ($course, $valid, $actor): void {
            LmsCourseCompetency::query()
                ->where('course_id', $course->id)
                ->whereNotIn('competency_id', $valid)
                ->delete();

            foreach ($valid as $competencyId) {
                LmsCourseCompetency::query()->updateOrCreate(
                    ['course_id' => $course->id, 'competency_id' => $competencyId],
                    ['is_required' => true, 'weight' => 1, 'mapped_by' => $actor->id],
                );
            }
        });

        $this->audit('lms.competency.mapped_course', $course, ['competencies' => $valid]);

        return $this->forCourse($course);
    }

    /**
     * Petakan kompetensi ke aktivitas (§29).
     *
     * @param  list<int|string>  $competencyIds
     */
    public function mapToActivity(LmsActivity $activity, array $competencyIds, LmsActor $actor): array
    {
        $ids = array_map('intval', $competencyIds);

        $valid = LmsCompetency::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($activity, $valid, $actor): void {
            LmsActivityCompetency::query()
                ->where('activity_id', $activity->id)
                ->whereNotIn('competency_id', $valid)
                ->delete();

            foreach ($valid as $competencyId) {
                LmsActivityCompetency::query()->updateOrCreate(
                    ['activity_id' => $activity->id, 'competency_id' => $competencyId],
                    ['weight' => 1, 'mapped_by' => $actor->id],
                );
            }
        });

        $this->audit('lms.competency.mapped_activity', $activity, ['competencies' => $valid]);

        return $this->forActivity($activity);
    }

    /** @return list<array<string, mixed>> */
    public function forCourse(LmsCourse $course): array
    {
        return LmsCourseCompetency::query()
            ->where('course_id', $course->id)
            ->with('competency.framework')
            ->get()
            ->map(fn (LmsCourseCompetency $mapping) => [
                'competency_id' => (int) $mapping->competency_id,
                'code' => $mapping->competency?->code,
                'name' => $mapping->competency?->name,
                'description' => $mapping->competency?->description,
                'framework' => $mapping->competency?->framework?->name,
                'is_required' => (bool) $mapping->is_required,
                'weight' => (float) $mapping->weight,
                'activity_ids' => LmsActivityCompetency::query()
                    ->where('competency_id', $mapping->competency_id)
                    ->whereHas('activity', fn ($query) => $query->where('course_id', $course->id))
                    ->pluck('activity_id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function forActivity(LmsActivity $activity): array
    {
        return LmsActivityCompetency::query()
            ->where('activity_id', $activity->id)
            ->with('competency')
            ->get()
            ->map(fn (LmsActivityCompetency $mapping) => [
                'competency_id' => (int) $mapping->competency_id,
                'code' => $mapping->competency?->code,
                'name' => $mapping->competency?->name,
                'weight' => (float) $mapping->weight,
            ])
            ->all();
    }
}
