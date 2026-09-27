<?php

namespace App\Services\Finance;

use App\Models\FinanceCashierSession;
use App\Models\FinancePayment;
use App\Models\FinanceRefund;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sesi kasir (§22, §23).
 *
 * Satu kasir memegang satu sesi terbuka terhadap satu akun kas. Saat tutup,
 * expected cash dihitung dari uang tunai yang benar-benar tercatat (pembayaran
 * tunai dikonfirmasi dikurangi refund tunai yang selesai) ditambah modal awal,
 * lalu dibandingkan dengan actual cash yang dihitung fisik. Selisih bukan nol
 * WAJIB diberi alasan. Sesi yang sudah ditutup diajukan ke finance untuk review;
 * reviewer tidak boleh kasir yang menutup sesinya sendiri (§3 pemisahan tugas).
 */
class FinanceCashierSessionService
{
    public function __construct(private readonly FinanceNumberingService $numbering) {}

    /**
     * Buka sesi kasir baru. Kasir tidak boleh punya dua sesi terbuka sekaligus.
     *
     * @param  array{cash_account_id: int, opening_cash?: float, notes?: string|null}  $data
     */
    public function open(array $data, User $cashier): FinanceCashierSession
    {
        return DB::transaction(function () use ($data, $cashier): FinanceCashierSession {
            $existing = FinanceCashierSession::query()
                ->where('cashier_id', $cashier->id)
                ->where('status', FinanceCashierSession::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'cash_account_id' => "Kasir masih punya sesi terbuka ({$existing->session_number}). Tutup lebih dulu.",
                ]);
            }

            return FinanceCashierSession::create([
                'session_number' => $this->numbering->next(FinanceNumberingService::CODE_CASHIER_SESSION),
                'cashier_id' => $cashier->id,
                'cash_account_id' => $data['cash_account_id'],
                'opened_at' => Carbon::now(),
                'opening_cash' => round((float) ($data['opening_cash'] ?? 0), 2),
                'status' => FinanceCashierSession::STATUS_OPEN,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * Tutup sesi: hitung expected vs actual, wajib alasan bila selisih.
     *
     * @param  array{actual_cash: float, difference_reason?: string|null, notes?: string|null}  $data
     */
    public function close(FinanceCashierSession $session, array $data, ?User $actor = null): FinanceCashierSession
    {
        return DB::transaction(function () use ($session, $data): FinanceCashierSession {
            $locked = FinanceCashierSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            FinanceStatusMachine::assert($locked, FinanceCashierSession::STATUS_CLOSED, 'session');

            $expected = $this->expectedCash($locked);
            $actual = round((float) ($data['actual_cash'] ?? 0), 2);
            $difference = round($actual - $expected, 2);

            if (abs($difference) > 0.005 && trim((string) ($data['difference_reason'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'difference_reason' => 'Selisih kas wajib dijelaskan (expected Rp '.number_format($expected, 0, ',', '.').', actual Rp '.number_format($actual, 0, ',', '.').').',
                ]);
            }

            $locked->closed_at = Carbon::now();
            $locked->expected_cash = $expected;
            $locked->actual_cash = $actual;
            $locked->difference = $difference;
            $locked->difference_reason = $data['difference_reason'] ?? null;
            $locked->notes = $data['notes'] ?? $locked->notes;
            $locked->status = FinanceCashierSession::STATUS_CLOSED;
            $locked->save();

            return $locked->fresh(['cashier', 'cashAccount']);
        });
    }

    /**
     * Review sesi yang sudah ditutup (§23). Reviewer tidak boleh kasir pemilik
     * sesi — pemisahan pencatat vs pemeriksa (§3).
     */
    public function review(FinanceCashierSession $session, User $reviewer, ?string $notes = null): FinanceCashierSession
    {
        return DB::transaction(function () use ($session, $reviewer, $notes): FinanceCashierSession {
            $locked = FinanceCashierSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            FinanceStatusMachine::assert($locked, FinanceCashierSession::STATUS_REVIEWED, 'session');

            if ((int) $locked->cashier_id === (int) $reviewer->id) {
                throw ValidationException::withMessages(['session' => 'Kasir tidak boleh mereview sesinya sendiri.']);
            }

            $locked->status = FinanceCashierSession::STATUS_REVIEWED;
            $locked->reviewed_by = $reviewer->id;
            $locked->reviewed_at = Carbon::now();

            if ($notes !== null) {
                $locked->notes = $notes;
            }

            $locked->save();

            return $locked->fresh(['cashier', 'cashAccount', 'reviewedBy']);
        });
    }

    /**
     * Perkiraan kas di laci: modal awal + pembayaran tunai dikonfirmasi − refund
     * tunai yang selesai, semuanya milik sesi ini.
     */
    public function expectedCash(FinanceCashierSession $session): float
    {
        $opening = round((float) $session->opening_cash, 2);

        $cashIn = (float) FinancePayment::query()
            ->where('cashier_session_id', $session->id)
            ->where('method', FinancePayment::METHOD_CASH)
            ->whereIn('status', [FinancePayment::STATUS_PAID, FinancePayment::STATUS_PARTIAL, FinancePayment::STATUS_REFUNDED])
            ->sum('amount');

        $paymentIds = FinancePayment::query()
            ->where('cashier_session_id', $session->id)
            ->where('method', FinancePayment::METHOD_CASH)
            ->pluck('id');

        $cashOut = $paymentIds->isEmpty() ? 0.0 : (float) FinanceRefund::query()
            ->whereIn('payment_id', $paymentIds)
            ->where('status', FinanceRefund::STATUS_COMPLETED)
            ->sum('amount');

        return round($opening + $cashIn - $cashOut, 2);
    }
}
