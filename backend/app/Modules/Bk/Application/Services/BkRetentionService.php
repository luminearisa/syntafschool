<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Domain\Enums\RetentionActionType;
use App\Modules\Bk\Domain\Enums\RetentionEntityType;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseDocument;
use App\Modules\Bk\Models\BkCaseNote;
use App\Modules\Bk\Models\BkConsent;
use App\Modules\Bk\Models\BkRetentionAction;
use App\Modules\Bk\Models\BkRetentionPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Retensi & pemusnahan data.
 *
 * Masa simpan adalah **data**, bukan konstanta di kode: sekolah dengan kewajiban
 * berbeda menyesuaikannya tanpa mengubah program, dan setiap perubahan menjadi
 * versi baru sehingga dapat ditelusuri kapan kebijakan berubah.
 *
 * Perjalanan sebuah rekaman: Active -> Archived -> Retention Expired -> Approved
 * Disposal. Tidak ada langkah yang melompat: pemusnahan selalu didahului
 * permintaan dan persetujuan, dan setiap langkahnya meninggalkan baris tersendiri
 * pada `bk_retention_actions` — sehingga pemusnahan dapat diaudit, bukan sekadar
 * diyakini.
 */
class BkRetentionService
{
    public function __construct(
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginatePolicies(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return BkRetentionPolicy::query()
            ->when($filters['entity_type'] ?? null, fn ($q, $type) => $q->where('entity_type', $type))
            ->when(filter_var($filters['active'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->active())
            ->orderBy('entity_type')
            ->orderByDesc('version')
            ->paginate($perPage);
    }

    /**
     * Menetapkan kebijakan baru untuk sebuah jenis rekaman.
     *
     * Selalu menjadi versi baru: kebijakan lama tetap ada sebagai catatan sejarah,
     * karena mengubah masa simpan menghapus bukti kebijakan yang berlaku saat data
     * itu dibuat.
     *
     * @param array<string, mixed> $data
     */
    public function setPolicy(User $actor, RetentionEntityType $entity, array $data): BkRetentionPolicy
    {
        $retentionDays = (int) ($data['retention_days'] ?? $entity->fallbackDays());

        if ($retentionDays < 1) {
            throw new BkValidationException(['retention_days' => ['Masa simpan minimal satu hari.']]);
        }

        return DB::transaction(function () use ($actor, $entity, $data, $retentionDays): BkRetentionPolicy {
            $version = (int) BkRetentionPolicy::query()->where('entity_type', $entity->value)->max('version') + 1;

            $policy = BkRetentionPolicy::query()->create([
                'entity_type' => $entity,
                'version' => $version,
                'retention_days' => $retentionDays,
                'archive_after_days' => $data['archive_after_days'] ?? null,
                'requires_approval_for_disposal' => (bool) ($data['requires_approval_for_disposal'] ?? true),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'legal_basis' => $data['legal_basis'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            // Hanya satu kebijakan aktif per jenis rekaman.
            BkRetentionPolicy::query()
                ->where('entity_type', $entity->value)
                ->where('id', '!=', $policy->id)
                ->update(['is_active' => false, 'updated_by' => $actor->id]);

            $this->audit->record(AuditAction::UPDATE, $policy, $actor, [
                'entity_type' => $entity->value,
                'retention_days' => $retentionDays,
                'version' => $version,
            ]);

            return $policy;
        });
    }

    /** Kebijakan aktif untuk sebuah jenis rekaman. */
    public function policyFor(RetentionEntityType $entity): ?BkRetentionPolicy
    {
        return BkRetentionPolicy::query()
            ->where('entity_type', $entity->value)
            ->active()
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Mengarsipkan rekaman yang sudah melewati `archive_after_days`.
     *
     * Pengarsipan tidak menyembunyikan data dari yang berhak: rekaman tetap dapat
     * dibuka, hanya ditandai sebagai arsip agar tidak lagi muncul pada daftar kerja
     * sehari-hari.
     *
     * @return array{archived: int, entities: array<string, int>}
     */
    public function archiveDue(User $actor, ?int $limit = null): array
    {
        $limit ??= 500;
        $summary = ['archived' => 0, 'entities' => []];

        foreach (RetentionEntityType::cases() as $entity) {
            $policy = $this->policyFor($entity);

            if ($policy === null || $policy->archive_after_days === null) {
                continue;
            }

            $cutoff = now()->subDays($policy->archive_after_days);
            $archived = 0;

            foreach ($this->dueRecords($entity, $cutoff, $limit) as $record) {
                $alreadyArchived = BkRetentionAction::query()
                    ->forEntity($entity, (int) $record->getKey())
                    ->where('action', RetentionActionType::ARCHIVED->value)
                    ->exists();

                if ($alreadyArchived) {
                    continue;
                }

                $this->record($actor, $policy, $entity, (int) $record->getKey(), RetentionActionType::ARCHIVED, [
                    'archived_after_days' => $policy->archive_after_days,
                ]);

                $archived++;
            }

            if ($archived > 0) {
                $summary['entities'][$entity->value] = $archived;
                $summary['archived'] += $archived;
            }
        }

        return $summary;
    }

    /**
     * Mengajukan pemusnahan rekaman yang sudah melewati masa simpan.
     *
     * Permintaan tidak menghapus apa pun — ia menunggu persetujuan orang. Bila
     * kebijakan tidak menuntut persetujuan, permintaan langsung dieksekusi, dan
     * jejaknya tetap tercatat dua kali (diajukan, lalu dimusnahkan).
     *
     * @return array{requested: int, disposed: int}
     */
    public function requestDisposal(User $actor, ?RetentionEntityType $entity = null): array
    {
        $summary = ['requested' => 0, 'disposed' => 0];
        $entities = $entity === null ? RetentionEntityType::cases() : [$entity];

        foreach ($entities as $type) {
            $policy = $this->policyFor($type);

            if ($policy === null) {
                continue;
            }

            $cutoff = now()->subDays($policy->retention_days);

            foreach ($this->dueRecords($type, $cutoff, 500) as $record) {
                $pending = BkRetentionAction::query()
                    ->forEntity($type, (int) $record->getKey())
                    ->where('action', RetentionActionType::DISPOSAL_REQUESTED->value)
                    ->where('status', 'PENDING')
                    ->exists();

                if ($pending) {
                    continue;
                }

                $this->record($actor, $policy, $type, (int) $record->getKey(), RetentionActionType::DISPOSAL_REQUESTED, [
                    'retention_days' => $policy->retention_days,
                    'requires_approval' => $policy->requires_approval_for_disposal,
                ]);

                $summary['requested']++;
            }

            if ($policy->requires_approval_for_disposal) {
                $this->notifications->notifyTeam(
                    NotificationEvent::RETENTION_DISPOSAL_REQUESTED,
                    "Permintaan pemusnahan data {$type->label()} menunggu persetujuan.",
                    ['entity_type' => $type->value, 'policy_version' => $policy->version],
                );
            }
        }

        return $summary;
    }

    /**
     * Menyetujui pemusnahan. Persetujuan tidak boleh dilakukan orang yang sama
     * dengan pengaju bila kebijakan menuntut pemisahan tugas.
     */
    public function approveDisposal(User $actor, BkRetentionAction $request, bool $approved, ?string $note = null): BkRetentionAction
    {
        if ($request->action !== RetentionActionType::DISPOSAL_REQUESTED || $request->status !== 'PENDING') {
            throw new BkValidationException(['status' => ['Baris ini bukan permintaan pemusnahan yang menunggu keputusan.']]);
        }

        if ($approved && (int) $request->performed_by === $actor->id) {
            throw new BkValidationException([
                'approved_by' => ['Pemusnahan data harus disetujui orang lain, bukan pengaju permintaan itu sendiri.'],
            ]);
        }

        // Permintaan tetap utuh sebagai fakta sejarah; keputusan ditulis sebagai
        // baris baru agar perjalanan retensi terbaca lengkap.
        $request->forceFill([
            'status' => $approved ? 'COMPLETED' : 'REJECTED',
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ])->save();

        $this->record(
            $actor,
            $request->policy,
            $request->entity_type,
            (int) $request->entity_id,
            $approved ? RetentionActionType::DISPOSAL_APPROVED : RetentionActionType::DISPOSAL_REJECTED,
            ['reason' => $note ?? 'Keputusan atas permintaan pemusnahan #'.$request->id, 'request_id' => $request->id],
        );

        $this->audit->record(AuditAction::RETENTION_APPROVE, $request, $actor, [
            'approved' => $approved,
            'entity_type' => $request->entity_type->value,
            'entity_id' => $request->entity_id,
        ]);

        return $request->refresh();
    }

    /**
     * Eksekusi pemusnahan atas permintaan yang sudah disetujui.
     *
     * Yang dihapus adalah **isi** rekaman; jejak bahwa pemusnahan terjadi tetap
     * tersimpan (baris `bk_retention_actions` beserta ringkasannya). Menghapus
     * jejaknya sendiri akan membuat pemusnahan tidak dapat diaudit.
     *
     * @return array{disposed: int, skipped: int}
     */
    public function executeApprovedDisposal(User $actor): array
    {
        $summary = ['disposed' => 0, 'skipped' => 0];

        $requests = BkRetentionAction::query()
            ->where('action', RetentionActionType::DISPOSAL_APPROVED->value)
            ->where('status', 'COMPLETED')
            ->get();

        foreach ($requests as $request) {
            $alreadyDisposed = BkRetentionAction::query()
                ->forEntity($request->entity_type, (int) $request->entity_id)
                ->where('action', RetentionActionType::DISPOSED->value)
                ->exists();

            if ($alreadyDisposed) {
                $summary['skipped']++;

                continue;
            }

            $model = $this->modelFor($request->entity_type)?->newQuery()->find($request->entity_id);

            if ($model === null) {
                $summary['skipped']++;

                continue;
            }

            $snapshot = $this->snapshotFor($model);

            DB::transaction(function () use ($actor, $request, $model, $snapshot, &$summary): void {
                $this->record($actor, $request->policy, $request->entity_type, (int) $request->entity_id, RetentionActionType::DISPOSED, [
                    'snapshot' => $snapshot,
                ]);

                $model->delete();

                $summary['disposed']++;
            });
        }

        return $summary;
    }

    /** Riwayat tindakan retensi untuk satu rekaman. */
    public function history(RetentionEntityType $entity, int $entityId): array
    {
        return BkRetentionAction::query()
            ->forEntity($entity, $entityId)
            ->with(['performer:id,name', 'approver:id,name'])
            ->orderBy('performed_at')
            ->get()
            ->map(fn (BkRetentionAction $action): array => [
                'action' => $action->action->value,
                'status' => $action->status,
                'reason' => $action->reason,
                'performed_at' => $action->performed_at?->toIso8601String(),
                'performed_by' => $action->performer?->name,
                'approved_by' => $action->approver?->name,
            ])
            ->all();
    }

    /** Ringkasan kebijakan & antrean retensi (dipakai layar pengaturan). */
    public function overview(): array
    {
        return [
            'policies' => collect(RetentionEntityType::cases())->map(function (RetentionEntityType $entity): array {
                $policy = $this->policyFor($entity);

                return [
                    'entity_type' => $entity->value,
                    'label' => $entity->label(),
                    'is_sensitive' => $entity->isHighlySensitive(),
                    'has_policy' => $policy !== null,
                    'retention_days' => $policy?->retention_days ?? $entity->fallbackDays(),
                    'source' => $policy === null ? 'fallback' : 'policy',
                    'archive_after_days' => $policy?->archive_after_days,
                    'requires_approval' => $policy?->requires_approval_for_disposal ?? true,
                    'version' => $policy?->version,
                ];
            })->all(),
            'pending_disposal_requests' => BkRetentionAction::query()
                ->where('action', RetentionActionType::DISPOSAL_REQUESTED->value)
                ->where('status', 'PENDING')
                ->count(),
            'disposed_total' => BkRetentionAction::query()->where('action', RetentionActionType::DISPOSED->value)->count(),
            'archived_total' => BkRetentionAction::query()->where('action', RetentionActionType::ARCHIVED->value)->count(),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, Model> */
    protected function dueRecords(RetentionEntityType $entity, \Carbon\CarbonInterface $cutoff, int $limit): \Illuminate\Support\Collection
    {
        $model = $this->modelFor($entity);

        if ($model === null) {
            return collect();
        }

        return $model->newQuery()
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    protected function modelFor(RetentionEntityType $entity): ?Model
    {
        return match ($entity) {
            RetentionEntityType::CASE => new BkCase(),
            RetentionEntityType::NOTE, RetentionEntityType::SESSION => new BkCaseNote(),
            RetentionEntityType::DOCUMENT => new BkCaseDocument(),
            RetentionEntityType::CONSENT => new BkConsent(),
            default => null,
        };
    }

    /** Ringkasan aman sebagai bukti pemusnahan: cukup untuk audit, tanpa isi sensitif. */
    protected function snapshotFor(Model $model): array
    {
        $attributes = $model->getAttributes();

        foreach (['counselor_note', 'body', 'discussion', 'outcome', 'summary', 'decision'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = '[dimusnahkan]';
            }
        }

        return array_intersect_key($attributes, array_flip([
            'id', 'case_number', 'referral_number', 'consent_number', 'student_id', 'status', 'created_at',
        ])) + ['redacted_at' => now()->toIso8601String()];
    }

    /** @param array<string, mixed> $payload */
    protected function record(User $actor, ?BkRetentionPolicy $policy, RetentionEntityType $entity, int $entityId, RetentionActionType $action, array $payload = []): BkRetentionAction
    {
        $record = BkRetentionAction::query()->create([
            'policy_id' => $policy?->id,
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'action' => $action,
            'status' => $action === RetentionActionType::DISPOSAL_REQUESTED ? 'PENDING' : 'COMPLETED',
            'reason' => $payload['reason'] ?? $action->label(),
            'snapshot' => $payload,
            'performed_at' => now(),
            'performed_by' => $actor->id,
        ]);

        $this->audit->record(
            $action->disposesData() ? AuditAction::RETENTION_DISPOSE : AuditAction::RETENTION_ARCHIVE,
            $record,
            $actor,
            ['entity_type' => $entity->value, 'entity_id' => $entityId, 'action' => $action->value],
        );

        return $record;
    }
}
