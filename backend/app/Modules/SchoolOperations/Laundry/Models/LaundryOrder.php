<?php

namespace App\Modules\SchoolOperations\Laundry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Order laundry — transaksi utama modul (§9).
 * Harga & porsi free/paid disnapshot saat calculate (Rule 9/23). Order tidak
 * pernah dihapus keras (Rule 17); soft delete hanya administratif.
 */
class LaundryOrder extends Model
{
    use SoftDeletes;

    protected $table = 'laundry_orders';

    protected $guarded = ['id'];

    protected $casts = [
        'order_date' => 'date',
        'received_at' => 'datetime',
        'weighed_at' => 'datetime',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'gross_weight' => 'decimal:3',
        'bag_weight' => 'decimal:3',
        'net_weight' => 'decimal:3',
        'item_count' => 'integer',
        'free_quantity' => 'decimal:3',
        'paid_quantity' => 'decimal:3',
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'pricing_snapshot' => 'array',
        'allow_unpaid_processing' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(LaundryService::class, 'laundry_service_id');
    }

    public function bag(): BelongsTo
    {
        return $this->belongsTo(LaundryBag::class, 'laundry_bag_id');
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(LaundryEntitlement::class, 'laundry_entitlement_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LaundryOrderItem::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LaundryOrderAllocation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LaundryPayment::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(LaundryPaymentAttempt::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(LaundryRefund::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(LaundryProcessingStep::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(LaundryProcessingLog::class)->orderBy('id');
    }

    public function qualityChecks(): HasMany
    {
        return $this->hasMany(LaundryQualityCheck::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(LaundryIncident::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(LaundryLabel::class);
    }

    public function pickups(): HasMany
    {
        return $this->hasMany(LaundryPickup::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(LaundryDelivery::class);
    }

    public function batchOrders(): HasMany
    {
        return $this->hasMany(LaundryBatchOrder::class);
    }

    /** Nominal yang harus dibayar setelah diskon/override. */
    public function payableAmount(): float
    {
        return max(0.0, round((float) $this->total_amount - (float) $this->discount_amount, 2));
    }

    /** Rule 27: boleh diproses bila lunas, gratis, di-waive, atau override beralasan. */
    public function isProcessable(): bool
    {
        return in_array($this->payment_status, ['PAID', 'NOT_REQUIRED', 'WAIVED'], true)
            || (bool) $this->allow_unpaid_processing;
    }
}
