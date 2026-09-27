<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris invoice (§16): deskripsi + akun pendapatan (COA) tempat jurnal diakrual.
 */
class FinanceInvoiceItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'invoice_id' => (int) $this->invoice_id,
            'fee_type_id' => $this->fee_type_id !== null ? (int) $this->fee_type_id : null,
            'fee_type_name' => $this->whenLoaded('feeType', fn (): ?string => $this->feeType?->name),
            'account_id' => $this->account_id !== null ? (int) $this->account_id : null,
            'account_code' => $this->whenLoaded('account', fn (): ?string => $this->account?->code),
            'account_name' => $this->whenLoaded('account', fn (): ?string => $this->account?->name),
            'description' => $this->description,
            'quantity' => (int) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'discount_amount' => (float) $this->discount_amount,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
        ];
    }
}
