<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Enums\ActivityCategoryStatus;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Models\ActivityCategory;
use App\Modules\Activity\Models\ActivityObjective;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityRole;
use App\Modules\Activity\Models\ActivitySkill;
use Illuminate\Support\Collection;

/**
 * Katalog dasar: kategori berjenjang, tujuan perkembangan, keterampilan, dan
 * peran anggota (§15, §19, §20, §27).
 *
 * Semuanya configurable — sekolah menambah kategori "Kepesantrenan" atau
 * keterampilan "Khotbah" tanpa perubahan kode.
 */
class ActivityCategoryService
{
    public function __construct(private readonly ActivityAuditService $audit) {}

    // ------------------------------------------------------------ kategori

    public function createCategory(array $attributes, ?User $actor = null): ActivityCategory
    {
        $category = ActivityCategory::query()->create([
            'code' => $attributes['code'],
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'parent_id' => $attributes['parent_id'] ?? null,
            'icon' => $attributes['icon'] ?? null,
            'status' => $attributes['status'] ?? ActivityCategoryStatus::ACTIVE->value,
            'sort_order' => $attributes['sort_order'] ?? 0,
        ]);

        $this->audit->record('category.created', [
            'subject' => $category,
            'after' => $category,
            'actor' => $actor?->id,
        ]);

        return $category;
    }

    public function updateCategory(ActivityCategory $category, array $attributes, ?User $actor = null): ActivityCategory
    {
        $before = $category->attributesToArray();
        $category->fill($attributes)->save();

        $this->audit->record('category.updated', [
            'subject' => $category,
            'before' => $before,
            'after' => $category,
            'actor' => $actor?->id,
        ]);

        return $category;
    }

    /** Kategori tidak dapat dihapus bila masih memiliki program atau sub-kategori. */
    public function deleteCategory(ActivityCategory $category): void
    {
        if ($category->programs()->exists()) {
            throw ActivityConflictException::make('Kategori masih dipakai program aktivitas — pindahkan programnya lebih dahulu.');
        }

        if ($category->children()->exists()) {
            throw ActivityConflictException::make('Kategori masih memiliki sub-kategori.');
        }

        $category->delete();
    }

    /** @return list<array> pohon kategori untuk layar pemilihan */
    public function tree(bool $onlyActive = true): array
    {
        $categories = ActivityCategory::query()
            ->when($onlyActive, fn ($query) => $query->where('status', ActivityCategoryStatus::ACTIVE->value))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->buildTree($categories, null);
    }

    /** @param  Collection<int, ActivityCategory>  $categories */
    protected function buildTree(Collection $categories, ?int $parentId): array
    {
        return $categories
            ->where('parent_id', $parentId)
            ->map(fn (ActivityCategory $category) => [
                'id' => (int) $category->id,
                'code' => $category->code,
                'name' => $category->name,
                'icon' => $category->icon,
                'description' => $category->description,
                'sort_order' => (int) $category->sort_order,
                'children' => $this->buildTree($categories, (int) $category->id),
            ])
            ->values()
            ->all();
    }

    // ------------------------------------------------------------ tujuan

    public function createObjective(array $attributes): ActivityObjective
    {
        return ActivityObjective::query()->create([
            'code' => $attributes['code'],
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'category' => $attributes['category'] ?? 'DEVELOPMENT',
            'is_active' => $attributes['is_active'] ?? true,
        ]);
    }

    // ------------------------------------------------------- keterampilan

    public function createSkill(array $attributes): ActivitySkill
    {
        return ActivitySkill::query()->create([
            'code' => $attributes['code'],
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'category' => $attributes['category'] ?? 'GENERAL',
            'is_active' => $attributes['is_active'] ?? true,
        ]);
    }

    // ------------------------------------------------------------- peran

    /** @return list<ActivityRole> */
    public function roles(bool $onlyActive = true): array
    {
        return ActivityRole::query()
            ->when($onlyActive, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    /** Program dengan kategori/keterampilan terlampir (dipakai layar builder). */
    public function metadata(): array
    {
        return [
            'categories' => $this->tree(false),
            'objectives' => ActivityObjective::query()->orderBy('name')->get(['id', 'code', 'name', 'category'])->all(),
            'skills' => ActivitySkill::query()->orderBy('name')->get(['id', 'code', 'name', 'category'])->all(),
            'roles' => $this->roles(false),
            'program_count' => ActivityProgram::query()->count(),
        ];
    }
}
