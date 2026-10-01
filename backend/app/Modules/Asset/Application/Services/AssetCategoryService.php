<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Domain\Enums\AssetCriticality;
use App\Modules\Asset\Domain\Enums\AssetDepreciationMethod;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetType;
use Illuminate\Support\Facades\DB;

/**
 * Katalog aset: kategori berjenjang, jenis, dan nilai bawaannya.
 *
 * Nilai bawaan kategori (umur, metode susut, ambang kapitalisasi, frekuensi
 * inspeksi) dipakai saat aset dicatat — sesudah itu nilai yang tercatat pada
 * asetlah yang berlaku. Mengubah kebijakan karenanya tidak mengubah masa lalu.
 */
class AssetCategoryService
{
    public function createCategory(User $actor, array $data): AssetCategory
    {
        $parent = isset($data['parent_id']) ? AssetCategory::query()->find($data['parent_id']) : null;

        if (isset($data['parent_id']) && $parent === null) {
            throw new AssetValidationException(['parent_id' => ['Kategori induk tidak ditemukan.']]);
        }

        if (AssetCategory::query()->where('code', $data['code'])->exists()) {
            throw new AssetValidationException(['code' => ['Kode kategori sudah dipakai.']]);
        }

        return AssetCategory::query()->create($data + ['parent_id' => $parent?->id]);
    }

    public function createType(User $actor, array $data): AssetType
    {
        $category = AssetCategory::query()->find($data['category_id'] ?? null);

        if ($category === null) {
            throw new AssetValidationException(['category_id' => ['Kategori tidak ditemukan.']]);
        }

        return DB::transaction(function () use ($data, $category): AssetType {
            $type = AssetType::query()->create($data + [
                'category_id' => $category->id,
                'default_useful_life_months' => $data['default_useful_life_months'] ?? $category->useful_life_months,
                'default_criticality' => $data['default_criticality'] ?? $category->default_criticality ?? AssetCriticality::NORMAL->value,
                'default_depreciation_method' => $data['default_depreciation_method'] ?? $category->depreciation_method,
            ]);

            foreach (($data['attributes'] ?? []) as $index => $attribute) {
                $type->attributes()->create([
                    'code' => $attribute['code'] ?? 'ATTR'.($index + 1),
                    'label' => $attribute['label'] ?? $attribute['code'] ?? 'Atribut',
                    'data_type' => $attribute['data_type'] ?? 'string',
                    'unit' => $attribute['unit'] ?? null,
                    'options' => $attribute['options'] ?? null,
                    'is_required' => (bool) ($attribute['is_required'] ?? false),
                    'sort_order' => $index,
                ]);
            }

            return $type->refresh();
        });
    }

    /**
     * Nilai bawaan yang berlaku untuk sebuah jenis aset.
     *
     * Urutannya: jenis → kategori → bawaan sekolah. Urutan ini disengaja: yang
     * paling khusus menang, sehingga satu jenis (mis. "Proyektor") dapat berbeda
     * dari kategorinya ("Elektronik") tanpa menggandakan kategori.
     */
    public function defaultsFor(?AssetType $type, ?AssetCategory $category = null): array
    {
        $category ??= $type?->category;

        return [
            'useful_life_months' => $type?->default_useful_life_months ?? $category?->useful_life_months,
            'depreciation_method' => $type?->default_depreciation_method
                ?? $category?->depreciation_method
                ?? AssetDepreciationMethod::STRAIGHT_LINE->value,
            'criticality' => $type?->default_criticality ?? $category?->default_criticality ?? AssetCriticality::NORMAL->value,
            'capitalization_threshold' => app(\App\Modules\Asset\Application\Support\AssetSettingsService::class)
                ->capitalizationThreshold($category),
            'inspection_frequency_days' => $category?->inspection_frequency_days,
            'is_consumable' => (bool) ($category?->is_consumable ?? false),
            'kind' => $category?->kind ?? 'ASSET',
        ];
    }
}
