<?php

namespace App\Http\Resources;

use App\Models\FinanceVendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vendor (§34) — penerima pembayaran pengeluaran/pembelian.
 */
class FinanceVendorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'vendor_type' => $this->vendor_type,
            'vendor_type_label' => FinanceVendor::TYPE_LABELS[$this->vendor_type] ?? $this->vendor_type,
            'tax_id' => $this->tax_id,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'account_name' => $this->account_name,
            'status' => $this->status,
            'is_active' => (bool) $this->is_active,
            'invoices_count' => $this->when(isset($this->invoices_count), fn (): int => (int) $this->invoices_count),
            'purchase_orders_count' => $this->when(isset($this->purchase_orders_count), fn (): int => (int) $this->purchase_orders_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
