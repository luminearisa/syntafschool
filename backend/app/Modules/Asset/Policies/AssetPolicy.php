<?php

namespace App\Modules\Asset\Policies;

use App\Models\User;
use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Models\Asset;

/**
 * Aset individual: siapa boleh melihat, mengubah, dan menghapus.
 *
 * Kebijakan ini memakai pemeriksaan kewenangan yang sama dengan layanan
 * (`AssetAccessService`), bukan salinannya — supaya tidak ada perbedaan antara
 * "boleh membuka halaman" dan "boleh menyimpan perubahan".
 */
class AssetPolicy
{
    public function __construct(protected readonly AssetAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('asset.view') || $this->access->isAssetAdmin($user);
    }

    public function view(User $user, Asset $model): bool
    {
        return $this->access->isAssetAdmin($user) || $user->can('asset.view.all') || $this->access->canViewAsset($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->can('asset.register') || $this->access->isAssetAdmin($user);
    }

    public function update(User $user, Asset $model): bool
    {
        return $user->can('asset.manage') || $this->access->isAssetAdmin($user);
    }

    public function delete(User $user, Asset $model): bool
    {
        return false;
    }

    public function restore(User $user, Asset $model): bool
    {
        return $this->access->isAssetAdmin($user);
    }
}
