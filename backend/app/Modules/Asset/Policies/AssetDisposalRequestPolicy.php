<?php

namespace App\Modules\Asset\Policies;

use App\Models\User;
use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Models\AssetDisposalRequest;

/**
 * Permintaan penghapusan aset.
 *
 * Kebijakan ini memakai pemeriksaan kewenangan yang sama dengan layanan
 * (`AssetAccessService`), bukan salinannya — supaya tidak ada perbedaan antara
 * "boleh membuka halaman" dan "boleh menyimpan perubahan".
 */
class AssetDisposalRequestPolicy
{
    public function __construct(protected readonly AssetAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('asset.disposal.view') || $this->access->isAssetAdmin($user);
    }

    public function view(User $user, AssetDisposalRequest $model): bool
    {
        return $user->can('asset.disposal.view') || $this->access->isAssetAdmin($user) || $user->id === $model->requested_by;
    }

    public function create(User $user): bool
    {
        return $user->can('asset.disposal.request');
    }

    public function update(User $user, AssetDisposalRequest $model): bool
    {
        return $user->can('asset.disposal.approve') || $this->access->isAssetAdmin($user);
    }

    public function delete(User $user, AssetDisposalRequest $model): bool
    {
        return false;
    }

    public function restore(User $user, AssetDisposalRequest $model): bool
    {
        return $this->access->isAssetAdmin($user);
    }
}
