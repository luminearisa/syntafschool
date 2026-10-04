<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Procurement\Application\Services\CatalogService;
use App\Modules\Procurement\Domain\Enums\ProcurementItemType;
use App\Modules\Procurement\Models\Category;
use App\Modules\Procurement\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Kategori & katalog item pengadaan (§37–§38). */
class CatalogController extends BaseApiController
{
    public function __construct(private readonly CatalogService $catalog)
    {
    }

    public function categories(Request $request): JsonResponse
    {
        return $this->successResponse($this->catalog->categories($request->boolean('active_only')));
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:proc_categories,code'],
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', 'exists:proc_categories,id'],
            'item_type' => ['nullable', Rule::in(ProcurementItemType::values())],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return $this->createdResponse($this->catalog->createCategory($data), 'Kategori dibuat');
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', 'exists:proc_categories,id'],
            'item_type' => ['nullable', Rule::in(ProcurementItemType::values())],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse($this->catalog->updateCategory($category, $data), 'Kategori diperbarui');
    }

    public function items(Request $request): JsonResponse
    {
        $page = $this->catalog->paginateItems(
            $request->only(['search', 'category_id', 'item_type', 'is_active']),
            (int) $request->query('per_page', 20),
        );

        return $this->successResponse($page);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $data = $this->validatedItem($request);

        return $this->createdResponse($this->catalog->createItem($data), 'Item dibuat');
    }

    public function updateItem(Request $request, Item $item): JsonResponse
    {
        $data = $this->validatedItem($request, $item);

        return $this->successResponse($this->catalog->updateItem($item, $data), 'Item diperbarui');
    }

    /** @return array<string, mixed> */
    protected function validatedItem(Request $request, ?Item $item = null): array
    {
        return $request->validate([
            'sku' => ['nullable', 'string', 'max:60', Rule::unique('proc_items', 'sku')->ignore($item?->id)],
            'name' => [$item === null ? 'required' : 'sometimes', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', 'exists:proc_categories,id'],
            'item_type' => ['nullable', Rule::in(ProcurementItemType::values())],
            'unit' => ['nullable', 'string', 'max:30'],
            'specification' => ['nullable', 'string', 'max:255'],
            'preferred_vendor_id' => ['nullable', 'integer', 'exists:proc_vendors,id'],
            'estimated_price' => ['nullable', 'numeric', 'min:0'],
            'tax_category' => ['nullable', 'string', 'max:40'],
            'inventory_item_ref' => ['nullable', 'string', 'max:60'],
            'asset_category' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
            'vendors' => ['nullable', 'array'],
            'vendors.*.vendor_id' => ['required_with:vendors', 'integer', 'exists:proc_vendors,id'],
            'vendors.*.last_price' => ['nullable', 'numeric', 'min:0'],
            'vendors.*.lead_time_days' => ['nullable', 'integer', 'min:0'],
            'vendors.*.is_preferred' => ['nullable', 'boolean'],
        ]);
    }
}
