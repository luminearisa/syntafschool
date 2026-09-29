<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\RubricGradingMethod;
use App\Modules\LMS\Domain\Enums\RubricStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsRubric;
use App\Modules\LMS\Models\LmsRubricCriterion;
use App\Modules\LMS\Models\LmsRubricLevel;
use Illuminate\Support\Facades\DB;

/**
 * Rubric (§22–§25): template institusi, rubric course, kriteria, dan level.
 *
 * Guru boleh memakai rubric yang sudah ada, atau membuat baru. Rubric yang
 * sudah dipakai menilai tidak dihapus — hanya diarsipkan, agar hasil penilaian
 * lama tetap dapat dibaca.
 */
class RubricService
{
    use LogsLmsAudit;

    /**
     * Daftar rubric yang dapat dipakai sebuah course: template institusi +
     * rubric course tersebut (§25).
     *
     * @return list<array<string, mixed>>
     */
    public function availableFor(?LmsCourse $course = null, bool $onlyActive = false): array
    {
        $query = LmsRubric::query()
            ->with('criteria.levels')
            ->where(function ($builder) use ($course): void {
                $builder->where('is_template', true);

                if ($course !== null) {
                    $builder->orWhere('course_id', $course->id);
                } else {
                    $builder->orWhereNull('course_id');
                }
            })
            ->orderByDesc('is_template')
            ->orderBy('name');

        if ($onlyActive) {
            $query->where('status', RubricStatus::ACTIVE->value);
        }

        return $query->get()->map(fn (LmsRubric $rubric) => $rubric->toStructure())->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, LmsActor $actor): LmsRubric
    {
        $this->assertCriteria($data['criteria'] ?? []);

        return DB::transaction(function () use ($data, $actor): LmsRubric {
            $rubric = LmsRubric::query()->create([
                'course_id' => $data['course_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'grading_method' => $data['grading_method'] ?? RubricGradingMethod::POINTS,
                'maximum_score' => $data['maximum_score'] ?? 100,
                'status' => $data['status'] ?? RubricStatus::ACTIVE,
                'is_template' => (bool) ($data['is_template'] ?? false),
                'is_shared' => (bool) ($data['is_shared'] ?? true),
                'owner_id' => $actor->id,
                'created_by' => $actor->id,
            ]);

            $this->syncCriteria($rubric, $data['criteria'] ?? []);

            $this->audit('lms.rubric.created', $rubric, ['criteria' => count($data['criteria'] ?? [])]);

            return $rubric->refresh()->load('criteria.levels');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LmsRubric $rubric, array $data, LmsActor $actor): LmsRubric
    {
        if (array_key_exists('criteria', $data)) {
            $this->assertCriteria($data['criteria']);
        }

        return DB::transaction(function () use ($rubric, $data, $actor): LmsRubric {
            $rubric->fill($data)->save();

            if (array_key_exists('criteria', $data)) {
                $this->syncCriteria($rubric, $data['criteria']);
            }

            $this->audit('lms.rubric.updated', $rubric, ['name' => $rubric->name]);

            return $rubric->refresh()->load('criteria.levels');
        });
    }

    public function archive(LmsRubric $rubric, LmsActor $actor): LmsRubric
    {
        $rubric->status = RubricStatus::ARCHIVED;
        $rubric->save();

        $this->audit('lms.rubric.archived', $rubric, []);

        return $rubric;
    }

    public function delete(LmsRubric $rubric, LmsActor $actor): void
    {
        if ($rubric->assessments()->exists()) {
            LmsFault::invalid('Rubric sudah dipakai menilai. Arsipkan saja agar riwayat penilaian tetap utuh.', 'rubric');
        }

        $this->audit('lms.rubric.deleted', $rubric, ['name' => $rubric->name]);

        $rubric->delete();
    }

    /** Salin rubric (biasa dipakai untuk membuat variasi dari template §25). */
    public function duplicate(LmsRubric $rubric, LmsActor $actor, ?int $courseId = null, ?string $name = null): LmsRubric
    {
        $rubric->loadMissing('criteria.levels');

        return $this->create([
            'course_id' => $courseId ?? $rubric->course_id,
            'name' => $name ?? ($rubric->name.' (salinan)'),
            'description' => $rubric->description,
            'grading_method' => $rubric->grading_method->value,
            'maximum_score' => (float) $rubric->maximum_score,
            'status' => RubricStatus::ACTIVE->value,
            'is_template' => $courseId === null && $rubric->is_template,
            'is_shared' => (bool) $rubric->is_shared,
            'criteria' => $rubric->criteria->map(fn (LmsRubricCriterion $criterion) => [
                'name' => $criterion->name,
                'description' => $criterion->description,
                'weight' => (float) $criterion->weight,
                'maximum_score' => $criterion->maximum_score === null ? null : (float) $criterion->maximum_score,
                'levels' => $criterion->levels->map(fn (LmsRubricLevel $level) => [
                    'name' => $level->name,
                    'description' => $level->description,
                    'score' => (float) $level->score,
                ])->all(),
            ])->all(),
        ], $actor);
    }

    /** Pilih rubric untuk sebuah grade item (§24). */
    public function attachToGradeItem(\App\Modules\LMS\Models\LmsGradeItem $item, ?LmsRubric $rubric, LmsActor $actor): \App\Modules\LMS\Models\LmsGradeItem
    {
        $item->rubric_id = $rubric?->id;
        $item->save();

        $this->audit('lms.rubric.attached', $item, ['rubric_id' => $rubric?->id]);

        return $item;
    }

    /**
     * @param  list<array<string, mixed>>  $criteria
     */
    private function syncCriteria(LmsRubric $rubric, array $criteria): void
    {
        $rubric->criteria()->delete();

        foreach (array_values($criteria) as $index => $criterion) {
            $model = LmsRubricCriterion::query()->create([
                'rubric_id' => $rubric->id,
                'name' => $criterion['name'],
                'description' => $criterion['description'] ?? null,
                'weight' => $criterion['weight'] ?? 1,
                'maximum_score' => $criterion['maximum_score'] ?? null,
                'sort_order' => $criterion['sort_order'] ?? $index + 1,
            ]);

            foreach (array_values($criterion['levels'] ?? []) as $levelIndex => $level) {
                LmsRubricLevel::query()->create([
                    'criterion_id' => $model->id,
                    'name' => $level['name'],
                    'description' => $level['description'] ?? null,
                    'score' => $level['score'] ?? 0,
                    'sort_order' => $level['sort_order'] ?? $levelIndex + 1,
                ]);
            }
        }
    }

    /** @param list<array<string, mixed>> $criteria */
    private function assertCriteria(array $criteria): void
    {
        if ($criteria === []) {
            LmsFault::invalid('Rubric harus memiliki minimal satu kriteria.', 'criteria');
        }

        foreach ($criteria as $criterion) {
            if (trim((string) ($criterion['name'] ?? '')) === '') {
                LmsFault::invalid('Setiap kriteria rubric harus punya nama.', 'criteria');
            }

            if (($criterion['levels'] ?? []) === []) {
                LmsFault::invalid(sprintf('Kriteria "%s" belum memiliki level.', $criterion['name']), 'criteria');
            }
        }
    }
}
