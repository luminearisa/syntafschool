<?php

namespace App\Modules\Bk\Policies;

use App\Models\User;
use App\Modules\Bk\Application\Services\BkAccessService;
use App\Modules\Bk\Models\BkFollowUp;

/**
 * Kebijakan tindak lanjut.
 */
class BkFollowUpPolicy
{
    public function __construct(private readonly BkAccessService $access)
    {
    }

    public function view(User $user, BkFollowUp $model): bool
    {
        return $this->access->canViewFollowUp($user, $model);
    }

    public function complete(User $user, BkFollowUp $model): bool
    {
        return $this->access->canViewFollowUp($user, $model);
    }

    public function reschedule(User $user, BkFollowUp $model): bool
    {
        return $this->access->canViewFollowUp($user, $model);
    }
}
