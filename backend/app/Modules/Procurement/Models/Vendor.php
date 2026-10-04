<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\Enums\VendorClassification;
use App\Modules\Procurement\Domain\Enums\VendorStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vendor / rekanan. Tabel: `proc_vendors`.
 *
 * Sumber kebenaran data rekanan untuk seluruh ERP (Procurement, Asset, HRD).
 * Hanya vendor berstatus ACTIVE dan tidak restricted yang boleh dipakai pada PO
 * (§52.16) — ditegakkan `VendorService::assertUsable()`.
 */
class Vendor extends ProcurementModel
{
    use SoftDeletes;

    protected $table = 'proc_vendors';

    protected $casts = [
        'tax_rate' => 'decimal:2',
        'verified_at' => 'datetime',
        'status' => VendorStatus::class,
        'classification' => VendorClassification::class,
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(VendorContact::class, 'vendor_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(VendorAddress::class, 'vendor_id');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(VendorBankAccount::class, 'vendor_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VendorDocument::class, 'vendor_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'proc_vendor_categories', 'vendor_id', 'category_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function performanceRecords(): HasMany
    {
        return $this->hasMany(VendorPerformanceRecord::class, 'vendor_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'vendor_id');
    }

    /** @param Builder<self> $query */
    public function scopeUsable(Builder $query): void
    {
        $query->where('status', VendorStatus::ACTIVE->value)
            ->whereNotIn('classification', [
                VendorClassification::RESTRICTED->value,
                VendorClassification::BLACKLISTED->value,
            ]);
    }

    /** @param Builder<self> $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $like = '%'.$term.'%';
        $query->where(fn (Builder $q) => $q
            ->where('code', 'like', $like)
            ->orWhere('legal_name', 'like', $like)
            ->orWhere('trading_name', 'like', $like)
            ->orWhere('npwp', 'like', $like)
            ->orWhere('email', 'like', $like));
    }

    /** Apakah vendor memenuhi syarat untuk dipakai pada PO. */
    public function isUsable(): bool
    {
        $status = $this->status instanceof VendorStatus ? $this->status : VendorStatus::tryFrom((string) $this->status);
        $classification = $this->classification instanceof VendorClassification
            ? $this->classification
            : VendorClassification::tryFrom((string) $this->classification);

        return $status === VendorStatus::ACTIVE && ! ($classification?->isRestricted() ?? false);
    }

    /** Dokumen wajib yang sudah kedaluwarsa (§52.17). */
    public function hasExpiredMandatoryDocuments(): bool
    {
        return $this->documents()
            ->where('is_mandatory', true)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->exists();
    }
}
