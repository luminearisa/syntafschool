<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Expense terealisasi (§30) — baris pengeluaran yang sudah diposting ke ledger,
 * terhubung ke request asalnya dan (bila dibayar tunai/bank) ke payment.
 */
class FinanceExpense extends BaseModel
{
    use LogsFinanceActivity;

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
    ];

    public function expenseRequest(): BelongsTo
    {
        return $this->belongsTo(FinanceExpenseRequest::class, 'expense_request_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinanceCostCenter::class, 'cost_center_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FinanceVendor::class, 'vendor_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FinancePayment::class, 'payment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_EXPENSE';
    }
}
