<?php

namespace App\Modules\Bk\Policies;

use App\Models\User;
use App\Modules\Bk\Application\Services\BkAccessService;
use App\Modules\Bk\Models\BkCaseNote;

/**
 * Kebijakan catatan kasus — catatan sensitif hanya untuk pemegang akses konseling.
 */
class BkCaseNotePolicy
{
    public function __construct(private readonly BkAccessService $access)
    {
    }

    public function view(User $user, BkCaseNote $model): bool
    {
        return $this->access->canViewNote($user, $model);
    }

    public function create(User $user, BkCaseNote $model): bool
    {
        return $model->case !== null && $this->access->canViewCase($user, $model->case);
    }

    public function amend(User $user, BkCaseNote $model): bool
    {
        return $this->access->canViewNote($user, $model);
    }
}
