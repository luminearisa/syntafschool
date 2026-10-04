<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\RfqStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** RFQ / permintaan penawaran. Tabel: `proc_rfqs`. */
class Rfq extends ProcurementModel
{
    use SoftDeletes;

    protected $table = 'proc_rfqs';

    protected $casts = [
        'deadline' => 'datetime',
        'issued_at' => 'datetime',
        'status' => RfqStatus::class,
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(ProcurementEvent::class, 'event_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(RfqVendor::class, 'rfq_id');
    }

    public function invitedVendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'proc_rfq_vendors', 'rfq_id', 'vendor_id')
            ->withPivot(['status', 'invited_at', 'responded_at'])
            ->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(RfqItem::class, 'rfq_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'rfq_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(EvaluationCriteria::class, 'rfq_id');
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [RfqStatus::ISSUED->value, RfqStatus::OPEN->value, RfqStatus::PARTIALLY_RESPONDED->value]);
    }
}
