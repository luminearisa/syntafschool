<?php

namespace App\Modules\Asset\Policies;

use App\Models\User;
use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Models\Location;

/**
 * Lokasi berjenjang.
 *
 * Kebijakan ini memakai pemeriksaan kewenangan yang sama dengan layanan
 * (`AssetAccessService`), bukan salinannya — supaya tidak ada perbedaan antara
 * "boleh membuka halaman" dan "boleh menyimpan perubahan".
 */
class LocationPolicy
{
    public function __construct(protected readonly AssetAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('location.view') || $this->access->isAssetAdmin($user);
    }

    public function view(User $user, Location $model): bool
    {
        return $user->can('location.view') || $this->access->isFacilityManager($user);
    }

    public function create(User $user): bool
    {
        return $user->can('location.manage');
    }

    public function update(User $user, Location $model): bool
    {
        return $user->can('location.manage');
    }

    public function delete(User $user, Location $model): bool
    {
        return false;
    }

    public function restore(User $user, Location $model): bool
    {
        return $this->access->isAssetAdmin($user);
    }
}
