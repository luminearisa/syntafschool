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

    /**
     * Boleh melihat kasus.
     *
     * Penolakan DICATAT pada jejak audit — tindakan yang ditolak pun harus
     * terlihat saat diperiksa. Tanpa itu, permintaan yang ditolak policy tidak
     * meninggalkan jejak apa pun, padahal justru itulah yang dicari pemeriksa.
     */
    public function view(User $user, BkCase $model): bool
    {
        if ($this->access->canViewCase($user, $model)) {
            return true;
        }

        $level = $model->confidentiality_level ?? \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::RESTRICTED;

        app(\App\Modules\Bk\Application\Services\BkAuditService::class)
            ->logAccess($user, $level->isCounselorOnly() ? 'VIEW_CONFIDENTIAL' : 'VIEW', $model, false, 'Ditolak policy kasus.');

        return false;
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
