<?php

namespace App\Modules\Bk\Policies;

use App\Models\User;
use App\Modules\Bk\Application\Services\BkAccessService;
use App\Modules\Bk\Models\BkReferral;

/**
 * Kebijakan referral: pelapor melihat miliknya, staf BK melihat antrean.
 */
class BkReferralPolicy
{
    public function __construct(private readonly BkAccessService $access)
    {
    }

    public function view(User $user, BkReferral $model): bool
    {
        return $this->access->canViewReferral($user, $model);
    }

    public function create(User $user, BkReferral $model): bool
    {
        return $user->hasPermissionTo("bk.referral.create");
    }

    public function screen(User $user, BkReferral $model): bool
    {
        return $this->access->isBkStaff($user);
    }

    public function receive(User $user, BkReferral $model): bool
    {
        return $this->access->isBkStaff($user);
    }

    public function update(User $user, BkReferral $model): bool
    {
        return $this->access->isBkStaff($user);
    }
}
