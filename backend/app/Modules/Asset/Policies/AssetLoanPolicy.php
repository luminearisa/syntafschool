<?php

namespace App\Modules\Asset\Policies;

use App\Models\User;
use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Models\AssetLoan;

/**
 * Peminjaman aset.
 *
 * Kebijakan ini memakai pemeriksaan kewenangan yang sama dengan layanan
 * (`AssetAccessService`), bukan salinannya — supaya tidak ada perbedaan antara
 * "boleh membuka halaman" dan "boleh menyimpan perubahan".
 */
class AssetLoanPolicy
{
    public function __construct(protected readonly AssetAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('asset.loan.view') || $this->access->isAssetAdmin($user);
    }

    public function view(User $user, AssetLoan $model): bool
    {
        return $user->can('asset.loan.view') || $user->id === $model->borrower_id || $user->id === $model->requested_by;
    }

    public function create(User $user): bool
    {
        return $user->can('asset.loan.request') || $this->access->isAssetAdmin($user);
    }

    public function update(User $user, AssetLoan $model): bool
    {
        return $user->can('asset.loan.manage') || $this->access->isAssetAdmin($user);
    }

    public function delete(User $user, AssetLoan $model): bool
    {
        return false;
    }

    public function restore(User $user, AssetLoan $model): bool
    {
        return $this->access->isAssetAdmin($user);
    }
}
