<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Invoice vendor + Accounts Payable (§35, §36). `remaining_amount` = AP
 * outstanding per invoice.
 */
class FinanceVendorInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'vendor_invoice_number' => $this->vendor_invoice_number,
            'vendor_id' => (int) $this->vendor_id,
            'vendor_name' => $this->whenLoaded('vendor', fn (): ?string => $this->vendor?->name),
            'invoice_date' => $this->invoice_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'subtotal' => (float) $this->subtotal,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
            'paid_amount' => (float) $this->paid_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'status_label' => $this->resource->statusLabel(),
            'purchase_order_id' => $this->purchase_order_id !== null ? (int) $this->purchase_order_id : null,
            'purchase_order_number' => $this->whenLoaded('purchaseOrder', fn (): ?string => $this->purchaseOrder?->po_number),
            'expense_request_id' => $this->expense_request_id !== null ? (int) $this->expense_request_id : null,
            'cost_center_id' => $this->cost_center_id !== null ? (int) $this->cost_center_id : null,
            'cost_center_name' => $this->whenLoaded('costCenter', fn (): ?string => $this->costCenter?->name),
            'account_id' => $this->account_id !== null ? (int) $this->account_id : null,
            'account_code' => $this->whenLoaded('account', fn (): ?string => $this->account?->code),
            'account_name' => $this->whenLoaded('account', fn (): ?string => $this->account?->name),
            'notes' => $this->notes,
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
