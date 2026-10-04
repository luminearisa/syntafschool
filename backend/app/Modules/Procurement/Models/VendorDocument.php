<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\Enums\VendorDocumentStatus;
use App\Modules\Procurement\Domain\Enums\VendorDocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dokumen vendor (onboarding & kepatuhan). Tabel: `proc_vendor_documents`. */
class VendorDocument extends ProcurementModel
{
    protected $table = 'proc_vendor_documents';

    protected $casts = [
        'issued_date' => 'date',
        'expiry_date' => 'date',
        'verified_at' => 'datetime',
        'is_mandatory' => 'boolean',
        'type' => VendorDocumentType::class,
        'status' => VendorDocumentStatus::class,
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** @param Builder<self> $query */
    public function scopeExpired(Builder $query): void
    {
        $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now()->toDateString());
    }

    /** @param Builder<self> $query */
    public function scopeExpiringWithin(Builder $query, int $days): void
    {
        $query->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString());
    }
}
