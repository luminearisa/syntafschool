<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expense terealisasi (§30) — baris pengeluaran yang sudah diposting ke ledger.
 */
class FinanceExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'expense_number' => $this->expense_number,
            'expense_request_id' => $this->expense_request_id !== null ? (int) $this->expense_request_id : null,
            'expense_request_number' => $this->whenLoaded('expenseRequest', fn (): ?string => $this->expenseRequest?->request_number),
            'cost_center_id' => (int) $this->cost_center_id,
            'cost_center_name' => $this->whenLoaded('costCenter', fn (): ?string => $this->costCenter?->name),
            'account_id' => (int) $this->account_id,
            'account_code' => $this->whenLoaded('account', fn (): ?string => $this->account?->code),
            'account_name' => $this->whenLoaded('account', fn (): ?string => $this->account?->name),
            'vendor_id' => $this->vendor_id !== null ? (int) $this->vendor_id : null,
            'vendor_name' => $this->whenLoaded('vendor', fn (): ?string => $this->vendor?->name),
            'amount' => (float) $this->amount,
            'description' => $this->description,
            'expense_date' => $this->expense_date?->toDateString(),
            'payment_id' => $this->payment_id !== null ? (int) $this->payment_id : null,
            'finance_transaction_id' => $this->finance_transaction_id !== null ? (int) $this->finance_transaction_id : null,
            'status' => $this->status,
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'created_by_name' => $this->whenLoaded('createdBy', fn (): ?string => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
