<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu angsuran (§7: sequence, due_date, amount, paid_amount, outstanding,
 * status). Nama `outstanding` dipakai di API sesuai spesifikasi; kolomnya
 * `outstanding_amount` agar setara dengan `remaining_amount` di invoice.
 *
 * Ketiga angka berlabel "uang" di sini adalah hasil bagi atas pelunasan invoice,
 * bukan penerimaan baru — jumlahkan seluruh angsuran pun hasilnya tetap
 * `invoice.paid_amount`.
 */
class FinancePaymentPlanInstallmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'payment_plan_id' => (int) $this->payment_plan_id,
            'sequence' => (int) $this->sequence,
            'label' => $this->resource->label(),
            'due_date' => $this->due_date?->toDateString(),
            'amount' => (float) $this->amount,
            'paid_amount' => (float) $this->paid_amount,
            'outstanding' => (float) $this->outstanding_amount,
            'status' => $this->status,
            'status_label' => $this->resource->statusLabel(),
        ];
    }
}
