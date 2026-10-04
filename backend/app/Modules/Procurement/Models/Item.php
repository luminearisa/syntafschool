<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\Enums\ProcurementItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Item katalog pengadaan (barang/jasa). Tabel: `proc_items`. */
class Item extends ProcurementModel
{
    use SoftDeletes;

    protected $table = 'proc_items';

    protected $casts = [
        'estimated_price' => 'decimal:2',
        'is_active' => 'boolean',
        'item_type' => ProcurementItemType::class,
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function preferredVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'preferred_vendor_id');
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(ItemVendor::class, 'item_id');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<self> $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $like = '%'.$term.'%';
        $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $like)
            ->orWhere('sku', 'like', $like)
            ->orWhere('description', 'like', $like));
    }
}
