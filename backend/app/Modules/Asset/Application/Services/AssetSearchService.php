<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\Facility;
use App\Modules\Asset\Models\Location;
use App\Modules\Asset\Models\MaintenanceRequest;
use App\Modules\Asset\Models\MaintenanceWorkOrder;
use Illuminate\Support\Collection;

/**
 * Pencarian global modul: satu kotak untuk kode aset, nama, nomor seri, barcode,
 * QR, lokasi, gedung, ruang, pemegang, fasilitas, permintaan, dan work order.
 *
 * Pencarian WAJIB menghormati dua hal sekaligus: unit sekolah (tenant) dan
 * kewenangan pemanggil. Tanpa yang kedua, pencarian menjadi jalan pintas untuk
 * melihat data yang justru dibatasi di halaman lain.
 */
class AssetSearchService
{
    public function __construct(private readonly AssetAccessService $access) {}

    public function search(User $user, string $keyword, int $limit = 25): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return ['assets' => [], 'facilities' => [], 'locations' => [], 'maintenance' => []];
        }

        return [
            'assets' => $this->assets($user, $keyword, $limit),
            'facilities' => $this->facilities($user, $keyword, $limit),
            'locations' => $this->locations($user, $keyword, min(10, $limit)),
            'maintenance' => $this->maintenance($user, $keyword, min(10, $limit)),
        ];
    }

    protected function assets(User $user, string $keyword, int $limit): array
    {
        $query = Asset::query()
            ->with('location:id,name')
            ->where(function ($q) use ($keyword): void {
                $q->where('asset_code', 'like', "%$keyword%")
                    ->orWhere('name', 'like', "%$keyword%")
                    ->orWhere('serial_number', 'like', "%$keyword%")
                    ->orWhere('barcode', 'like', "%$keyword%")
                    ->orWhere('qr_token', $keyword)
                    ->orWhere('asset_tag', 'like', "%$keyword%")
                    ->orWhereHas('location', fn ($l) => $l->where('name', 'like', "%$keyword%")->orWhere('code', 'like', "%$keyword%"));
            })
            ->orderBy('asset_code')
            ->limit($limit);

        $this->access->scopeAssets($query, $user);

        return $query->get()->map(fn (Asset $asset): array => [
            'id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            'status' => $asset->status?->value ?? (string) $asset->status,
            'condition' => $asset->condition?->value ?? (string) $asset->condition,
            'location' => $asset->location?->name,
            'url' => '/assets/'.$asset->id,
        ])->all();
    }

    protected function facilities(User $user, string $keyword, int $limit): array
    {
        return Facility::query()
            ->where(function ($q) use ($keyword): void {
                $q->where('facility_code', 'like', "%$keyword%")
                    ->orWhere('name', 'like', "%$keyword%")
                    ->orWhereHas('location', fn ($l) => $l->where('name', 'like', "%$keyword%"));
            })
            ->orderBy('facility_code')
            ->limit($limit)
            ->get()
            ->map(fn (Facility $facility): array => [
                'id' => $facility->id,
                'facility_code' => $facility->facility_code,
                'name' => $facility->name,
                'status' => $facility->status?->value ?? (string) $facility->status,
                'location' => $facility->location?->name,
                'url' => '/facilities/'.$facility->id,
            ])
            ->all();
    }

    protected function locations(User $user, string $keyword, int $limit): array
    {
        $query = Location::query()->where(function ($q) use ($keyword): void {
            $q->where('name', 'like', "%$keyword%")->orWhere('code', 'like', "%$keyword%")->orWhere('path', 'like', "%$keyword%");
        })->orderBy('path')->limit($limit);

        $this->access->scopeLocations($query, $user);

        return $query->get()->map(fn (Location $location): array => [
            'id' => $location->id,
            'code' => $location->code,
            'name' => $location->name,
            'path' => $location->path,
            'location_type' => $location->location_type?->value ?? (string) $location->location_type,
            'url' => '/locations/'.$location->id,
        ])->all();
    }

    protected function maintenance(User $user, string $keyword, int $limit): array
    {
        $workOrders = MaintenanceWorkOrder::query()
            ->where(function ($q) use ($keyword): void {
                $q->where('work_order_number', 'like', "%$keyword%")
                    ->orWhere('title', 'like', "%$keyword%")
                    ->orWhereHas('asset', fn ($a) => $a->where('asset_code', 'like', "%$keyword%")->orWhere('name', 'like', "%$keyword%"));
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $requests = MaintenanceRequest::query()
            ->where('request_number', 'like', "%$keyword%")
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return Collection::make($workOrders->map(fn (MaintenanceWorkOrder $wo): array => [
            'id' => $wo->id,
            'type' => 'work_order',
            'number' => $wo->work_order_number,
            'title' => $wo->title,
            'status' => $wo->status?->value ?? (string) $wo->status,
            'url' => '/maintenance/work-orders/'.$wo->id,
        ]))->merge($requests->map(fn (MaintenanceRequest $mr): array => [
            'id' => $mr->id,
            'type' => 'request',
            'number' => $mr->request_number,
            'title' => $mr->title,
            'status' => $mr->status?->value ?? (string) $mr->status,
            'url' => '/maintenance/requests/'.$mr->id,
        ]))->all();
    }
}
