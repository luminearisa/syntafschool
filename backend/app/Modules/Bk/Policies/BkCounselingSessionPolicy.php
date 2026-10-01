<?php

namespace App\Modules\Bk\Policies;

use App\Models\User;
use App\Modules\Bk\Application\Services\BkAccessService;
use App\Modules\Bk\Models\BkCounselingSession;

/**
 * Kebijakan sesi konseling — catatan konseling tidak pernah dapat dibuka guru/siswa/wali.
 */
class BkCounselingSessionPolicy
{
    public function __construct(private readonly BkAccessService $access)
    {
    }

    public function view(User $user, BkCounselingSession $model): bool
    {
        return $model->case !== null && $this->access->canViewSession($user, $model);
    }

    public function record(User $user, BkCounselingSession $model): bool
    {
        return $model->case !== null && $this->access->canViewCounselingContent($user, $model->case);
    }

    public function amendNote(User $user, BkCounselingSession $model): bool
    {
        return $model->case !== null && $this->access->canViewCounselingContent($user, $model->case);
    }
}
