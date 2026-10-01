<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Models\WorkflowApproval;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cakupan data workflow (§38, §7, §12).
 *
 * Engine ini menyimpan data lintas modul, sehingga pertanyaan "siapa boleh
 * melihat apa" harus dijawab sekali di satu tempat:
 *
 *  - **requester** melihat requestnya sendiri (dan riwayatnya);
 *  - **approver** melihat request yang pernah/sedang ditugaskan kepadanya;
 *  - **pengamat (observer)** melihat request yang didefinisikan versi;
 *  - **workflow manager / auditor** melihat seluruh request (izin
 *    `workflow.instance.view`), tanpa hak mengubah apa pun bila auditor;
 *  - **penerima delegasi** mewarisi pandangan pemberi delegasi.
 *
 * Penyaringan ini bukan pengganti permission: ia mempersempit apa yang boleh
 * dibaca pengguna yang sudah lolos middleware.
 */
class WorkflowAccessService
{
    public function canView(WorkflowInstance $instance, User $user): bool
    {
        if ($user->can('workflow.instance.view')) {
            return true;
        }

        if ((int) $instance->requester_id === $user->id) {
            return true;
        }

        if ($instance->approvals()->where('approver_id', $user->id)->exists()) {
            return true;
        }

        if (in_array($user->id, $this->observerIds($instance), true)) {
            return true;
        }

        // Penerima delegasi melihat apa yang sedang didelegasikan kepadanya.
        return WorkflowApproval::query()
            ->where('instance_id', $instance->id)
            ->where('original_approver_id', $user->id)
            ->exists();
    }

    public function canViewStep(WorkflowInstance $instance, User $user): bool
    {
        return $this->canView($instance, $user);
    }

    /** Bolehkah membatalkan (§63) sesuai kebijakan definisi. */
    public function canCancel(WorkflowInstance $instance, User $user): bool
    {
        if (! $instance->status->isOpen()) {
            return false;
        }

        $policy = strtoupper((string) ($instance->definition?->settings['cancel_policy']
            ?? $instance->version?->config['cancel_policy']
            ?? 'REQUESTER_OR_MANAGER'));

        if ($policy === 'NONE') {
            return false;
        }

        $isRequester = (int) $instance->requester_id === $user->id;

        if ($policy === 'MANAGER') {
            return $user->can('workflow.request.cancel');
        }

        return ($isRequester && ! $instance->status->isTerminal()) || $user->can('workflow.request.cancel');
    }

    /** Bolehkah merevisi/mengirim ulang request yang dikembalikan. */
    public function canResubmit(WorkflowInstance $instance, User $user): bool
    {
        if (! in_array($instance->status->value, ['DRAFT', 'RETURNED'], true)) {
            return false;
        }

        return (int) $instance->requester_id === $user->id || $user->can('workflow.request.manage');
    }

    /** Bolehkah memutuskan penugasan ini (untuk tombol di UI). */
    public function canDecide(WorkflowApproval $approval, User $user): bool
    {
        if (! $approval->status->isPending()) {
            return false;
        }

        if ((int) $approval->approver_id === $user->id) {
            return true;
        }

        if ($user->can('workflow.approval.override')) {
            return true;
        }

        $instance = $approval->instance ?? $approval->instance()->first();

        if ($instance === null) {
            return false;
        }

        $delegation = app(DelegationService::class)->activeDelegationFor((int) $approval->approver_id, app(WorkflowContextService::class)
            ->forInstance($instance)
            ->withMeta([
                'definition_code' => (string) $instance->definition?->code,
                'module' => (string) $instance->module,
            ]));

        return $delegation !== null && (int) $delegation->delegate_id === $user->id;
    }

    /**
     * Saring kueri instance sesuai cakupan pengguna.
     *
     * @return Builder<WorkflowInstance>
     */
    public function scopeInstances(Builder $query, User $user): Builder
    {
        if ($user->can('workflow.instance.view')) {
            return $query;
        }

        $observing = WorkflowInstance::query()
            ->whereHas('version', function ($q) use ($user): void {
                $q->whereRaw('1 = 0');
            });

        return $query->where(function (Builder $q) use ($user): void {
            $q->where('requester_id', $user->id)
                ->orWhereHas('approvals', fn ($approval) => $approval->where('approver_id', $user->id))
                ->orWhereHas('approvals', fn ($approval) => $approval->where('original_approver_id', $user->id));
        });
    }

    /**
     * Saring kueri penugasan approval sesuai cakupan pengguna.
     *
     * @return Builder<WorkflowApproval>
     */
    public function scopeApprovals(Builder $query, User $user): Builder
    {
        if ($user->can('workflow.instance.view')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user): void {
            $q->where('approver_id', $user->id)
                ->orWhere('original_approver_id', $user->id);
        });
    }

    /** @return list<int> */
    public function observerIds(WorkflowInstance $instance): array
    {
        $observers = (array) ($instance->version?->config['observers'] ?? $instance->definition?->observers ?? []);

        $ids = [];

        foreach ($observers as $observer) {
            if (is_numeric($observer)) {
                $ids[] = (int) $observer;

                continue;
            }

            if (is_array($observer)) {
                // Spesifikasi resolver penuh, mis. {"resolver": "role", "role": "kepala-sekolah"}.
                $context = app(WorkflowContextService::class)->forInstance($instance);

                $ids = array_merge($ids, app(ApproverResolverService::class)->resolveUserIds([$observer], $context));
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
