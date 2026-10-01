<?php

namespace App\Modules\Asset\Policies;

use App\Models\User;
use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Models\AssetTransfer;

/**
 * Pemindahan aset.
 *
 * Kebijakan ini memakai pemeriksaan kewenangan yang sama dengan layanan
 * (`AssetAccessService`), bukan salinannya — supaya tidak ada perbedaan antara
 * "boleh membuka halaman" dan "boleh menyimpan perubahan".
 */
class AssetTransferPolicy
{
    public function __construct(protected readonly AssetAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('asset.transfer.view') || $this->access->isAssetAdmin($user);
    }

    public function view(User $user, AssetTransfer $model): bool
    {
        return $user->can('asset.transfer.view') || $user->id === $model->requested_by || $user->id === $model->received_by;
    }

    public function create(User $user): bool
    {
        return $user->can('asset.transfer.request');
    }

    public function update(User $user, AssetTransfer $model): bool
    {
        return $user->can('asset.transfer.approve') || $this->access->isAssetAdmin($user);
    }

    public function delete(User $user, AssetTransfer $model): bool
    {
        return false;
    }

    public function restore(User $user, AssetTransfer $model): bool
    {
        return $this->access->isAssetAdmin($user);
    }
}
