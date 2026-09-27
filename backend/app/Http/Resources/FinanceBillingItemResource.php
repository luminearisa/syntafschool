<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris billing (§13): jenis tagihan + nominal + potongan diskon/beasiswa.
 */
class FinanceBillingItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'billing_id' => (int) $this->billing_id,
            'fee_type_id' => $this->fee_type_id !== null ? (int) $this->fee_type_id : null,
            'fee_type_code' => $this->whenLoaded('feeType', fn (): ?string => $this->feeType?->code),
            'fee_type_name' => $this->whenLoaded('feeType', fn (): ?string => $this->feeType?->name),
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'quantity' => (int) $this->quantity,
            'discount_amount' => (float) $this->discount_amount,
            'scholarship_amount' => (float) $this->scholarship_amount,
            'total' => (float) $this->total,
        ];
    }
}
