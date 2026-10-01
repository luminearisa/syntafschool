<?php

namespace App\Modules\Bk\Policies;

use App\Models\User;
use App\Modules\Bk\Application\Services\BkAccessService;
use App\Modules\Bk\Models\BkCaseConference;

/**
 * Politik konferensi kasus.
 *
 * Politik ini menjaga pintu masuk: siapa yang boleh menyelenggarakan, mengubah,
 * dan melihat konferensi. Isi di dalamnya tetap disaring layanan, karena satu
 * konferensi dapat memuat pihak dengan kewenangan berbeda.
 */
class BkCaseConferencePolicy
{
    public function __construct(private readonly BkAccessService $access)
    {
    }

    public function viewAny(User $user): bool
    {
        return $user->can('bk.conference.view') || $this->access->isBkStaff($user);
    }

    public function view(User $user, BkCaseConference $conference): bool
    {
        return app(\App\Modules\Bk\Application\Services\BkCaseConferenceService::class)->canView($user, $conference);
    }

    public function create(User $user): bool
    {
        return $user->can('bk.conference.manage') || $this->access->isBkStaff($user);
    }

    public function update(User $user, BkCaseConference $conference): bool
    {
        if (! ($user->can('bk.conference.manage') || $this->access->isBkStaff($user))) {
            return false;
        }

        // Fasilitator atau tim BK; peserta biasa tidak dapat mengubah keputusan rapat.
        return $conference->facilitator_id === null
            || (int) $conference->facilitator_id === $user->id
            || $this->access->isBkStaff($user);
    }

    public function delete(User $user, BkCaseConference $conference): bool
    {
        return $user->can('bk.conference.manage') && $this->access->isBkAdmin($user);
    }
}
