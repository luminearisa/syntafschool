<?php

namespace App\Modules\Procurement\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\VendorProviderInterface;
use App\Modules\Procurement\Models\Contract;
use App\Modules\Procurement\Models\Vendor;

/**
 * Adapter untuk modul Asset: menyediakan data vendor sebagai sumber kebenaran.
 *
 * Modul Asset memakai `VendorProviderInterface`-nya sendiri agar nama vendor
 * tidak pernah disimpan sebagai teks bebas di work order.
 */
class EloquentVendorProvider implements VendorProviderInterface
{
    public function vendor(int $vendorId): ?array
    {
        $vendor = Vendor::query()->find($vendorId);

        return $vendor === null ? null : $this->toArray($vendor);
    }

    public function search(string $keyword, int $limit = 10): array
    {
        return Vendor::query()
            ->search($keyword)
            ->orderBy('legal_name')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (Vendor $vendor): array => $this->toArray($vendor))
            ->all();
    }

    public function contractedVendors(int $assetId): array
    {
        // Pemetaan aset → kontrak vendor belum dimiliki Procurement; yang dapat
        // dijawab dengan pasti adalah vendor yang memiliki kontrak aktif.
        return Contract::query()
            ->active()
            ->with('vendor')
            ->get()
            ->pluck('vendor')
            ->filter()
            ->unique('id')
            ->map(fn (Vendor $vendor): array => $this->toArray($vendor))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function toArray(Vendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'code' => $vendor->code,
            'name' => $vendor->legal_name,
            'trading_name' => $vendor->trading_name,
            'type' => $vendor->vendor_type,
            'status' => $vendor->status instanceof \BackedEnum ? $vendor->status->value : $vendor->status,
            'classification' => $vendor->classification instanceof \BackedEnum ? $vendor->classification->value : $vendor->classification,
            'email' => $vendor->email,
            'phone' => $vendor->phone,
            'address' => $vendor->address,
            'is_usable' => $vendor->isUsable(),
        ];
    }
}
