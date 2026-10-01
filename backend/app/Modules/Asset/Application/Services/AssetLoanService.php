<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Domain\Contracts\WorkflowProviderInterface;
use App\Modules\Asset\Domain\Enums\AssetCondition;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\LoanStatus;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Domain\StateMachines\LoanStateMachine;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetLoan;
use App\Modules\Asset\Models\AssetReservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Peminjaman aset: permintaan → persetujuan → reservasi → serah terima → pemulangan.
 *
 * Tiga pengaman yang membuat modul ini dapat dipercaya:
 *  1. **Satu aset, satu pinjaman berjalan** — dijaga kunci unik
 *     `assets.active_loan_id`, bukan hanya pemeriksaan di kode. Dua permintaan
 *     yang berjalan bersamaan tidak dapat sama-sama menang.
 *  2. **Aset yang sedang diperbaiki/dihapus tidak dapat dipinjam** — diperiksa
 *     pada saat serah terima, bukan hanya saat permintaan.
 *  3. **Pemulangan selalu memeriksa kondisi** — dan kondisi buruk melahirkan
 *     laporan kerusakan serta permintaan pemeliharaan, bukan sekadar catatan.
 */
class AssetLoanService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetAuditService $audit,
        private readonly AssetNotificationService $notifications,
        private readonly WorkflowProviderInterface $workflow,
        private readonly AssetReservationService $reservations,
    ) {}

    /** @param list<int> $assetIds */
    public function request(User $actor, array $data, array $assetIds): AssetLoan
    {
        if ($assetIds === []) {
            throw new AssetValidationException(['assets' => ['Pilih setidaknya satu aset untuk dipinjam.']]);
        }

        $start = Carbon::parse($data['start_at'] ?? now());
        $due = Carbon::parse($data['due_at'] ?? now()->addDays((int) config('asset.loan.default_days', 3)));

        if ($due->lessThanOrEqualTo($start)) {
            throw new AssetValidationException(['due_at' => ['Waktu kembali harus setelah waktu mulai.']]);
        }

        $maxDays = (int) config('asset.loan.max_days', 30);

        if ($start->diffInDays($due) > $maxDays) {
            throw new AssetValidationException(['due_at' => ["Peminjaman melebihi batas $maxDays hari."]]);
        }

        return DB::transaction(function () use ($actor, $data, $assetIds, $start, $due): AssetLoan {
            $loan = AssetLoan::query()->create([
                'loan_number' => $this->numbers->next('loan', ['tahun' => now()->year]),
                'status' => LoanStatus::REQUESTED->value,
                'requested_by' => $actor->id,
                'borrower_id' => $data['borrower_id'] ?? $actor->id,
                'borrower_type' => $data['borrower_type'] ?? 'STAFF',
                'unit_id' => $data['unit_id'] ?? null,
                'purpose' => $data['purpose'] ?? 'Peminjaman aset.',
                'activity_reference' => $data['activity_reference'] ?? null,
                'start_at' => $start,
                'due_at' => $due,
                'accessories' => $data['accessories'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach (Asset::query()->whereIn('id', $assetIds)->get() as $asset) {
                $loan->items()->create([
                    'asset_id' => $asset->id,
                    'condition_out' => $this->conditionValue($asset),
                ]);
            }

            $this->workflow->start([
                'subject_type' => $loan->getMorphClass(),
                'subject_id' => $loan->id,
                'type' => 'ASSET_LOAN',
                'requested_by' => $actor->id,
            ]);

            $this->audit->record(AuditAction::ASSET_LOANED, $loan, $actor, [
                'status_after' => $loan->status,
                'reason' => 'Permintaan peminjaman aset.',
                'context' => ['assets' => $assetIds, 'due_at' => $due->toDateTimeString()],
            ]);

            return $loan->refresh();
        });
    }

    public function approve(User $actor, AssetLoan $loan): AssetLoan
    {
        LoanStateMachine::assert($this->statusValue($loan), LoanStatus::APPROVED->value, 'pinjaman');

        $loan->forceFill(['status' => LoanStatus::APPROVED->value, 'approved_by' => $actor->id, 'approved_at' => now()])->save();

        $this->workflow->decide([
            'subject_type' => $loan->getMorphClass(),
            'subject_id' => $loan->id,
            'decision' => 'APPROVED',
            'actor_id' => $actor->id,
        ]);

        $this->audit->record(AuditAction::ASSET_LOANED, $loan, $actor, [
            'status_before' => LoanStatus::REQUESTED->value,
            'status_after' => LoanStatus::APPROVED->value,
            'reason' => 'Peminjaman disetujui.',
        ]);

        return $loan->refresh();
    }

    /** Reservasi slot: menahan aset supaya tidak direbut peminjam lain. */
    public function reserve(User $actor, AssetLoan $loan): AssetLoan
    {
        LoanStateMachine::assert($this->statusValue($loan), LoanStatus::RESERVED->value, 'pinjaman');

        return DB::transaction(function () use ($actor, $loan): AssetLoan {
            foreach ($loan->items()->with('asset')->get() as $item) {
                $asset = $item->asset;

                if ($asset === null) {
                    continue;
                }

                $this->reservations->hold($actor, $asset, $loan->start_at, $loan->due_at, [
                    'purpose' => 'Pinjaman '.$loan->loan_number,
                    'expires_at' => $loan->start_at,
                ]);
            }

            $loan->forceFill(['status' => LoanStatus::RESERVED->value])->save();

            $this->audit->record(AuditAction::ASSET_LOANED, $loan, $actor, [
                'status_before' => LoanStatus::APPROVED->value,
                'status_after' => LoanStatus::RESERVED->value,
                'reason' => 'Aset direservasi untuk peminjaman.',
            ]);

            return $loan->refresh();
        });
    }

    /**
     * Serah terima: di sinilah aset benar-benar berpindah ke peminjam.
     *
     * Kunci basis data `active_loan_id` dipasang di dalam transaksi; bila ada
     * pinjaman lain yang lebih dulu menang, penyimpanan gagal dan permintaan ini
     * ditolak dengan 409 — bukan dua pinjaman atas satu aset.
     */
    public function handover(User $actor, AssetLoan $loan, array $data = []): AssetLoan
    {
        LoanStateMachine::assert($this->statusValue($loan), LoanStatus::HANDED_OVER->value, 'pinjaman');

        return DB::transaction(function () use ($actor, $loan, $data): AssetLoan {
            foreach ($loan->items()->with('asset')->get() as $item) {
                $asset = Asset::query()->whereKey($item->asset_id)->lockForUpdate()->first();

                if ($asset === null) {
                    continue;
                }

                $status = $asset->status instanceof AssetStatus ? $asset->status->value : (string) $asset->status;

                if ($asset->active_loan_id !== null) {
                    throw new AssetDomainException(sprintf('Aset %s sedang dipinjam pada transaksi lain.', $asset->asset_code), 409);
                }

                if (in_array($status, [AssetStatus::IN_MAINTENANCE->value, AssetStatus::DISPOSED->value, AssetStatus::RETIRED->value, AssetStatus::LOST->value], true)) {
                    throw new AssetDomainException(sprintf('Aset %s berstatus %s dan tidak dapat dipinjam.', $asset->asset_code, $status), 409);
                }

                $asset->forceFill([
                    'status' => AssetStatus::ON_LOAN->value,
                    'active_loan_id' => $loan->id,
                    'loan_count' => (int) $asset->loan_count + 1,
                    'version' => $asset->version + 1,
                    'updated_by' => $actor->id,
                ])->save();

                $item->forceFill(['condition_out' => $data['conditions'][$asset->id] ?? $this->conditionValue($asset)])->save();
            }

            $loan->forceFill([
                'status' => LoanStatus::HANDED_OVER->value,
                'handed_over_at' => now(),
                'handed_over_by' => $actor->id,
                'condition_out' => $data['condition_out'] ?? null,
            ])->save();

            AssetReservation::query()->where('loan_id', $loan->id)->update(['status' => 'CONVERTED']);

            $this->audit->record(AuditAction::ASSET_LOANED, $loan, $actor, [
                'status_before' => $this->statusValue($loan) === LoanStatus::HANDED_OVER->value ? LoanStatus::RESERVED->value : $this->statusValue($loan),
                'status_after' => LoanStatus::HANDED_OVER->value,
                'reason' => 'Aset diserahkan kepada peminjam.',
            ]);

            return $loan->refresh();
        });
    }

    /** Aktifkan pinjaman (barang sudah di tangan peminjam). */
    public function activate(User $actor, AssetLoan $loan): AssetLoan
    {
        LoanStateMachine::assert($this->statusValue($loan), LoanStatus::ACTIVE->value, 'pinjaman');

        $loan->forceFill(['status' => LoanStatus::ACTIVE->value])->save();

        return $loan->refresh();
    }

    /**
     * Pemulangan dengan pemeriksaan kondisi.
     *
     * Jika kondisi kembali lebih buruk, laporan kerusakan dan permintaan
     * pemeliharaan dibuat — bukan hanya dicatat di kolom. Dengan begitu barang
     * yang kembali rusak tidak berhenti sebagai catatan.
     */
    public function return(User $actor, AssetLoan $loan, array $data): AssetLoan
    {
        LoanStateMachine::assert($this->statusValue($loan), LoanStatus::RETURNED->value, 'pinjaman');

        return DB::transaction(function () use ($actor, $loan, $data): AssetLoan {
            $condition = AssetCondition::from((string) ($data['condition'] ?? AssetCondition::GOOD->value));
            $damageFound = (bool) ($data['damage_found'] ?? $condition->needsRepair());

            $damageReport = null;
            $maintenanceRequest = null;

            foreach ($loan->items()->with('asset')->get() as $item) {
                $asset = Asset::query()->whereKey($item->asset_id)->lockForUpdate()->first();

                if ($asset === null) {
                    continue;
                }

                $assetCondition = isset($data['conditions'][$asset->id])
                    ? AssetCondition::from((string) $data['conditions'][$asset->id])
                    : $condition;

                $item->forceFill([
                    'condition_in' => $assetCondition->value,
                    'returned' => true,
                    'notes' => $data['notes'] ?? $item->notes,
                ])->save();

                $asset->forceFill([
                    'status' => $assetCondition->needsRepair() ? AssetStatus::DAMAGED->value : AssetStatus::ACTIVE->value,
                    'condition' => $assetCondition->value,
                    'active_loan_id' => null,
                    'version' => $asset->version + 1,
                    'updated_by' => $actor->id,
                ])->save();

                if ($assetCondition->needsRepair() && $damageReport === null) {
                    $damageReport = app(DamageReportService::class)->report($actor, [
                        'asset_id' => $asset->id,
                        'severity' => $assetCondition->rank() >= AssetCondition::UNUSABLE->rank() ? 'TOTAL' : 'MAJOR',
                        'damage_type' => 'KERUSAKAN_PEMINJAMAN',
                        'description' => 'Kerusakan ditemukan saat pemulangan pinjaman '.$loan->loan_number.'.',
                    ]);

                    $maintenanceRequest = app(MaintenanceService::class)->createRequest($actor, [
                        'asset_id' => $asset->id,
                        'priority' => 'HIGH',
                        'title' => 'Perbaikan setelah peminjaman '.$loan->loan_number,
                        'description' => $data['notes'] ?? 'Kerusakan dilaporkan saat pemulangan.',
                        'source' => 'DAMAGE_REPORT',
                        'damage_report_id' => $damageReport->id,
                    ]);
                }
            }

            $loan->forceFill([
                'status' => $damageFound ? LoanStatus::DAMAGED->value : LoanStatus::RETURNED->value,
                'returned_at' => now(),
                'received_by' => $actor->id,
                'condition_in' => $condition->value,
                'damage_report_id' => $damageReport?->id,
            ])->save();

            $loan->returnRecord()->create([
                'returned_at' => now(),
                'received_by' => $actor->id,
                'condition' => $condition->value,
                'accessories' => $data['accessories'] ?? null,
                'damage_found' => $damageFound,
                'damage_report_id' => $damageReport?->id,
                'maintenance_request_id' => $maintenanceRequest?->id,
                'notes' => $data['notes'] ?? null,
            ]);

            AssetReservation::query()->where('loan_id', $loan->id)->update(['status' => 'CONVERTED']);

            $this->audit->record(AuditAction::ASSET_RETURNED, $loan, $actor, [
                'status_before' => LoanStatus::ACTIVE->value,
                'status_after' => $loan->status instanceof LoanStatus ? $loan->status->value : (string) $loan->status,
                'reason' => $damageFound ? 'Dikembalikan dengan kerusakan.' : 'Dikembalikan dalam kondisi baik.',
                'context' => ['condition' => $condition->value, 'damage_report_id' => $damageReport?->id],
            ]);

            if ($damageFound) {
                $this->notifications->notify(
                    array_values(array_filter([$loan->borrower_id, $loan->requested_by])),
                    ['ASSET_OFFICER', 'MAINTENANCE_MANAGER'],
                    'ASSET_RETURNED_DAMAGED',
                    'Aset kembali dalam kondisi rusak: '.$loan->loan_number,
                    'Laporan kerusakan dan permintaan pemeliharaan telah dibuat.',
                    $loan,
                );
            }

            return $loan->refresh();
        });
    }

    public function cancel(User $actor, AssetLoan $loan, string $reason): AssetLoan
    {
        LoanStateMachine::assert($this->statusValue($loan), LoanStatus::CANCELLED->value, 'pinjaman');

        $loan->forceFill(['status' => LoanStatus::CANCELLED->value, 'notes' => $reason])->save();

        $this->audit->record(AuditAction::ASSET_LOANED, $loan, $actor, [
            'status_after' => LoanStatus::CANCELLED->value, 'reason' => $reason,
        ]);

        return $loan->refresh();
    }

    /** Tandai pinjaman terlambat & kirim pengingat (idempoten per hari). */
    public function markOverdue(?Carbon $now = null): array
    {
        $now ??= now();
        $grace = (int) config('asset.reminders.loan_overdue_grace_days', 0);
        $marked = [];

        $loans = AssetLoan::query()
            ->whereIn('status', [LoanStatus::HANDED_OVER->value, LoanStatus::ACTIVE->value])
            ->where('due_at', '<', $now->copy()->subDays($grace))
            ->get();

        foreach ($loans as $loan) {
            $loan->forceFill(['status' => LoanStatus::OVERDUE->value])->save();

            $alreadyNotified = $loan->overdue_notified_at !== null && $loan->overdue_notified_at->isToday();

            if (! $alreadyNotified) {
                $this->notifications->notify(
                    [(int) $loan->borrower_id],
                    ['ASSET_OFFICER'],
                    'ASSET_LOAN_OVERDUE',
                    'Peminjaman terlambat: '.$loan->loan_number,
                    'Jatuh tempo '.$loan->due_at?->toDateTimeString().'. Mohon segera dikembalikan.',
                    $loan,
                );

                $loan->forceFill(['overdue_notified_at' => $now])->save();
            }

            $marked[] = $loan->loan_number;
        }

        return $marked;
    }

    protected function statusValue(AssetLoan $loan): string
    {
        return $loan->status instanceof LoanStatus ? $loan->status->value : (string) $loan->status;
    }

    protected function conditionValue(Asset $asset): string
    {
        return $asset->condition instanceof AssetCondition ? $asset->condition->value : (string) $asset->condition;
    }
}
