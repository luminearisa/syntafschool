<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\GradeItemSource;
use App\Modules\LMS\Domain\Enums\GradeScaleScope;
use App\Modules\LMS\Domain\Enums\GradebookStatus;
use App\Modules\LMS\Domain\Enums\WeightPolicy;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsGradebook;
use App\Modules\LMS\Models\LmsGradeCategory;
use App\Modules\LMS\Models\LmsGradeScale;
use App\Modules\LMS\Models\LmsGradeScaleEntry;
use Illuminate\Support\Collection;

/**
 * Konfigurasi gradebook: gradebook, kategori, dan skala nilai (§2, §4, §6, §7).
 *
 * Perhitungan nilai **tidak** ada di sini — itu tugas
 * `GradeCalculationService`/`FinalGradeService` supaya aturan penilaian hanya
 * hidup di satu tempat.
 */
class GradebookService
{
    use LogsLmsAudit;

    public function __construct(private readonly GradeItemService $items) {}

    /** Gradebook satu-satunya untuk sebuah course (§2). */
    public function ensureForCourse(LmsCourse $course, ?int $actorId = null): LmsGradebook
    {
        $existing = LmsGradebook::query()->where('course_id', $course->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $defaults = (array) config('lms.gradebook', []);

        $gradebook = LmsGradebook::query()->create([
            'course_id' => $course->id,
            'name' => 'Nilai — '.$course->name,
            'description' => 'Gradebook otomatis untuk course '.$course->name.'.',
            'status' => GradebookStatus::ACTIVE,
            'grading_method' => $defaults['grading_method'] ?? 'PERCENTAGE',
            'aggregation_method' => $defaults['aggregation_method'] ?? 'WEIGHTED_MEAN',
            'missing_grade_policy' => $defaults['missing_grade_policy'] ?? 'PENDING',
            'weight_policy' => $defaults['weight_policy'] ?? 'NORMALIZE',
            'extra_credit_policy' => $defaults['extra_credit_policy'] ?? 'CAP',
            'maximum_grade' => $defaults['maximum_grade'] ?? 100,
            'passing_grade' => $defaults['passing_grade'] ?? 70,
            'grade_scale_id' => $this->defaultScale()->id,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        $this->audit('lms.gradebook.created', $gradebook, ['course_id' => (int) $course->id]);

        // Grade item untuk aktivitas yang sudah ada ikut dibuat agar tidak ada
        // aktivitas bernilai yang "hilang" dari gradebook (§64).
        $this->items->syncFromCourse($gradebook, $actorId);

        return $gradebook->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(LmsGradebook $gradebook, array $data, LmsActor $actor): LmsGradebook
    {
        $this->assertEditable($gradebook, $actor);

        $gradebook->fill($data);

        if ($gradebook->isDirty()) {
            $gradebook->updated_by = $actor->id;
            $gradebook->save();

            $this->audit('lms.gradebook.updated', $gradebook, [
                'changed' => array_keys($gradebook->getChanges()),
            ]);
        }

        if (array_key_exists('weight_policy', $data)) {
            $this->assertWeightsValid($gradebook->refresh());
        }

        return $gradebook;
    }

    public function setStatus(LmsGradebook $gradebook, GradebookStatus $status, LmsActor $actor): LmsGradebook
    {
        if (! $gradebook->isEditable() && ! $actor->can('lms.grade.lock')) {
            throw LmsFault::forbidden('Gradebook sedang dibekukan/terkunci.');
        }

        $gradebook->status = $status;
        $gradebook->updated_by = $actor->id;

        if ($status === GradebookStatus::FROZEN) {
            $gradebook->frozen_at = now();
            $gradebook->frozen_by = $actor->id;
        }

        if ($status !== GradebookStatus::FROZEN) {
            $gradebook->frozen_at = null;
            $gradebook->frozen_by = null;
        }

        $gradebook->save();

        $this->audit('lms.gradebook.status', $gradebook, ['status' => $status->value]);

        return $gradebook;
    }

    /** §44 grade freeze: tidak ada perubahan nilai/rilis/kategori tanpa izin khusus. */
    public function freeze(LmsGradebook $gradebook, LmsActor $actor): LmsGradebook
    {
        return $this->setStatus($gradebook, GradebookStatus::FROZEN, $actor);
    }

    public function unfreeze(LmsGradebook $gradebook, LmsActor $actor): LmsGradebook
    {
        return $this->setStatus($gradebook, GradebookStatus::ACTIVE, $actor);
    }

    public function isFrozen(LmsGradebook $gradebook): bool
    {
        return $gradebook->isFrozen();
    }

    public function assertEditable(LmsGradebook $gradebook, LmsActor $actor): void
    {
        if (! $gradebook->isEditable() && ! $actor->can('lms.grade.lock')) {
            throw LmsFault::forbidden('Gradebook sedang dibekukan atau terkunci.');
        }
    }

    // ------------------------------------------------------------------ kategori

    /** @param array<string, mixed> $data */
    public function createCategory(LmsGradebook $gradebook, array $data, LmsActor $actor): LmsGradeCategory
    {
        $this->assertEditable($gradebook, $actor);

        $category = LmsGradeCategory::query()->create([
            'gradebook_id' => $gradebook->id,
            'parent_id' => $data['parent_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'weight' => $data['weight'] ?? 0,
            'aggregation_method' => $data['aggregation_method'] ?? null,
            'missing_grade_policy' => $data['missing_grade_policy'] ?? null,
            'drop_lowest' => $data['drop_lowest'] ?? 0,
            'is_extra_credit' => (bool) ($data['is_extra_credit'] ?? false),
            'include_in_final' => (bool) ($data['include_in_final'] ?? true),
            'sort_order' => $data['sort_order'] ?? ((int) $gradebook->categories()->max('sort_order') + 1),
        ]);

        $this->audit('lms.gradebook.category.created', $category, ['name' => $category->name]);

        return $category;
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(LmsGradeCategory $category, array $data, LmsActor $actor): LmsGradeCategory
    {
        $gradebook = $category->gradebook()->firstOrFail();
        $this->assertEditable($gradebook, $actor);

        if (array_key_exists('parent_id', $data) && (int) $data['parent_id'] === (int) $category->id) {
            LmsFault::invalid('Kategori tidak dapat menjadi induk dirinya sendiri.', 'parent_id');
        }

        $category->fill($data)->save();
        $this->audit('lms.gradebook.category.updated', $category, ['name' => $category->name]);

        return $category;
    }

    public function deleteCategory(LmsGradeCategory $category, LmsActor $actor): void
    {
        $gradebook = $category->gradebook()->firstOrFail();
        $this->assertEditable($gradebook, $actor);

        // Item tidak dihapus: dipindahkan ke "tanpa kategori" agar nilai tidak hilang.
        $category->items()->update(['category_id' => $category->parent_id]);
        $category->children()->update(['parent_id' => $category->parent_id]);

        $this->audit('lms.gradebook.category.deleted', $category, ['name' => $category->name]);

        $category->delete();
    }

    /** @param list<int> $orderedIds */
    public function reorderCategories(LmsGradebook $gradebook, array $orderedIds, LmsActor $actor): void
    {
        $this->assertEditable($gradebook, $actor);

        foreach ($orderedIds as $index => $id) {
            LmsGradeCategory::query()
                ->where('gradebook_id', $gradebook->id)
                ->whereKey($id)
                ->update(['sort_order' => $index + 1]);
        }
    }

    /**
     * Ringkasan bobot + validasi (§7).
     *
     * @return array<string, mixed>
     */
    public function weightSummary(LmsGradebook $gradebook): array
    {
        $gradebook->loadMissing('categories.items', 'items');

        $rootCategories = $gradebook->categories->whereNull('parent_id');
        $categoryWeight = round((float) $rootCategories->sum('weight'), 2);
        $uncategorisedItems = $gradebook->items->whereNull('category_id');

        $hasCategories = $rootCategories->isNotEmpty();

        // STRICT: total bobot kategori wajib tepat 100%.
        // NORMALIZE: total apa pun sah karena dibagi dengan total aktual (§7).
        $isValid = ! $hasCategories
            ? true
            : ($gradebook->weight_policy === WeightPolicy::STRICT
                ? abs($categoryWeight - 100.0) < 0.01
                : $categoryWeight > 0.0);

        return [
            'weight_policy' => $gradebook->weight_policy->value,
            'weight_policy_label' => $gradebook->weight_policy->label(),
            'category_weight_total' => $categoryWeight,
            'has_categories' => $hasCategories,
            'uncategorised_item_count' => $uncategorisedItems->count(),
            'uncategorised_weight' => round((float) $uncategorisedItems->sum('weight'), 2),
            'is_valid' => $isValid,
            'requires_exact_total' => $gradebook->weight_policy === WeightPolicy::STRICT,
            'message' => $this->weightMessage($gradebook, $categoryWeight),
        ];
    }

    public function assertWeightsValid(LmsGradebook $gradebook): void
    {
        if ($gradebook->weight_policy !== WeightPolicy::STRICT) {
            return;
        }

        $summary = $this->weightSummary($gradebook);

        if (! $summary['is_valid']) {
            LmsFault::invalid(sprintf(
                'Total bobot kategori harus 100%% (saat ini %s%%).',
                number_format((float) $summary['category_weight_total'], 2),
            ), 'weight');
        }
    }

    private function weightMessage(LmsGradebook $gradebook, float $categoryWeight): string
    {
        if ($categoryWeight <= 0.0) {
            return 'Belum ada bobot kategori: semua item dihitung berbobot sama sesuai metode agregasi.';
        }

        if ($gradebook->weight_policy === WeightPolicy::NORMALIZE) {
            return sprintf('Total bobot %s%% akan dinormalisasi otomatis.', number_format($categoryWeight, 2));
        }

        return abs($categoryWeight - 100.0) < 0.01
            ? 'Total bobot kategori sudah 100%.'
            : sprintf('Total bobot harus 100%% (saat ini %s%%).', number_format($categoryWeight, 2));
    }

    // ------------------------------------------------------------------ skala nilai

    /** Skala default sekolah (§6) — dibuat sekali, lalu boleh disunting admin. */
    public function defaultScale(): LmsGradeScale
    {
        $scale = LmsGradeScale::query()
            ->where('scope', GradeScaleScope::SCHOOL)
            ->where('is_default', true)
            ->first();

        if ($scale !== null) {
            return $scale;
        }

        return $this->createScale([
            'scope' => GradeScaleScope::SCHOOL->value,
            'code' => 'DEFAULT',
            'name' => 'Skala Nilai Sekolah',
            'description' => 'A–E bawaan LMS; dapat disunting sesuai kebijakan sekolah.',
            'is_default' => true,
            'entries' => LmsGradeScale::defaults(),
        ], null);
    }

    /** Skala efektif untuk sebuah gradebook: course → tahun ajaran → sekolah. */
    public function scaleFor(LmsGradebook $gradebook): ?LmsGradeScale
    {
        $gradebook->loadMissing('course', 'scale');

        $course = $gradebook->course;

        if ($course !== null) {
            $courseScale = LmsGradeScale::query()
                ->where('scope', GradeScaleScope::COURSE)
                ->where('course_id', $course->id)
                ->where('is_active', true)
                ->first();

            if ($courseScale !== null) {
                return $courseScale;
            }

            if ($course->academic_year_id !== null) {
                $yearScale = LmsGradeScale::query()
                    ->where('scope', GradeScaleScope::ACADEMIC_YEAR)
                    ->where('academic_year_id', $course->academic_year_id)
                    ->where('is_active', true)
                    ->first();

                if ($yearScale !== null) {
                    return $yearScale;
                }
            }
        }

        // Skala yang dipilih eksplisit pada gradebook menang atas default sekolah.
        if ($gradebook->scale !== null) {
            return $gradebook->scale;
        }

        return $this->defaultScale();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{grade: string, min_score: float|int, max_score: float|int, predicate?: string|null, gpa?: float|int|null}>|null  $entries
     */
    public function createScale(array $data, ?LmsActor $actor, ?array $entries = null): LmsGradeScale
    {
        $scale = LmsGradeScale::query()->create([
            'scope' => $data['scope'] ?? GradeScaleScope::SCHOOL->value,
            'code' => $data['code'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'course_id' => $data['course_id'] ?? null,
            'academic_year_id' => $data['academic_year_id'] ?? null,
            'semester_id' => $data['semester_id'] ?? null,
            'is_default' => (bool) ($data['is_default'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $actor?->id,
        ]);

        $this->replaceEntries($scale, $entries ?? ($data['entries'] ?? []));

        $this->audit('lms.grade_scale.created', $scale, ['name' => $scale->name]);

        return $scale->refresh();
    }

    /** @param list<array<string, mixed>> $entries */
    public function replaceEntries(LmsGradeScale $scale, array $entries): LmsGradeScale
    {
        $scale->entries()->delete();

        foreach ($entries as $index => $entry) {
            LmsGradeScaleEntry::query()->create([
                'grade_scale_id' => $scale->id,
                'grade' => (string) $entry['grade'],
                'min_score' => $entry['min_score'],
                'max_score' => $entry['max_score'],
                'predicate' => $entry['predicate'] ?? null,
                'gpa' => $entry['gpa'] ?? null,
                'description' => $entry['description'] ?? null,
                'sort_order' => $entry['sort_order'] ?? $index + 1,
            ]);
        }

        return $scale->refresh()->load('entries');
    }

    /** @return Collection<int, LmsGradeScale> */
    public function scales(?int $courseId = null): Collection
    {
        return LmsGradeScale::query()
            ->with('entries')
            ->where(function ($query) use ($courseId): void {
                $query->whereNull('course_id');

                if ($courseId !== null) {
                    $query->orWhere('course_id', $courseId);
                }
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /** Konfigurasi efektif untuk UI (§55, §56). @return array<string, mixed> */
    public function configuration(LmsGradebook $gradebook): array
    {
        $gradebook->loadMissing(['course', 'categories', 'items']);

        $scale = $this->scaleFor($gradebook);

        return [
            'id' => (int) $gradebook->id,
            'course_id' => (int) $gradebook->course_id,
            'course_title' => $gradebook->course?->name,
            'name' => $gradebook->name,
            'description' => $gradebook->description,
            'status' => $gradebook->status->value,
            'status_label' => $gradebook->status->label(),
            'status_tone' => $gradebook->status->tone(),
            'is_frozen' => $gradebook->isFrozen(),
            'is_editable' => $gradebook->isEditable(),
            'grading_method' => $gradebook->grading_method->value,
            'grading_method_label' => $gradebook->grading_method->label(),
            'aggregation_method' => $gradebook->aggregation_method->value,
            'aggregation_method_label' => $gradebook->aggregation_method->label(),
            'missing_grade_policy' => $gradebook->missing_grade_policy->value,
            'missing_grade_policy_label' => $gradebook->missing_grade_policy->label(),
            'weight_policy' => $gradebook->weight_policy->value,
            'extra_credit_policy' => $gradebook->extra_credit_policy->value,
            'maximum_grade' => (float) $gradebook->maximum_grade,
            'passing_grade' => (float) $gradebook->passingGrade(),
            'is_parent_visible' => (bool) $gradebook->is_parent_visible,
            'is_ranking_visible' => (bool) $gradebook->is_ranking_visible,
            'config_hash' => $gradebook->configHash(),
            'weights' => $this->weightSummary($gradebook),
            'scale' => $scale === null ? null : [
                'id' => (int) $scale->id,
                'name' => $scale->name,
                'scope' => $scale->scope->value,
                'entries' => $scale->entries->map(fn (LmsGradeScaleEntry $entry) => [
                    'grade' => $entry->grade,
                    'min_score' => (float) $entry->min_score,
                    'max_score' => (float) $entry->max_score,
                    'predicate' => $entry->predicate,
                ])->all(),
            ],
            'categories' => $gradebook->categories->map(fn (LmsGradeCategory $category) => [
                'id' => (int) $category->id,
                'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                'name' => $category->name,
                'description' => $category->description,
                'weight' => (float) $category->weight,
                'aggregation_method' => $category->aggregation_method?->value,
                'missing_grade_policy' => $category->missing_grade_policy?->value,
                'drop_lowest' => (int) $category->drop_lowest,
                'is_extra_credit' => (bool) $category->is_extra_credit,
                'include_in_final' => (bool) $category->include_in_final,
                'sort_order' => (int) $category->sort_order,
                'item_count' => $gradebook->items->where('category_id', $category->id)->count(),
            ])->all(),
        ];
    }

    /** Sumber grade item yang tersedia (§3) beserta labelnya. */
    public function sources(): array
    {
        return array_map(fn (GradeItemSource $source) => [
            'value' => $source->value,
            'label' => $source->label(),
            'tone' => $source->tone(),
            'icon' => $source->icon(),
            'has_evidence' => $source->hasEvidence(),
        ], GradeItemSource::cases());
    }
}
