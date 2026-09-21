<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris tagihan.
 *
 * `fee_id` adalah rujukan konfigurasi biaya internal dan hanya ditampilkan ke
 * petugas; portal cukup melihat nama & nominal yang memang harus dibayar.
 */
class PpdbInvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->hasAnyPermission(['ppdb.payment.view', 'ppdb.registration.view']) ?? false;

        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'name' => $this->name,
            'amount' => (float) $this->amount,
            'fee_id' => $this->when($staff, $this->fee_id),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
