<?php

namespace App\Modules\Asset\Policies;

use App\Models\User;
use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Models\AssetInspection;

/**
 * Inspeksi aset & fasilitas.
 *
 * Kebijakan ini memakai pemeriksaan kewenangan yang sama dengan layanan
 * (`AssetAccessService`), bukan salinannya — supaya tidak ada perbedaan antara
 * "boleh membuka halaman" dan "boleh menyimpan perubahan".
 */
class AssetInspectionPolicy
{
    public function __construct(protected readonly AssetAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('asset.inspect.view') || $this->access->isMaintenanceManager($user);
    }

    public function view(User $user, AssetInspection $model): bool
    {
        return $user->can('asset.inspect.view') || $this->access->isMaintenanceManager($user);
    }

    public function create(User $user): bool
    {
        return $user->can('asset.inspect.manage');
    }

    public function update(User $user, AssetInspection $model): bool
    {
        return $user->can('asset.inspect.manage') || $this->access->isTechnician($user);
    }

    public function delete(User $user, AssetInspection $model): bool
    {
        return false;
    }

    public function restore(User $user, AssetInspection $model): bool
    {
        return $this->access->isAssetAdmin($user);
    }
}
