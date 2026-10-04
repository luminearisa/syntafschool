<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Penawaran vendor. Tabel: `proc_quotations`.
 *
 * Nilai penawaran bersifat **snapshot**: perubahan master vendor/item tidak
 * mengubah histori penawaran (§13, §52.20).
 */
class Quotation extends ProcurementModel
{
    use SoftDeletes;

    protected $table = 'proc_quotations';

    protected $casts = [
        'quotation_date' => 'date',
        'valid_until' => 'date',
        'shipping_cost' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
        'delivery_days' => 'integer',
        'status' => QuotationStatus::class,
    ];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ProcurementEvent::class, 'event_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class, 'quotation_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(QuotationDocument::class, 'quotation_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(QuotationEvaluation::class, 'quotation_id');
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /** @param Builder<self> $query */
    public function scopeValid(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereNull('valid_until')
            ->orWhereDate('valid_until', '>=', now()->toDateString()));
    }
}
