<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Domain\Enums\GradeItemSource;
use App\Modules\LMS\Domain\Enums\GradeItemVisibility;
use App\Modules\LMS\Domain\Enums\GradingMethod;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsAssignment;
use App\Modules\LMS\Models\LmsGradebook;
use App\Modules\LMS\Models\LmsGradeCategory;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsQuiz;

/**
 * Grade item (§3): "sesuatu yang menghasilkan nilai".
 *
 * Item dari aktivitas dibuat otomatis (idempoten) sehingga setiap aktivitas
 * bernilai selalu punya tempat di gradebook, sementara guru tetap dapat
 * menambah item MANUAL/EXTERNAL untuk penilaian di luar LMS.
 */
class GradeItemService
{
    use LogsLmsAudit;

    /** Kategori bawaan per sumber nilai (§4 contoh struktur). */
    private const DEFAULT_CATEGORIES = [
        'ASSIGNMENT' => ['name' => 'Tugas', 'weight' => 30, 'sort' => 1],
        'QUIZ' => ['name' => 'Kuis', 'weight' => 20, 'sort' => 2],
        'FORUM' => ['name' => 'Partisipasi', 'weight' => 10, 'sort' => 3],
        'LESSON' => ['name' => 'Aktivitas Lesson', 'weight' => 10, 'sort' => 4],
        'MANUAL' => ['name' => 'Penilaian Manual', 'weight' => 30, 'sort' => 5],
        'EXTERNAL' => ['name' => 'Nilai Eksternal', 'weight' => 0, 'sort' => 6],
    ];

    /** Item milik aktivitas ini, dibuat bila belum ada. */
    public function ensureForActivity(LmsActivity $activity, ?int $actorId = null): LmsGradeItem
    {
        $existing = LmsGradeItem::query()->where('activity_id', $activity->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $course = $activity->course()->firstOrFail();
        $gradebook = app(GradebookService::class)->ensureForCourse($course, $actorId);

        // Bila gradebook baru saja dibuat, `ensureForCourse` menyinkronkan
        // aktivitas yang sudah ada dan bisa saja sudah membuat item untuk
        // aktivitas ini — cek ulang agar tidak melanggar unique `activity_id`.
        $existing = LmsGradeItem::query()->where('activity_id', $activity->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $source = $this->sourceFor($activity);
        $category = $this->defaultCategory($gradebook, $source, $actorId);

        $item = LmsGradeItem::query()->create([
            'gradebook_id' => $gradebook->id,
            'category_id' => $category?->id,
            'activity_id' => $activity->id,
            'source' => $source,
            'name' => (string) $activity->title,
            'description' => $activity->description,
            'maximum_grade' => $this->maximumFor($activity),
            'passing_grade' => $this->passingFor($activity),
            'weight' => (float) ($category?->weight ?? 0) > 0 ? 0 : 0,
            'grade_type' => GradingMethod::POINTS,
            'visibility' => GradeItemVisibility::VISIBLE,
            'is_extra_credit' => false,
            'include_in_final' => (bool) $activity->is_graded,
            'sort_order' => (int) $gradebook->items()->max('sort_order') + 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        $this->audit('lms.grade_item.created', $item, [
            'activity_id' => (int) $activity->id,
            'source' => $source->value,
            'auto' => true,
        ]);

        return $item;
    }

    /**
     * Pastikan seluruh aktivitas bernilai pada course sudah punya grade item
     * (§64: tidak ada aktivitas bernilai yang luput dari gradebook).
     */
    public function syncFromCourse(LmsGradebook $gradebook, ?int $actorId = null): int
    {
        $activities = LmsActivity::query()
            ->where('course_id', $gradebook->course_id)
            ->where('is_graded', true)
            ->orderBy('due_at')
            ->get();

        $created = 0;

        foreach ($activities as $activity) {
            if (LmsGradeItem::query()->where('activity_id', $activity->id)->exists()) {
                continue;
            }

            LmsGradeItem::query()->create([
                'gradebook_id' => $gradebook->id,
                'category_id' => $this->defaultCategory($gradebook, $this->sourceFor($activity), $actorId)?->id,
                'activity_id' => $activity->id,
                'source' => $this->sourceFor($activity),
                'name' => (string) $activity->title,
                'description' => $activity->description,
                'maximum_grade' => $this->maximumFor($activity),
                'passing_grade' => $this->passingFor($activity),
                'grade_type' => GradingMethod::POINTS,
                'visibility' => GradeItemVisibility::VISIBLE,
                'include_in_final' => true,
                'sort_order' => (int) $gradebook->items()->max('sort_order') + 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $created++;
        }

        if ($created > 0) {
            $this->audit('lms.grade_item.synced', $gradebook, ['created' => $created]);
        }

        return $created;
    }

    /** @param array<string, mixed> $data */
    public function create(LmsGradebook $gradebook, array $data, LmsActor $actor): LmsGradeItem
    {
        $this->assertEditable($gradebook, $actor);

        $source = GradeItemSource::tryFrom((string) ($data['source'] ?? 'MANUAL')) ?? GradeItemSource::MANUAL;

        $item = LmsGradeItem::query()->create([
            'gradebook_id' => $gradebook->id,
            'category_id' => $data['category_id'] ?? $this->defaultCategory($gradebook, $source, $actor->id)?->id,
            'activity_id' => $data['activity_id'] ?? null,
            'source' => $source,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'maximum_grade' => $data['maximum_grade'] ?? 100,
            'passing_grade' => $data['passing_grade'] ?? null,
            'weight' => $data['weight'] ?? 0,
            'aggregation_method' => $data['aggregation_method'] ?? null,
            'grade_type' => $data['grade_type'] ?? GradingMethod::POINTS,
            'visibility' => $data['visibility'] ?? GradeItemVisibility::VISIBLE,
            'is_extra_credit' => (bool) ($data['is_extra_credit'] ?? false),
            'include_in_final' => (bool) ($data['include_in_final'] ?? true),
            'rubric_id' => $data['rubric_id'] ?? null,
            'sort_order' => $data['sort_order'] ?? ((int) $gradebook->items()->max('sort_order') + 1),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->audit('lms.grade_item.created', $item, ['name' => $item->name, 'source' => $source->value]);

        return $item;
    }

    /** @param array<string, mixed> $data */
    public function update(LmsGradeItem $item, array $data, LmsActor $actor): LmsGradeItem
    {
        $gradebook = $item->gradebook()->firstOrFail();
        $this->assertEditable($gradebook, $actor);

        $item->fill($data)->save();
        $item->updated_by = $actor->id;
        $item->save();

        $this->audit('lms.grade_item.updated', $item, ['changed' => array_keys($item->getChanges())]);

        return $item;
    }

    public function delete(LmsGradeItem $item, LmsActor $actor): void
    {
        $gradebook = $item->gradebook()->firstOrFail();
        $this->assertEditable($gradebook, $actor);

        if ($item->activity_id !== null && $item->results()->exists()) {
            LmsFault::invalid('Item dengan nilai tersimpan tidak dapat dihapus. Sembunyikan item bila tidak ingin dihitung.', 'item');
        }

        $this->audit('lms.grade_item.deleted', $item, ['name' => $item->name]);

        $item->delete();
    }

    /** @param list<int> $orderedIds */
    public function reorder(LmsGradebook $gradebook, array $orderedIds, LmsActor $actor): void
    {
        $this->assertEditable($gradebook, $actor);

        foreach ($orderedIds as $index => $id) {
            LmsGradeItem::query()
                ->where('gradebook_id', $gradebook->id)
                ->whereKey($id)
                ->update(['sort_order' => $index + 1]);
        }
    }

    /** Kategori bawaan per sumber nilai — dibuat sekali bila belum ada. */
    public function defaultCategory(LmsGradebook $gradebook, GradeItemSource $source, ?int $actorId = null): ?LmsGradeCategory
    {
        $definition = self::DEFAULT_CATEGORIES[$source->value] ?? null;

        if ($definition === null) {
            return null;
        }

        $category = LmsGradeCategory::query()
            ->where('gradebook_id', $gradebook->id)
            ->whereNull('parent_id')
            ->where('name', $definition['name'])
            ->first();

        if ($category !== null) {
            return $category;
        }

        $category = LmsGradeCategory::query()->create([
            'gradebook_id' => $gradebook->id,
            'name' => $definition['name'],
            'description' => 'Kategori otomatis untuk nilai '.strtolower($source->label()).'.',
            'weight' => 0,
            'sort_order' => $definition['sort'],
        ]);

        $this->audit('lms.gradebook.category.created', $category, ['name' => $category->name, 'auto' => true]);

        return $category;
    }

    /** Sumber nilai berdasarkan tipe aktivitas (§3). */
    public function sourceFor(LmsActivity $activity): GradeItemSource
    {
        return match ($activity->activity_type) {
            ActivityType::ASSIGNMENT => GradeItemSource::ASSIGNMENT,
            ActivityType::QUIZ => GradeItemSource::QUIZ,
            ActivityType::FORUM => GradeItemSource::FORUM,
            ActivityType::LESSON => GradeItemSource::LESSON,
            default => GradeItemSource::EXTERNAL,
        };
    }

    public function maximumFor(LmsActivity $activity): float
    {
        $assignment = $activity->activity_type === ActivityType::ASSIGNMENT
            ? LmsAssignment::query()->where('activity_id', $activity->id)->first()
            : null;

        if ($assignment !== null && $assignment->maximum_grade !== null) {
            return (float) $assignment->maximum_grade;
        }

        $quiz = $activity->activity_type === ActivityType::QUIZ
            ? LmsQuiz::query()->where('activity_id', $activity->id)->first()
            : null;

        if ($quiz !== null && $quiz->maximum_grade !== null) {
            return (float) $quiz->maximum_grade;
        }

        return 100.0;
    }

    public function passingFor(LmsActivity $activity): ?float
    {
        $assignment = LmsAssignment::query()->where('activity_id', $activity->id)->first();

        if ($assignment?->passing_grade !== null) {
            return (float) $assignment->passing_grade;
        }

        $quiz = LmsQuiz::query()->where('activity_id', $activity->id)->first();

        return $quiz?->passing_grade === null ? null : (float) $quiz->passing_grade;
    }

    /** @return array<string, mixed> */
    public function toRow(LmsGradeItem $item, bool $withStats = false): array
    {
        $item->loadMissing(['category', 'activity', 'rubric']);

        $row = [
            'id' => (int) $item->id,
            'gradebook_id' => (int) $item->gradebook_id,
            'category_id' => $item->category_id === null ? null : (int) $item->category_id,
            'category_name' => $item->category?->name,
            'activity_id' => $item->activity_id === null ? null : (int) $item->activity_id,
            'source' => $item->source->value,
            'source_label' => $item->source->label(),
            'source_tone' => $item->source->tone(),
            'name' => $item->name,
            'description' => $item->description,
            'maximum_grade' => (float) $item->maximum_grade,
            'passing_grade' => $item->passing_grade === null ? null : (float) $item->passing_grade,
            'weight' => (float) $item->weight,
            'grade_type' => $item->grade_type->value,
            'visibility' => $item->visibility->value,
            'visibility_label' => $item->visibility->label(),
            'is_extra_credit' => (bool) $item->is_extra_credit,
            'include_in_final' => (bool) $item->include_in_final,
            'rubric_id' => $item->rubric_id === null ? null : (int) $item->rubric_id,
            'rubric_name' => $item->rubric?->name,
            'sort_order' => (int) $item->sort_order,
            'activity_title' => $item->activity?->title,
        ];

        if ($withStats) {
            $row['stats'] = app(GradeStatisticsService::class)->itemStatistics($item);
        }

        return $row;
    }

    private function assertEditable(LmsGradebook $gradebook, LmsActor $actor): void
    {
        app(GradebookService::class)->assertEditable($gradebook, $actor);
    }
}
