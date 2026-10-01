<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\LoanStatus;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetLoan;
use App\Modules\Asset\Models\AssetReservation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reservasi aset yang dipinjamkan (proyektor, kamera, alat).
 *
 * Reservasi ada supaya "siapa lebih dulu" tidak ditentukan oleh siapa yang
 * menekan tombol lebih cepat pada hari pemakaian: slot ditahan sejak disetujui,
 * dan penahanan itu diperiksa saat serah terima.
 */
class AssetReservationService
{
    public function hold(User $actor, Asset $asset, CarbonInterface $start, CarbonInterface $end, array $data = []): AssetReservation
    {
        if ($end->lessThanOrEqualTo($start)) {
            throw new AssetValidationException(['end_at' => ['Waktu berakhir harus setelah waktu mulai.']]);
        }

        return DB::transaction(function () use ($actor, $asset, $start, $end, $data): AssetReservation {
            $conflict = $this->conflictFor($asset, $start, $end);

            if ($conflict !== null) {
                throw new AssetDomainException(
                    'Aset sudah tertahan pada rentang itu: '.$conflict,
                    409,
                );
            }

            return AssetReservation::query()->create([
                'asset_id' => $asset->id,
                'reserved_by' => $data['reserved_by'] ?? $actor->id,
                'purpose' => $data['purpose'] ?? null,
                'start_at' => $start,
                'end_at' => $end,
                'status' => 'HELD',
                'expires_at' => $data['expires_at'] ?? $start->copy()->subHours(2),
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    public function convertToLoan(AssetReservation $reservation, AssetLoan $loan): AssetReservation
    {
        $reservation->forceFill(['status' => 'CONVERTED', 'loan_id' => $loan->id])->save();

        return $reservation;
    }

    public function release(AssetReservation $reservation, string $status = 'CANCELLED'): AssetReservation
    {
        $reservation->forceFill(['status' => $status])->save();

        return $reservation;
    }

    /** Reservasi kedaluwarsa dibersihkan oleh perintah terjadwal. */
    public function expireStale(?CarbonInterface $now = null): int
    {
        $now ??= now();

        return AssetReservation::query()
            ->whereIn('status', ['HELD', 'CONFIRMED'])
            ->where('expires_at', '<', $now)
            ->update(['status' => 'EXPIRED']);
    }

    /** Apakah aset bebas pada rentang waktu tertentu — beserta alasannya. */
    public function availability(Asset $asset, CarbonInterface $start, CarbonInterface $end): array
    {
        $reasons = [];

        $status = $asset->status instanceof AssetStatus ? $asset->status->value : (string) $asset->status;

        if (! $asset->status instanceof AssetStatus ? true : ! $asset->status->isLoanable()) {
            $reasons[] = 'Status aset: '.$status.'.';
        }

        if ($asset->active_loan_id !== null) {
            $reasons[] = 'Sedang dipinjam.';
        }

        $conflict = $this->conflictFor($asset, $start, $end);

        if ($conflict !== null) {
            $reasons[] = $conflict;
        }

        return ['available' => $reasons === [], 'reasons' => $reasons];
    }

    protected function conflictFor(Asset $asset, CarbonInterface $start, CarbonInterface $end): ?string
    {
        $reservation = AssetReservation::query()
            ->where('asset_id', $asset->id)
            ->whereIn('status', ['HELD', 'CONFIRMED'])
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->first();

        if ($reservation !== null) {
            return 'reservasi '.$reservation->id.' oleh pengguna '.$reservation->reserved_by;
        }

        $loan = AssetLoan::query()
            ->where('status', '!=', LoanStatus::RETURNED->value)
            ->whereIn('status', [LoanStatus::ACTIVE->value, LoanStatus::OVERDUE->value, LoanStatus::HANDED_OVER->value])
            ->whereHas('items', fn ($query) => $query->where('asset_id', $asset->id))
            ->where('start_at', '<', $end)
            ->where('due_at', '>', $start)
            ->first();

        return $loan !== null ? 'pinjaman berjalan '.$loan->loan_number : null;
    }
}
