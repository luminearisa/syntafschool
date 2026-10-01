<?php

namespace App\Modules\Bk\Policies;

use App\Models\User;
use App\Modules\Bk\Application\Services\BkAccessService;
use App\Modules\Bk\Models\BkAssessment;

/**
 * Kebijakan asesmen: responden boleh mengisi miliknya, konselor menelaah.
 */
class BkAssessmentPolicy
{
    public function __construct(private readonly BkAccessService $access)
    {
    }

    public function view(User $user, BkAssessment $model): bool
    {
        return $model->case !== null ? $this->access->canViewCase($user, $model->case) : $this->access->isBkStaff($user);
    }

    public function submit(User $user, BkAssessment $model): bool
    {
        return ((int) $model->respondent_user_id === (int) $user->id) || $this->access->isBkStaff($user);
    }

    public function review(User $user, BkAssessment $model): bool
    {
        return $model->case !== null && $this->access->canViewCounselingContent($user, $model->case);
    }
}
