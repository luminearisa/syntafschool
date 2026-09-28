<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\CategoryStatus;
use App\Modules\LMS\Domain\Enums\CourseVisibility;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Kategori bertingkat (§4): Category → Subcategory → Course. */
class LmsCategoryService
{
    /**
     * Pohon kategori. `$onlyVisible` dipakai permukaan publik sehingga kategori
     * yang dinonaktifkan admin tidak muncul di katalog.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(bool $onlyVisible = false, ?int $parentId = null): array
    {
        $categories = LmsCategory::query()
            ->withCount('courses')
            ->when($onlyVisible, fn ($q) => $q->active()->visible())
            ->where('parent_id', $parentId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $categories->map(fn (LmsCategory $category) => [
            'id' => (int) $category->id,
            'parent_id' => $category->parent_id !== null ? (int) $category->parent_id : null,
            'name' => (string) $category->name,
            'slug' => (string) $category->slug,
            'description' => $category->description,
            'image' => $category->image,
            'sort_order' => (int) $category->sort_order,
            'status' => $category->status?->value,
            'visibility' => $category->visibility?->value,
            'depth' => (int) $category->depth,
            'courses_count' => (int) $category->courses_count,
            'children' => $this->tree($onlyVisible, (int) $category->id),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    public function options(bool $onlyVisible = false): array
    {
        $flat = [];

        $walk = function (array $nodes, int $depth = 0) use (&$walk, &$flat): void {
            foreach ($nodes as $node) {
                $flat[] = [
                    'id' => $node['id'],
                    'name' => $node['name'],
                    'label' => str_repeat('— ', $depth).$node['name'],
                    'depth' => $depth,
                    'parent_id' => $node['parent_id'],
                ];

                if ($node['children'] !== []) {
                    $walk($node['children'], $depth + 1);
                }
            }
        };

        $walk($this->tree($onlyVisible));

        return $flat;
    }

    public function create(array $data, ?LmsActor $actor = null): LmsCategory
    {
        $parent = $this->resolveParent($data['parent_id'] ?? null);

        return LmsCategory::create([
            'parent_id' => $parent?->id,
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['slug'] ?? $data['name']),
            'description' => $data['description'] ?? null,
            'image' => $data['image'] ?? null,
            'sort_order' => $data['sort_order'] ?? ((int) LmsCategory::query()->where('parent_id', $parent?->id)->max('sort_order') + 1),
            'status' => $data['status'] ?? CategoryStatus::ACTIVE->value,
            'visibility' => $data['visibility'] ?? CourseVisibility::PUBLIC->value,
            'depth' => $parent !== null ? (int) $parent->depth + 1 : 0,
            'created_by' => $actor?->id,
        ]);
    }

    public function update(LmsCategory $category, array $data): LmsCategory
    {
        if (array_key_exists('parent_id', $data) && (int) ($data['parent_id'] ?? 0) !== (int) $category->parent_id) {
            $parent = $this->resolveParent($data['parent_id']);

            if ($parent !== null && ($parent->id === $category->id || $this->isDescendantOf($parent, $category))) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Kategori tidak boleh dipindahkan ke dalam subkategorinya sendiri.',
                ]);
            }

            $category->parent_id = $parent?->id;
            $category->depth = $parent !== null ? (int) $parent->depth + 1 : 0;
        }

        if (array_key_exists('name', $data)) {
            $category->name = $data['name'];
        }

        if (array_key_exists('slug', $data) && $data['slug'] !== null) {
            $category->slug = $this->uniqueSlug($data['slug'], $category->id);
        }

        foreach (['description', 'image', 'sort_order', 'status', 'visibility'] as $field) {
            if (array_key_exists($field, $data)) {
                $category->{$field} = $data[$field];
            }
        }

        $category->save();

        return $category;
    }

    public function delete(LmsCategory $category): void
    {
        if ($category->children()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Kategori ini masih memiliki subkategori. Pindahkan atau hapus subkategorinya lebih dulu.',
            ]);
        }

        if ($category->courses()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Kategori ini masih dipakai oleh course. Pindahkan course-nya lebih dulu.',
            ]);
        }

        $category->delete();
    }

    /** @param list<int> $ids */
    public function reorder(array $ids, ?int $parentId = null): void
    {
        DB::transaction(function () use ($ids, $parentId): void {
            foreach (array_values($ids) as $index => $id) {
                LmsCategory::query()
                    ->whereKey($id)
                    ->update(['sort_order' => $index + 1, 'parent_id' => $parentId]);
            }
        });
    }

    public function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'kategori';
        $slug = $base;
        $counter = 1;

        while (LmsCategory::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.(++$counter);
        }

        return $slug;
    }

    protected function resolveParent(?int $parentId): ?LmsCategory
    {
        return $parentId !== null && $parentId > 0 ? LmsCategory::query()->find($parentId) : null;
    }

    protected function isDescendantOf(LmsCategory $candidate, LmsCategory $ancestor): bool
    {
        $current = $candidate;

        while ($current->parent_id !== null) {
            if ((int) $current->parent_id === (int) $ancestor->id) {
                return true;
            }

            $current = LmsCategory::query()->find($current->parent_id);

            if ($current === null) {
                break;
            }
        }

        return false;
    }
}
