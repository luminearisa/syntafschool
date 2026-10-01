<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\VendorProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jembatan ke data vendor.
 *
 * Nama vendor TIDAK disimpan sebagai teks bebas pada work order atau kontrak:
 * selalu rujukan, supaya rekap biaya per vendor dapat dipercaya. Tabel vendor
 * yang ada saat ini milik modul Keuangan (`finance_vendors`).
 */
class EloquentVendorProvider implements VendorProviderInterface
{
    public function vendor(int $vendorId): ?array
    {
        $row = $this->query()->where('id', $vendorId)->first();

        return $row === null ? null : (array) $row;
    }

    public function search(string $keyword, int $limit = 10): array
    {
        return $this->query()
            ->where('name', 'like', "%$keyword%")
            ->orWhere('code', 'like', "%$keyword%")
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => ['id' => $row->id, 'code' => $row->code ?? null, 'name' => $row->name])
            ->all();
    }

    public function contractedVendors(int $assetId): array
    {
        if (! Schema::hasTable('maintenance_contract_assets') || ! Schema::hasTable('maintenance_contracts')) {
            return [];
        }

        return DB::table('maintenance_contract_assets')
            ->join('maintenance_contracts', 'maintenance_contracts.id', '=', 'maintenance_contract_assets.contract_id')
            ->where('maintenance_contract_assets.asset_id', $assetId)
            ->where('maintenance_contracts.status', 'ACTIVE')
            ->get(['maintenance_contracts.id', 'maintenance_contracts.contract_number', 'maintenance_contracts.vendor_id'])
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    protected function query()
    {
        if (Schema::hasTable('finance_vendors')) {
            return DB::table('finance_vendors');
        }

        return DB::table('vendors');
    }
}
