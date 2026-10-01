<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Domain\Contracts\FinanceProviderInterface;
use App\Modules\Asset\Domain\Contracts\WorkflowProviderInterface;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\DisposalMethod;
use App\Modules\Asset\Domain\Enums\DisposalReason;
use App\Modules\Asset\Domain\Enums\DisposalStatus;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Domain\StateMachines\AssetStateMachine;
use App\Modules\Asset\Domain\StateMachines\DisposalStateMachine;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetDisposal;
use App\Modules\Asset\Models\AssetDisposalRequest;
use App\Modules\Asset\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Penghapusan aset: permintaan → inspeksi → valuasi → persetujuan → pelaksanaan → arsip.
 *
 * Modul ini TIDAK PERNAH menghapus baris aset. Yang terjadi pada akhirnya adalah
 * status DIHAPUSBUKUKAN lalu DIARSIPKAN — dan barisnya tetap dapat dicari untuk
 * keperluan pemeriksaan. Penghapusan juga selalu melewati persetujuan berjenjang;
 * tidak ada jalur pintas dari "rusak" ke "hilang dari daftar".
 */
class AssetDisposalService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetAuditService $audit,
        private readonly AssetNotificationService $notifications,
        private readonly FinanceProviderInterface $finance,
        private readonly WorkflowProviderInterface $workflow,
    ) {}

    public function submit(User $actor, array $data): AssetDisposalRequest
    {
        $asset = Asset::query()->find($data['asset_id'] ?? null);

        if ($asset === null) {
            throw new AssetValidationException(['asset_id' => ['Aset tidak ditemukan.']]);
        }

        if ($asset->active_loan_id !== null) {
            throw new AssetDomainException('Aset sedang dipinjam; selesaikan peminjamannya sebelum diusulkan penghapusan.', 409);
        }

        $status = $asset->status instanceof AssetStatus ? $asset->status->value : (string) $asset->status;

        if (in_array($status, [AssetStatus::DISPOSED->value, AssetStatus::RETIRED->value], true)) {
            throw new AssetDomainException('Aset ini sudah dihapusbukukan.', 409);
        }

        $reason = DisposalReason::from((string) $data['reason']);

        return DB::transaction(function () use ($actor, $data, $asset, $reason): AssetDisposalRequest {
            $request = AssetDisposalRequest::query()->create([
                'request_number' => $this->numbers->next('disposal', ['tahun' => now()->year]),
                'asset_id' => $asset->id,
                'reason' => $reason->value,
                'method' => $data['method'] ?? null,
                'status' => DisposalStatus::SUBMITTED->value,
                'requested_by' => $actor->id,
                'description' => $data['description'] ?? null,
                'condition_at_request' => $asset->condition?->value ?? (string) $asset->condition,
                'book_value_at_request' => (string) ($asset->current_book_value ?? $asset->acquisition_cost),
                'estimated_value' => $data['estimated_value'] ?? null,
                'expected_proceeds' => $data['expected_proceeds'] ?? null,
                'buyer_name' => $data['buyer_name'] ?? null,
                'donation_recipient' => $data['donation_recipient'] ?? null,
                'submitted_at' => now(),
                'inspection_id' => $data['inspection_id'] ?? null,
            ]);

            // Rantai persetujuan dimulai lewat Workflow Engine; bila belum
            // terpasang, permintaan TETAP menunggu (tidak diam-diam disetujui).
            $workflow = $this->workflow->start([
                'subject_type' => $request->getMorphClass(),
                'subject_id' => $request->id,
                'type' => 'ASSET_DISPOSAL',
                'requested_by' => $actor->id,
                'context' => ['value' => (string) $request->book_value_at_request, 'reason' => $reason->value],
            ]);

            $request->forceFill(['approval_reference' => $workflow['reference'] ?? null])->save();

            $this->notifications->notify(
                [],
                ['ASSET_ADMIN', 'FINANCE', 'SARPRAS_MANAGER'],
                'ASSET_DISPOSAL_SUBMITTED',
                'Permintaan penghapusan aset: '.$asset->name,
                sprintf('%s — alasan %s, nilai buku %s.', $request->request_number, $reason->value, $request->book_value_at_request),
                $request,
            );

            $this->audit->record(AuditAction::ASSET_DISPOSED, $request, $actor, [
                'status_after' => $request->status,
                'reason' => 'Pengajuan penghapusan: '.$reason->value,
                'context' => ['book_value' => $request->book_value_at_request],
            ]);

            return $request->refresh();
        });
    }

    /** Inspeksi pra-penghapusan: wajib sebelum penilaian. */
    public function inspect(User $actor, AssetDisposalRequest $request, array $data = []): AssetDisposalRequest
    {
        DisposalStateMachine::assert($this->status($request), DisposalStatus::INSPECTED->value, 'penghapusan');

        $inspection = app(InspectionService::class)->schedule($actor, [
            'asset_id' => $request->asset_id,
            'inspection_type' => 'PRE_DISPOSAL',
            'scheduled_at' => now(),
            'notes' => 'Inspeksi pra-penghapusan '.$request->request_number.'.',
        ]);

        $inspection = app(InspectionService::class)->perform($actor, $inspection, array_map(
            fn (string $code) => ['code' => $code, 'label' => ucfirst(strtolower($code)), 'result' => $data['results'][$code] ?? 'PASS'],
            ['CONDITION', 'FUNCTIONALITY', 'SAFETY', 'COMPLETENESS'],
        ), ['condition' => $data['condition'] ?? null, 'notes' => $data['notes'] ?? null]);

        $request->forceFill([
            'status' => DisposalStatus::INSPECTED->value,
            'inspection_id' => $inspection->id,
        ])->save();

        $this->audit->record(AuditAction::ASSET_DISPOSED, $request, $actor, [
            'status_before' => DisposalStatus::SUBMITTED->value,
            'status_after' => DisposalStatus::INSPECTED->value,
            'reason' => 'Inspeksi pra-penghapusan selesai.',
            'context' => ['inspection_id' => $inspection->id],
        ]);

        return $request->refresh();
    }

    /** Nilai yang diharapkan dari pelepasan (memakai valuasi resmi). */
    public function value(User $actor, AssetDisposalRequest $request, array $data = []): AssetDisposalRequest
    {
        DisposalStateMachine::assert($this->status($request), DisposalStatus::VALUED->value, 'penghapusan');

        $valuation = app(AssetValuationService::class)->revalue(
            $actor,
            $request->asset,
            \App\Modules\Asset\Domain\Enums\ValuationType::FAIR_VALUE,
            (string) ($data['estimated_value'] ?? $request->estimated_value ?? '0.00'),
            'Penilaian pra-penghapusan '.$request->request_number.'.',
            ['document_reference' => $data['document_reference'] ?? null],
        );

        $request->forceFill([
            'status' => DisposalStatus::VALUED->value,
            'valuation_id' => $valuation->id,
            'estimated_value' => $valuation->amount,
            'expected_proceeds' => $data['expected_proceeds'] ?? $request->expected_proceeds,
        ])->save();

        $this->audit->record(AuditAction::ASSET_DISPOSED, $request, $actor, [
            'status_before' => DisposalStatus::INSPECTED->value,
            'status_after' => DisposalStatus::VALUED->value,
            'reason' => 'Penilaian pra-penghapusan.',
            'context' => ['estimated_value' => (string) $valuation->amount],
        ]);

        return $request->refresh();
    }

    public function approve(User $actor, AssetDisposalRequest $request, ?string $notes = null): AssetDisposalRequest
    {
        DisposalStateMachine::assert($this->status($request), DisposalStatus::APPROVED->value, 'penghapusan');

        $request->forceFill([
            'status' => DisposalStatus::APPROVED->value,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'notes' => $notes ?? $request->notes,
        ])->save();

        $this->workflow->decide([
            'subject_type' => $request->getMorphClass(),
            'subject_id' => $request->id,
            'decision' => 'APPROVED',
            'actor_id' => $actor->id,
        ]);

        $this->audit->record(AuditAction::ASSET_DISPOSED, $request, $actor, [
            'status_before' => DisposalStatus::VALUED->value,
            'status_after' => DisposalStatus::APPROVED->value,
            'reason' => $notes ?? 'Penghapusan disetujui.',
        ]);

        return $request->refresh();
    }

    public function reject(User $actor, AssetDisposalRequest $request, string $reason): AssetDisposalRequest
    {
        DisposalStateMachine::assert($this->status($request), DisposalStatus::REJECTED->value, 'penghapusan');

        $request->forceFill([
            'status' => DisposalStatus::REJECTED->value,
            'rejection_reason' => $reason,
        ])->save();

        $this->audit->record(AuditAction::ASSET_DISPOSED, $request, $actor, [
            'status_after' => DisposalStatus::REJECTED->value,
            'reason' => $reason,
        ]);

        return $request->refresh();
    }

    /**
     * Laksanakan penghapusan: aset dihapusbukukan, akibat keuangannya dicatat.
     */
    public function execute(User $actor, AssetDisposalRequest $request, array $data = []): AssetDisposal
    {
        DisposalStateMachine::assert($this->status($request), DisposalStatus::EXECUTED->value, 'penghapusan');

        if ($request->approved_by === null) {
            throw new AssetDomainException('Penghapusan hanya dapat dilaksanakan setelah disetujui.', 409);
        }

        // `$request->method` bertipe enum (di-cast model); input pemanggil berupa
        // string. Keduanya diterima, dan itu disengaja — pemanggil API tidak perlu
        // tahu tipe internal, sementara kode tetap tidak boleh merangkai enum
        // menjadi string (galat yang pernah terjadi di sini).
        $rawMethod = $data['method']
            ?? ($request->method instanceof DisposalMethod ? $request->method->value : $request->method)
            ?? DisposalMethod::WRITE_OFF->value;

        $method = DisposalMethod::from((string) $rawMethod);

        return DB::transaction(function () use ($actor, $request, $data, $method): AssetDisposal {
            $asset = $request->asset;
            $proceeds = (string) ($data['proceeds'] ?? $request->expected_proceeds ?? '0.00');

            $disposal = AssetDisposal::query()->create([
                'disposal_request_id' => $request->id,
                'asset_id' => $asset->id,
                'method' => $method->value,
                'proceeds' => $proceeds,
                'recipient' => $data['recipient'] ?? $request->buyer_name ?? $request->donation_recipient,
                'executed_by' => $actor->id,
                'executed_at' => now(),
                'document_reference' => $data['document_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Keuangan: laba/rugi pelepasan dihitung dan dicatat di sana.
            $finance = $this->finance->dispose([
                'asset_id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'method' => $method->value,
                'proceeds' => $proceeds,
                'book_value' => (string) ($asset->current_book_value ?? $asset->acquisition_cost),
                'reason' => $request->reason instanceof \App\Modules\Asset\Domain\Enums\DisposalReason
                    ? $request->reason->value
                    : (string) $request->reason,
                'effective_date' => now()->toDateString(),
                'actor_id' => $actor->id,
            ]);

            $disposal->forceFill([
                'finance_reference' => $finance['reference'] ?? null,
            ])->save();

            $request->forceFill([
                'status' => DisposalStatus::EXECUTED->value,
                'method' => $method->value,
                'executed_at' => now(),
                'executed_by' => $actor->id,
                'finance_status' => $finance['status'] ?? 'PENDING',
                'finance_reference' => $finance['reference'] ?? null,
            ])->save();

            // Status aset: DIHAPUSBUKUKAN, bukan dihapus dari basis data.
            $current = $asset->status instanceof AssetStatus ? $asset->status->value : (string) $asset->status;

            if (AssetStateMachine::can($current, AssetStatus::DISPOSED->value)) {
                app(AssetRegisterService::class)->changeStatus($actor, $asset, AssetStatus::DISPOSED, 'Penghapusan '.$request->request_number.'.');
            }

            $this->audit->record(AuditAction::ASSET_DISPOSED, $asset, $actor, [
                'status_before' => $current,
                'status_after' => AssetStatus::DISPOSED->value,
                'reason' => 'Penghapusan dilaksanakan ('.$method->value.').',
                'context' => ['proceeds' => $proceeds, 'finance_status' => $finance['status'] ?? 'PENDING'],
            ]);

            return $disposal->refresh();
        });
    }

    /** Arsipkan aset yang sudah dihapusbukukan; barisnya tetap dapat dicari. */
    public function retire(User $actor, AssetDisposalRequest $request, ?string $reason = null): AssetDisposalRequest
    {
        DisposalStateMachine::assert($this->status($request), DisposalStatus::RETIRED->value, 'penghapusan');

        $asset = $request->asset;

        DB::transaction(function () use ($actor, $request, $asset, $reason): void {
            if ($asset !== null) {
                app(AssetRegisterService::class)->changeStatus($actor, $asset, AssetStatus::RETIRED, $reason ?? 'Arsip setelah penghapusan.');

                $asset->forceFill(['is_archived' => true, 'archived_at' => now()])->save();
            }

            $request->forceFill(['status' => DisposalStatus::RETIRED->value, 'retired_at' => now()])->save();

            $this->audit->record(AuditAction::ASSET_RETIRED, $asset ?? $request, $actor, [
                'status_after' => AssetStatus::RETIRED->value,
                'reason' => $reason ?? 'Aset diarsipkan.',
            ]);
        });

        return $request->refresh();
    }

    protected function status(AssetDisposalRequest $request): string
    {
        return $request->status instanceof DisposalStatus ? $request->status->value : (string) $request->status;
    }
}
