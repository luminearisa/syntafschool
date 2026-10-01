<?php

namespace App\Modules\Bk\Policies;

use App\Models\User;
use App\Modules\Bk\Application\Services\BkAccessService;
use App\Modules\Bk\Models\BkCase;

/**
 * Kebijakan kasus BK — memakai satu sumber kebenaran (`BkAccessService`).
 *
 * Policy tidak menyalin logika: ia hanya menjembatani Gate Laravel ke keputusan
 * cakupan/kerahasiaan yang sudah diuji di layanan.
 */
class BkCasePolicy
{
    public function __construct(private readonly BkAccessService $access)
    {
    }

    public function view(User $user, BkCase $model): bool
    {
        return $this->access->canViewCase($user, $model);
    }

    public function viewConfidential(User $user, BkCase $model): bool
    {
        return $this->access->canViewCounselingContent($user, $model);
    }

    public function update(User $user, BkCase $model): bool
    {
        return $this->access->isBkStaff($user) && $this->access->canViewCase($user, $model);
    }

    public function assign(User $user, BkCase $model): bool
    {
        return $this->access->canMonitorWorkload($user);
    }

    public function escalate(User $user, BkCase $model): bool
    {
        return $this->access->canEscalate($user, $model);
    }

    public function close(User $user, BkCase $model): bool
    {
        return $this->access->canCloseCase($user, $model);
    }

    public function reopen(User $user, BkCase $model): bool
    {
        return $this->access->canReopenCase($user, $model);
    }

    public function share(User $user, BkCase $model): bool
    {
        return $this->access->isBkStaff($user) && $this->access->canViewCase($user, $model);
    }
}
