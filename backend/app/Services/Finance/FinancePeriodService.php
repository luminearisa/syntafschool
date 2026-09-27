<?php

namespace App\Services\Finance;

use App\Models\FinancePeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penutupan periode fiskal (§48, §49).
 *
 * Setelah periode `closed`, ledger engine menolak memposting transaksi ke
 * dalamnya dan transaksi lama tidak boleh diedit — koreksi harus lewat
 * adjustment/reversal di periode terbuka. Karena itu menutup periode adalah
 * tindakan satu arah yang disengaja; membuka kembali (reopen) dijaga permission
 * `finance.closing.manage` dan dicatat audit.
 */
class FinancePeriodService
{
    /** Tutup periode: tandai closed + siapa/kapan. Idempoten-safe (sudah closed = error). */
    public function close(FinancePeriod $period, ?User $actor): FinancePeriod
    {
        return DB::transaction(function () use ($period, $actor): FinancePeriod {
            $fresh = $this->lock($period);

            if (! FinanceStatusMachine::can($fresh, FinancePeriod::STATUS_CLOSED)) {
                throw ValidationException::withMessages([
                    'status' => "Periode {$fresh->code} sudah ditutup sebelumnya.",
                ]);
            }

            $fresh->status = FinancePeriod::STATUS_CLOSED;
            $fresh->closed_by = $actor?->id;
            $fresh->closed_at = Carbon::now();
            $fresh->save();

            return $fresh;
        });
    }

    /** Buka kembali periode yang keliru ditutup (§49 koreksi terkontrol). */
    public function reopen(FinancePeriod $period, ?User $actor): FinancePeriod
    {
        return DB::transaction(function () use ($period): FinancePeriod {
            $fresh = $this->lock($period);

            if (! FinanceStatusMachine::can($fresh, FinancePeriod::STATUS_OPEN)) {
                throw ValidationException::withMessages([
                    'status' => "Periode {$fresh->code} memang masih terbuka.",
                ]);
            }

            $fresh->status = FinancePeriod::STATUS_OPEN;
            $fresh->closed_by = null;
            $fresh->closed_at = null;
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * Buat periode bulanan untuk satu tahun fiskal bila belum ada (idempoten).
     * Dipakai saat setup tahun ajaran baru supaya ledger punya wadah posting.
     *
     * @return list<FinancePeriod>
     */
    public function ensureMonthlyPeriods(int $fiscalYear, int $startMonth = 7): array
    {
        $startMonth = max(1, min(12, $startMonth));
        $start = Carbon::create($fiscalYear, $startMonth, 1)->startOfMonth();

        return DB::transaction(function () use ($fiscalYear, $start): array {
            $created = [];

            for ($i = 0; $i < 12; $i++) {
                $from = $start->copy()->addMonthsNoOverflow($i);
                $to = $from->copy()->endOfMonth();

                $created[] = FinancePeriod::query()->firstOrCreate(
                    ['code' => sprintf('P-%d-%02d', $from->year, $from->month)],
                    [
                        'name' => $from->translatedFormat('F Y'),
                        'type' => FinancePeriod::TYPE_MONTHLY,
                        'fiscal_year' => $fiscalYear,
                        'start_date' => $from->toDateString(),
                        'end_date' => $to->toDateString(),
                        'status' => FinancePeriod::STATUS_OPEN,
                    ],
                );
            }

            return $created;
        });
    }

    /** Periode aktif saat ini (yang memuat hari ini), untuk default UI. */
    public function currentPeriod(): ?FinancePeriod
    {
        return FinancePeriod::query()->open()->whereDate('start_date', '<=', Carbon::today())
            ->whereDate('end_date', '>=', Carbon::today())
            ->orderBy('id')
            ->first();
    }

    /**
     * Ringkasan status periode untuk dashboard closing: berapa yang open/closed
     * per tahun fiskal.
     *
     * @return array<string, mixed>
     */
    public function closingSummary(?int $fiscalYear = null): array
    {
        $query = FinancePeriod::query()
            ->when($fiscalYear !== null, fn (Builder $q) => $q->where('fiscal_year', $fiscalYear));

        return [
            'fiscal_year' => $fiscalYear,
            'total' => (clone $query)->count(),
            'open' => (clone $query)->where('status', FinancePeriod::STATUS_OPEN)->count(),
            'closed' => (clone $query)->where('status', FinancePeriod::STATUS_CLOSED)->count(),
            'current' => $this->currentPeriod()?->code,
        ];
    }

    protected function lock(FinancePeriod $period): FinancePeriod
    {
        return FinancePeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
    }
}
