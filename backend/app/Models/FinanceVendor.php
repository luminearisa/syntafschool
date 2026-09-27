<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vendor (§34) — perusahaan/individu/supplier/penyedia jasa penerima pembayaran.
 */
class FinanceVendor extends BaseModel
{
    use LogsFinanceActivity, SoftDeletes;

    public const TYPE_COMPANY = 'company';

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_SUPPLIER = 'supplier';

    public const TYPE_SERVICE = 'service';

    public const TYPES = [self::TYPE_COMPANY, self::TYPE_INDIVIDUAL, self::TYPE_SUPPLIER, self::TYPE_SERVICE];

    public const TYPE_LABELS = [
        self::TYPE_COMPANY => 'Perusahaan',
        self::TYPE_INDIVIDUAL => 'Individu',
        self::TYPE_SUPPLIER => 'Supplier',
        self::TYPE_SERVICE => 'Penyedia Jasa',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(FinanceVendorInvoice::class, 'vendor_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(FinancePurchaseOrder::class, 'vendor_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('is_active', true);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->vendor_type] ?? (string) $this->vendor_type;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_VENDOR';
    }
}
