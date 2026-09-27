<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pengajuan pengeluaran (§30–§33). `required_approval_levels`/`current_approval_level`
 * menampilkan progres approval bertingkat; `finance_transaction_id` menaut ke
 * jurnal saat dibayar.
 */
class FinanceExpenseRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'request_number' => $this->request_number,
            'requester_id' => $this->requester_id !== null ? (int) $this->requester_id : null,
            'requester_name' => $this->whenLoaded('requester', fn (): ?string => $this->requester?->name),
            'cost_center_id' => (int) $this->cost_center_id,
            'cost_center_name' => $this->whenLoaded('costCenter', fn (): ?string => $this->costCenter?->name),
            'account_id' => (int) $this->account_id,
            'account_code' => $this->whenLoaded('account', fn (): ?string => $this->account?->code),
            'account_name' => $this->whenLoaded('account', fn (): ?string => $this->account?->name),
            'vendor_id' => $this->vendor_id !== null ? (int) $this->vendor_id : null,
            'vendor_name' => $this->whenLoaded('vendor', fn (): ?string => $this->vendor?->name),
            'payee_name' => $this->payee_name,
            'amount' => (float) $this->amount,
            'description' => $this->description,
            'expense_date' => $this->expense_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'attachment_path' => $this->attachment_path,
            'status' => $this->status,
            'status_label' => $this->resource->statusLabel(),
            'current_approval_level' => (int) $this->current_approval_level,
            'required_approval_levels' => (int) $this->required_approval_levels,
            // Hasil pemeriksaan anggaran (§10) dibekukan saat pengajuan: kebijakan
            // mana yang berlaku, berapa kelebihannya, siapa yang menerimanya.
            'budget_control' => $this->budget_control,
            'budget_shortfall' => (float) $this->budget_shortfall,
            'overran_budget' => $this->resource->overranBudget(),
            'budget_override_by' => $this->budget_override_by !== null ? (int) $this->budget_override_by : null,
            'budget_override_by_name' => $this->whenLoaded('budgetOverrideBy', fn (): ?string => $this->budgetOverrideBy?->name),
            'budget_override_at' => $this->budget_override_at?->toIso8601String(),
            'approved_by' => $this->approved_by !== null ? (int) $this->approved_by : null,
            'approved_by_name' => $this->whenLoaded('approvedBy', fn (): ?string => $this->approvedBy?->name),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'reject_reason' => $this->reject_reason,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cash_account_id' => $this->cash_account_id !== null ? (int) $this->cash_account_id : null,
            'bank_account_id' => $this->bank_account_id !== null ? (int) $this->bank_account_id : null,
            'finance_transaction_id' => $this->finance_transaction_id !== null ? (int) $this->finance_transaction_id : null,
            'expenses' => FinanceExpenseResource::collection($this->whenLoaded('expenses')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
