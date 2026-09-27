<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Receipt / kuitansi (§24, §25) — terbit hanya untuk payment yang sudah
 * confirmed. Pembatalan TIDAK menghapus: kuitansi ditandai `is_cancelled`
 * dan reversalnya ditunjuk `reversal_receipt_id`, dokumen asli tetap tercatat.
 */
class FinanceReceipt extends BaseModel
{
    use LogsFinanceActivity;

    protected $casts = [
        'customer_id' => 'integer',
        'amount' => 'decimal:2',
        'received_at' => 'datetime',
        'is_cancelled' => 'boolean',
        'cancelled_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FinancePayment::class, 'payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'invoice_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function reversalReceipt(): BelongsTo
    {
        return $this->belongsTo(FinanceReceipt::class, 'reversal_receipt_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_RECEIPT';
    }
}
