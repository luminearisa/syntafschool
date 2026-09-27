<?php

namespace App\Http\Resources;

use App\Models\FinanceFeeType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris paket tagihan (§12): satu jenis tagihan + nominal + frekuensi.
 */
class FinanceFeeItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'fee_package_id' => (int) $this->fee_package_id,
            'fee_type_id' => (int) $this->fee_type_id,
            'fee_type_code' => $this->whenLoaded('feeType', fn (): ?string => $this->feeType?->code),
            'fee_type_name' => $this->whenLoaded('feeType', fn (): ?string => $this->feeType?->name),
            'amount' => (float) $this->amount,
            'frequency' => $this->frequency,
            'frequency_label' => FinanceFeeType::FREQUENCY_LABELS[$this->frequency] ?? $this->frequency,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
