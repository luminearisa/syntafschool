<?php

namespace App\Modules\Bk\Policies;

use App\Models\User;
use App\Modules\Bk\Application\Services\BkAccessService;
use App\Modules\Bk\Models\BkAppointment;

/**
 * Kebijakan janji temu.
 */
class BkAppointmentPolicy
{
    public function __construct(private readonly BkAccessService $access)
    {
    }

    public function view(User $user, BkAppointment $model): bool
    {
        return $this->access->canViewAppointment($user, $model);
    }

    public function manage(User $user, BkAppointment $model): bool
    {
        return $this->access->canManageAppointment($user, $model);
    }

    public function cancel(User $user, BkAppointment $model): bool
    {
        return $this->access->canManageAppointment($user, $model);
    }
}
