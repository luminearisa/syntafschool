<?php

namespace App\Modules\Asset\Policies;

use App\Models\User;
use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Models\FacilityBooking;

/**
 * Pemesanan fasilitas.
 *
 * Kebijakan ini memakai pemeriksaan kewenangan yang sama dengan layanan
 * (`AssetAccessService`), bukan salinannya — supaya tidak ada perbedaan antara
 * "boleh membuka halaman" dan "boleh menyimpan perubahan".
 */
class FacilityBookingPolicy
{
    public function __construct(protected readonly AssetAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('facility.book') || $this->access->isFacilityManager($user);
    }

    public function view(User $user, FacilityBooking $model): bool
    {
        return $user->can('facility.book') || $user->id === $model->requested_by || $this->access->isFacilityManager($user);
    }

    public function create(User $user): bool
    {
        return $user->can('facility.book');
    }

    public function update(User $user, FacilityBooking $model): bool
    {
        return $user->can('facility.booking.approve') || $this->access->isFacilityManager($user);
    }

    public function delete(User $user, FacilityBooking $model): bool
    {
        return false;
    }

    public function restore(User $user, FacilityBooking $model): bool
    {
        return $this->access->isAssetAdmin($user);
    }
}
