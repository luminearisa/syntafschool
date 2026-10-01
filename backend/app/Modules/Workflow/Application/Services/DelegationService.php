<?php

namespace App\Modules\Workflow\Application\Services;

use App\Models\User;
use App\Modules\Workflow\Domain\Enums\AuditAction;
use App\Modules\Workflow\Domain\Enums\DelegationStatus;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use App\Modules\Workflow\Models\WorkflowDelegation;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Delegasi kewenangan approver (§8, §39).
 *
 * Aturan yang dijaga kelas ini:
 *  - delegator ≠ delegate;
 *  - rentang waktu wajib, dan delegasi hanya berlaku di dalamnya;
 *  - cakupan (`scope`) dapat dipersempit: definisi, modul, node, atau rentang nominal;
 *  - delegasi tidak pernah menghapus identitas delegator (§8) — penerima
 *    delegasi selalu menyimpan `original_approver_id`;
 *  - pencabutan tidak menghapus baris, hanya menandai `REVOKED` + alasan.
 */
class DelegationService
{
    /** @var array<string, WorkflowDelegation|null> */
    protected array $cache = [];

    public function __construct(
        private readonly WorkflowAuditService $audit,
    ) {
    }

    /**
     * Delegasi aktif milik seorang approver untuk konteks request tertentu.
     *
     * Scope dievaluasi terhadap konteks: definisi/modul/node yang sedang berjalan
     * dan nominal request. Delegasi yang cakupannya tidak cocok tidak dipakai.
     */
    public function activeDelegationFor(int $userId, WorkflowContext $context): ?WorkflowDelegation
    {
        $meta = $context->meta();
        $definitionCode = $meta['definition_code'] ?? null;
        $module = $meta['module'] ?? null;
        $nodeKey = $meta['node_key'] ?? null;
        $amount = $context->get('amount');

        $cacheKey = implode('|', [$userId, (string) $definitionCode, (string) $module, (string) $nodeKey, (string) $amount]);

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $candidates = WorkflowDelegation::query()
            ->where('delegator_id', $userId)
            ->whereIn('status', [DelegationStatus::ACTIVE->value, DelegationStatus::PENDING->value])
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now())
            ->orderBy('start_at')
            ->get();

        foreach ($candidates as $delegation) {
            if ($delegation->coversDefinition($definitionCode, $module, $nodeKey, $amount)) {
                return $this->cache[$cacheKey] = $delegation;
            }
        }

        return $this->cache[$cacheKey] = null;
    }

    /**
     * Buat delegasi. Hanya pemilik kewenangan (atau admin workflow) yang boleh.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $delegator, array $data, ?User $actor = null): WorkflowDelegation
    {
        $actor ??= $delegator;
        $delegateId = (int) ($data['delegate_id'] ?? 0);

        if ($delegateId <= 0 || $delegateId === $delegator->id) {
            throw new DomainException('Penerima delegasi harus pengguna lain yang sah.');
        }

        $start = isset($data['start_at']) ? now()->parse($data['start_at']) : now();
        $end = isset($data['end_at']) ? now()->parse($data['end_at']) : now()->addDays(7);

        if ($end->lessThanOrEqualTo($start)) {
            throw new DomainException('Akhir delegasi harus setelah waktu mulai.');
        }

        $overlap = WorkflowDelegation::query()
            ->where('delegator_id', $delegator->id)
            ->where('delegate_id', $delegateId)
            ->whereIn('status', [DelegationStatus::ACTIVE->value, DelegationStatus::PENDING->value])
            ->where(fn ($q) => $q->whereBetween('start_at', [$start, $end])->orWhereBetween('end_at', [$start, $end]))
            ->exists();

        if ($overlap) {
            throw new DomainException('Sudah ada delegasi aktif untuk penerima dan rentang waktu yang bertumpang tindih.');
        }

        $delegation = DB::transaction(function () use ($delegator, $data, $delegateId, $start, $end, $actor) {
            $delegation = WorkflowDelegation::query()->create([
                'delegator_id' => $delegator->id,
                'delegate_id' => $delegateId,
                'scope' => $data['scope'] ?? [],
                'reason' => $data['reason'] ?? null,
                'start_at' => $start,
                'end_at' => $end,
                'status' => $start->isPast() ? DelegationStatus::ACTIVE->value : DelegationStatus::PENDING->value,
                'notify_delegator' => (bool) ($data['notify_delegator'] ?? true),
                'activated_at' => $start->isPast() ? now() : null,
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                AuditAction::DELEGATION_CREATED,
                null,
                $delegation,
                after: [
                    'delegator_id' => $delegator->id,
                    'delegate_id' => $delegateId,
                    'scope' => $delegation->scope,
                    'start_at' => $start->toIso8601String(),
                    'end_at' => $end->toIso8601String(),
                ],
                reason: $delegation->reason,
                actor: $actor,
                extra: ['target_type' => WorkflowDelegation::class, 'target_id' => (string) $delegation->id],
            );

            return $delegation;
        });

        $this->cache = [];

        return $delegation;
    }

    public function revoke(WorkflowDelegation $delegation, ?User $actor = null, ?string $reason = null): WorkflowDelegation
    {
        if ($delegation->status === DelegationStatus::REVOKED) {
            throw new DomainException('Delegasi ini sudah dicabut.');
        }

        $before = ['status' => $delegation->status->value];

        $delegation->forceFill([
            'status' => DelegationStatus::REVOKED,
            'revoked_at' => now(),
            'revoked_by' => $actor?->id,
            'revoke_reason' => $reason,
        ])->save();

        $this->audit->record(
            AuditAction::DELEGATION_REVOKED,
            null,
            $delegation,
            before: $before,
            after: ['status' => DelegationStatus::REVOKED->value],
            reason: $reason,
            actor: $actor,
            extra: ['target_type' => WorkflowDelegation::class, 'target_id' => (string) $delegation->id],
        );

        $this->cache = [];

        return $delegation;
    }

    /**
     * Aktifkan delegasi terjadwal & tutup yang sudah lewat masa berlaku.
     * Dijalankan perintah `workflow:check-sla` (idempoten).
     *
     * @return array{activated: int, expired: int}
     */
    public function sweep(): array
    {
        $activated = WorkflowDelegation::query()
            ->where('status', DelegationStatus::PENDING->value)
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now())
            ->get()
            ->each(function (WorkflowDelegation $delegation): void {
                $delegation->forceFill([
                    'status' => DelegationStatus::ACTIVE,
                    'activated_at' => $delegation->activated_at ?? now(),
                ])->save();
            })
            ->count();

        $expired = WorkflowDelegation::query()
            ->where('end_at', '<', now())
            ->whereIn('status', [DelegationStatus::ACTIVE->value, DelegationStatus::PENDING->value])
            ->get()
            ->each(function (WorkflowDelegation $delegation): void {
                $delegation->forceFill(['status' => DelegationStatus::EXPIRED])->save();
            })
            ->count();

        $this->cache = [];

        return ['activated' => $activated, 'expired' => $expired];
    }

    /** Apakah pengguna ini sedang menerima delegasi aktif dari orang lain. */
    public function delegationsReceivedBy(int $userId): int
    {
        return WorkflowDelegation::query()
            ->where('delegate_id', $userId)
            ->where('status', DelegationStatus::ACTIVE->value)
            ->where('end_at', '>=', now())
            ->count();
    }
}
