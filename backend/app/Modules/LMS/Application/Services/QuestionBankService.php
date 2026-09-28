<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsQuestionBank;
use App\Modules\LMS\Models\LmsQuestionCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/** Bank soal & kategori bertingkat (§14). */
class QuestionBankService
{
    use LogsLmsAudit;

    /** @return Collection<int, LmsQuestionBank> */
    public function all(?LmsCourse $course = null): Collection
    {
        return LmsQuestionBank::query()
            ->when($course !== null, fn ($query) => $query->where(fn ($q) => $q->where('course_id', $course->id)->orWhereNull('course_id')))
            ->withCount(['questions', 'categories'])
            ->orderBy('name')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, LmsActor $actor): LmsQuestionBank
    {
        $bank = LmsQuestionBank::create([
            'course_id' => $data['course_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'ACTIVE',
            'visibility' => $data['visibility'] ?? ($data['course_id'] ?? null ? 'COURSE' : 'INSTITUTION'),
            'created_by' => $actor->id,
        ]);

        $this->audit('LMS_QUESTION_BANK_CREATED', $bank, ['name' => $bank->name]);

        return $bank;
    }

    /** @param array<string, mixed> $data */
    public function update(LmsQuestionBank $bank, array $data): LmsQuestionBank
    {
        $bank->fill(collect($data)->only(['name', 'description', 'status', 'visibility', 'course_id'])->all())->save();

        $this->audit('LMS_QUESTION_BANK_UPDATED', $bank, ['name' => $bank->name]);

        return $bank->fresh();
    }

    public function delete(LmsQuestionBank $bank): void
    {
        $bank->delete();

        $this->audit('LMS_QUESTION_BANK_DELETED', $bank, ['name' => $bank->name]);
    }

    /** @param array<string, mixed> $data */
    public function createCategory(LmsQuestionBank $bank, array $data): LmsQuestionCategory
    {
        $parent = null;

        if (! empty($data['parent_id'])) {
            $parent = LmsQuestionCategory::query()
                ->where('bank_id', $bank->id)
                ->findOrFail((int) $data['parent_id']);
        }

        $category = $bank->categories()->create([
            'parent_id' => $parent?->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? ((int) $bank->categories()->max('sort_order') + 1),
        ]);

        $this->audit('LMS_QUESTION_CATEGORY_CREATED', $category, ['bank_id' => $bank->id]);

        return $category;
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(LmsQuestionCategory $category, array $data): LmsQuestionCategory
    {
        if (array_key_exists('parent_id', $data) && (int) $data['parent_id'] === (int) $category->id) {
            throw ValidationException::withMessages(['parent_id' => 'Kategori tidak boleh menjadi induk dirinya sendiri.']);
        }

        $category->fill(collect($data)->only(['name', 'description', 'sort_order', 'parent_id'])->all())->save();

        return $category->fresh();
    }

    public function deleteCategory(LmsQuestionCategory $category): void
    {
        if ($category->questions()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Kategori masih memiliki soal. Pindahkan atau hapus soalnya terlebih dahulu.',
            ]);
        }

        $category->delete();
    }

    /**
     * Struktur bank → kategori (bertingkat) + jumlah soal.
     *
     * @return array<string, mixed>
     */
    public function tree(LmsQuestionBank $bank): array
    {
        return [
            'bank' => [
                'id' => (int) $bank->id,
                'name' => (string) $bank->name,
                'description' => $bank->description,
                'course_id' => $bank->course_id === null ? null : (int) $bank->course_id,
                'visibility' => $bank->visibility,
                'questions_count' => $bank->questions()->count(),
            ],
            'categories' => $this->categoryBranch($bank, null),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function categoryBranch(LmsQuestionBank $bank, ?int $parentId): array
    {
        return LmsQuestionCategory::query()
            ->where('bank_id', $bank->id)
            ->where('parent_id', $parentId)
            ->withCount('questions')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (LmsQuestionCategory $category) => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'description' => $category->description,
                'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                'questions_count' => (int) $category->questions_count,
                'children' => $this->categoryBranch($bank, (int) $category->id),
            ])
            ->all();
    }
}
