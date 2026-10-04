<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Models\Category;
use App\Modules\Procurement\Models\Item;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Kategori & katalog item pengadaan (§37–§38). */
class CatalogService
{
    public function __construct(private readonly ProcurementAuditService $audit)
    {
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Category> */
    public function categories(bool $activeOnly = false)
    {
        return Category::query()
            ->when($activeOnly, fn ($q) => $q->active())
            ->with('parent:id,name')
            ->orderBy('name')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function createCategory(array $data): Category
    {
        $category = Category::query()->create($data);
        $this->audit->record(ProcurementAuditAction::CREATE, $category, null, $category->toArray());

        return $category;
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(Category $category, array $data): Category
    {
        $old = $category->toArray();
        $category->fill($data)->save();
        $this->audit->record(ProcurementAuditAction::UPDATE, $category, $old, $category->fresh()->toArray());

        return $category;
    }

    /** @param array<string, mixed> $filters */
    public function paginateItems(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return Item::query()
            ->with(['category:id,name', 'preferredVendor:id,legal_name', 'vendors.vendor:id,legal_name'])
            ->search($filters['search'] ?? null)
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', (int) $id))
            ->when($filters['item_type'] ?? null, fn ($q, $type) => $q->where('item_type', $type))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('name')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function createItem(array $data): Item
    {
        return DB::transaction(function () use ($data): Item {
            $item = Item::query()->create($data);
            $this->syncVendors($item, $data['vendors'] ?? []);
            $this->audit->record(ProcurementAuditAction::CREATE, $item, null, $item->toArray());

            return $item->fresh(['category', 'vendors']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateItem(Item $item, array $data): Item
    {
        return DB::transaction(function () use ($item, $data): Item {
            $old = $item->toArray();
            $item->fill($data)->save();

            if (array_key_exists('vendors', $data)) {
                $this->syncVendors($item, $data['vendors'] ?? []);
            }

            $this->audit->record(ProcurementAuditAction::UPDATE, $item, $old, $item->fresh()->toArray());

            return $item->fresh(['category', 'vendors']);
        });
    }

    /** @param array<int, array<string, mixed>> $vendors */
    protected function syncVendors(Item $item, array $vendors): void
    {
        foreach ($vendors as $row) {
            $item->vendors()->updateOrCreate(
                ['vendor_id' => (int) $row['vendor_id']],
                [
                    'vendor_sku' => $row['vendor_sku'] ?? null,
                    'last_price' => $row['last_price'] ?? 0,
                    'currency' => $row['currency'] ?? 'IDR',
                    'lead_time_days' => $row['lead_time_days'] ?? 0,
                    'is_preferred' => (bool) ($row['is_preferred'] ?? false),
                ],
            );
        }
    }
}
