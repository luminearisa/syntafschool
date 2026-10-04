<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Procurement\Domain\Enums\ExceptionStatus;
use App\Modules\Procurement\Domain\Enums\ExceptionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pengecualian invoice (mismatch/duplikat/dsb.). Tabel: `proc_invoice_exceptions`. */
class InvoiceException extends ProcurementModel
{
    protected $table = 'proc_invoice_exceptions';

    protected $casts = [
        'type' => ExceptionType::class,
        'status' => ExceptionStatus::class,
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class, 'vendor_invoice_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [ExceptionStatus::DETECTED->value, ExceptionStatus::INVESTIGATION->value]);
    }
}
