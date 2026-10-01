<?php

namespace App\Modules\Asset\Policies;

use App\Models\User;
use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Models\AssetStockOpname;

/**
 * Sesi stok opname.
 *
 * Kebijakan ini memakai pemeriksaan kewenangan yang sama dengan layanan
 * (`AssetAccessService`), bukan salinannya — supaya tidak ada perbedaan antara
 * "boleh membuka halaman" dan "boleh menyimpan perubahan".
 */
class AssetStockOpnamePolicy
{
    public function __construct(protected readonly AssetAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('asset.stockopname.view') || $this->access->isAssetAdmin($user);
    }

    public function view(User $user, AssetStockOpname $model): bool
    {
        return $user->can('asset.stockopname.view') || $this->access->isAssetAdmin($user);
    }

    public function create(User $user): bool
    {
        return $user->can('asset.stockopname.create') || $this->access->isAssetAdmin($user);
    }

    public function update(User $user, AssetStockOpname $model): bool
    {
        return $user->can('asset.stockopname.manage') || $this->access->isAssetAdmin($user);
    }

    public function delete(User $user, AssetStockOpname $model): bool
    {
        return false;
    }

    public function restore(User $user, AssetStockOpname $model): bool
    {
        return $this->access->isAssetAdmin($user);
    }
}
