<?php

namespace App\Modules\Asset\Policies;

use App\Models\User;
use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Models\MaintenanceRequest;

/**
 * Permintaan pemeliharaan.
 *
 * Kebijakan ini memakai pemeriksaan kewenangan yang sama dengan layanan
 * (`AssetAccessService`), bukan salinannya — supaya tidak ada perbedaan antara
 * "boleh membuka halaman" dan "boleh menyimpan perubahan".
 */
class MaintenanceRequestPolicy
{
    public function __construct(protected readonly AssetAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('asset.maintenance.view') || $this->access->isMaintenanceManager($user);
    }

    public function view(User $user, MaintenanceRequest $model): bool
    {
        return $user->can('asset.maintenance.view') || $this->access->isMaintenanceManager($user) || $user->id === $model->requested_by;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MaintenanceRequest $model): bool
    {
        return $user->can('asset.maintenance.manage') || $this->access->isMaintenanceManager($user);
    }

    public function delete(User $user, MaintenanceRequest $model): bool
    {
        return false;
    }

    public function restore(User $user, MaintenanceRequest $model): bool
    {
        return $this->access->isAssetAdmin($user);
    }
}
