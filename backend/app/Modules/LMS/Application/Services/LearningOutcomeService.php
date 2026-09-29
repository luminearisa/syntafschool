<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityOutcome;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsLearningOutcome;
use Illuminate\Support\Facades\DB;

/**
 * Learning outcome & pemetaannya (§26–§27).
 *
 * Satu aktivitas dapat memetakan banyak outcome, dan satu outcome dapat
 * dipenuhi banyak aktivitas — peta inilah yang membuat capaian siswa punya
 * bukti, bukan sekadar angka.
 */
class LearningOutcomeService
{
    use LogsLmsAudit;

    /** @return list<array<string, mixed>> */
    public function forCourse(LmsCourse $course, bool $onlyActive = false): array
    {
        return LmsLearningOutcome::query()
            ->where('course_id', $course->id)
            ->when($onlyActive, fn ($query) => $query->where('status', 'ACTIVE'))
            ->with('mappings')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (LmsLearningOutcome $outcome) => $outcome->toStructure())
            ->all();
    }

    /** @param array<string, mixed> $data */
    public function create(LmsCourse $course, array $data, LmsActor $actor): LmsLearningOutcome
    {
        $outcome = LmsLearningOutcome::query()->create([
            'course_id' => $course->id,
            'code' => $data['code'] ?? 'LO'.((int) LmsLearningOutcome::query()->where('course_id', $course->id)->max('sort_order') + 1),
            'statement' => $data['statement'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'achievement_thresholds' => $data['achievement_thresholds'] ?? null,
            'is_required' => (bool) ($data['is_required'] ?? true),
            'sort_order' => $data['sort_order'] ?? ((int) LmsLearningOutcome::query()->where('course_id', $course->id)->max('sort_order') + 1),
            'status' => $data['status'] ?? 'ACTIVE',
            'created_by' => $actor->id,
        ]);

        if (! empty($data['activity_ids'])) {
            $this->mapActivities($outcome, (array) $data['activity_ids'], $actor);
        }

        $this->audit('lms.outcome.created', $outcome, ['course_id' => (int) $course->id]);

        return $outcome->refresh()->load('mappings');
    }

    /** @param array<string, mixed> $data */
    public function update(LmsLearningOutcome $outcome, array $data, LmsActor $actor): LmsLearningOutcome
    {
        $outcome->fill($data)->save();

        if (array_key_exists('activity_ids', $data)) {
            $this->mapActivities($outcome, (array) $data['activity_ids'], $actor);
        }

        $this->audit('lms.outcome.updated', $outcome, ['code' => $outcome->code]);

        return $outcome->refresh()->load('mappings');
    }

    public function delete(LmsLearningOutcome $outcome, LmsActor $actor): void
    {
        $this->audit('lms.outcome.deleted', $outcome, ['code' => $outcome->code]);

        $outcome->delete();
    }

    /**
     * Petakan aktivitas ke outcome (§27) — idempoten, aman dipanggil berulang.
     *
     * @param  list<int|string>  $activityIds
     * @return list<array<string, mixed>>
     */
    public function mapActivities(LmsLearningOutcome $outcome, array $activityIds, LmsActor $actor): array
    {
        $courseId = (int) $outcome->course_id;

        $valid = LmsActivity::query()
            ->where('course_id', $courseId)
            ->whereIn('id', array_map('intval', $activityIds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($valid) !== count(array_unique(array_map('intval', $activityIds)))) {
            LmsFault::invalid('Ada aktivitas yang bukan milik course ini.', 'activity_ids');
        }

        DB::transaction(function () use ($outcome, $valid, $actor): void {
            LmsActivityOutcome::query()
                ->where('learning_outcome_id', $outcome->id)
                ->whereNotIn('activity_id', $valid)
                ->delete();

            foreach ($valid as $activityId) {
                LmsActivityOutcome::query()->updateOrCreate(
                    ['activity_id' => $activityId, 'learning_outcome_id' => $outcome->id],
                    ['weight' => 1, 'mapped_by' => $actor->id],
                );
            }
        });

        $this->audit('lms.outcome.mapped', $outcome, ['activities' => $valid]);

        return $this->forCourse($outcome->course()->firstOrFail());
    }

    /** Outcome yang dipetakan ke sebuah aktivitas (§27). */
    public function forActivity(LmsActivity $activity): array
    {
        return LmsActivityOutcome::query()
            ->where('activity_id', $activity->id)
            ->with('outcome')
            ->get()
            ->map(fn (LmsActivityOutcome $mapping) => [
                'outcome_id' => (int) $mapping->learning_outcome_id,
                'code' => $mapping->outcome?->code,
                'statement' => $mapping->outcome?->statement,
                'weight' => (float) $mapping->weight,
            ])
            ->all();
    }

    /** Ringkasan peta outcome sebuah course (dipakai UI guru). */
    public function coverage(LmsCourse $course): array
    {
        $outcomes = LmsLearningOutcome::query()
            ->where('course_id', $course->id)
            ->with('mappings.activity')
            ->orderBy('sort_order')
            ->get();

        return [
            'outcome_count' => $outcomes->count(),
            'mapped_activity_count' => $outcomes->sum(fn (LmsLearningOutcome $outcome) => $outcome->mappings->count()),
            'unmapped_activities' => LmsActivity::query()
                ->where('course_id', $course->id)
                ->whereDoesntHave('outcomeMappings')
                ->pluck('title')
                ->all(),
            'outcomes' => $outcomes->map(fn (LmsLearningOutcome $outcome) => $outcome->toStructure() + [
                'activities' => $outcome->mappings->map(fn (LmsActivityOutcome $mapping) => [
                    'activity_id' => (int) $mapping->activity_id,
                    'title' => $mapping->activity?->title,
                ])->all(),
            ])->all(),
        ];
    }
}
