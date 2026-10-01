<?php

namespace App\Modules\Asset\Policies;

use App\Models\User;
use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Models\Facility;

/**
 * Fasilitas: pengelolaan dan pemesanan.
 *
 * Kebijakan ini memakai pemeriksaan kewenangan yang sama dengan layanan
 * (`AssetAccessService`), bukan salinannya — supaya tidak ada perbedaan antara
 * "boleh membuka halaman" dan "boleh menyimpan perubahan".
 */
class FacilityPolicy
{
    public function __construct(protected readonly AssetAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('facility.view') || $this->access->isFacilityManager($user);
    }

    public function view(User $user, Facility $model): bool
    {
        return $user->can('facility.view') || $this->access->isFacilityManager($user);
    }

    public function create(User $user): bool
    {
        return $user->can('facility.manage') || $this->access->isFacilityManager($user);
    }

    public function update(User $user, Facility $model): bool
    {
        return $user->can('facility.manage') || $this->access->isFacilityManager($user);
    }

    public function delete(User $user, Facility $model): bool
    {
        return false;
    }

    public function restore(User $user, Facility $model): bool
    {
        return $this->access->isAssetAdmin($user);
    }
}
