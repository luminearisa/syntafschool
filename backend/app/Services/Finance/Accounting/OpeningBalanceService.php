<?php

namespace App\Services\Finance\Accounting;

use App\Models\FinanceAccount;
use App\Models\FinanceOpeningBalance;
use App\Models\User;
use App\Services\Finance\FinancialTransactionEngine;
use App\Support\Accounting\CoaHierarchy;
use App\Support\Accounting\SumberModul;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saldo awal per akun.
 *
 * Dua tahap yang sengaja dipisah:
 *   1. **Rancangan** — angka boleh diubah sesuka hati; belum ada jejak di buku besar.
 *   2. **Posting** — angka menjadi jurnal pembuka yang terkunci. Sesudah itu
 *      koreksinya hanya lewat jurnal pembalik, sama seperti transaksi lain.
 *
 * Jurnal pembuka wajib seimbang. Bila total debet ≠ total kredit, layanan ini
 * menolak dan menyebutkan selisihnya beserta langkah yang harus diambil — bukan
 * menyelipkan selisih ke akun "lain-lain" secara diam-diam, karena cara itulah
 * yang membuat neraca awal tidak dapat dipercaya sejak hari pertama.
 */
class OpeningBalanceService
{
    public function __construct(
        private readonly FinancialTransactionEngine $ledger,
    ) {
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, FinanceOpeningBalance> */
    public function all()
    {
        return FinanceOpeningBalance::query()
            ->with('account:id,code,name,type')
            ->orderBy('account_id')
            ->get();
    }

    /**
     * Menyimpan rancangan saldo awal.
     *
     * @param  list<array{account_id: int, jenis_saldo: string, saldo: mixed, keterangan?: string|null}>  $entries
     */
    public function save(array $entries, ?User $actor = null): void
    {
        if ($this->sudahDiposting()) {
            throw ValidationException::withMessages([
                'opening' => 'Saldo awal sudah diposting menjadi jurnal pembuka. Untuk mengubahnya, batalkan dulu jurnal tersebut lewat permohonan void.',
            ]);
        }

        DB::transaction(function () use ($entries, $actor): void {
            foreach ($entries as $index => $entry) {
                $accountId = (int) ($entry['account_id'] ?? 0);
                $jenis = (string) ($entry['jenis_saldo'] ?? '');
                $nomor = $index + 1;

                if (! in_array($jenis, ['debet', 'kredit'], true)) {
                    throw ValidationException::withMessages(['opening' => "Baris {$nomor}: jenis saldo harus debet atau kredit."]);
                }

                $account = FinanceAccount::query()->find($accountId);

                if ($account === null) {
                    throw ValidationException::withMessages(['opening' => "Baris {$nomor}: akun tidak ditemukan."]);
                }

                if (! $account->isLeaf()) {
                    throw ValidationException::withMessages([
                        'opening' => "Baris {$nomor}: akun {$account->code} adalah akun induk; saldo awal hanya untuk akun rincian.",
                    ]);
                }

                $saldo = Money::of($entry['saldo'] ?? 0);

                if (Money::isNegative($saldo)) {
                    throw ValidationException::withMessages([
                        'opening' => "Baris {$nomor}: saldo awal tidak boleh negatif. Pakai sisi debet/kredit untuk menyatakan arah saldo.",
                    ]);
                }

                FinanceOpeningBalance::query()->updateOrCreate(
                    ['account_id' => $accountId],
                    [
                        'jenis_saldo' => $jenis,
                        'saldo' => $saldo,
                        'keterangan' => $entry['keterangan'] ?? null,
                        'created_by' => $actor?->id,
                    ],
                );
            }
        });
    }

    public function remove(FinanceOpeningBalance $balance): void
    {
        if ($balance->posted) {
            throw ValidationException::withMessages([
                'opening' => 'Saldo awal yang sudah diposting tidak dapat dihapus; batalkan jurnal pembukanya terlebih dahulu.',
            ]);
        }

        $balance->delete();
    }

    /**
     * Memposting seluruh rancangan menjadi satu jurnal pembuka.
     *
     * @return \App\Models\FinancialTransaction|null
     */
    public function post(User $actor, ?string $tanggal = null): mixed
    {
        if ($this->sudahDiposting()) {
            throw ValidationException::withMessages(['opening' => 'Saldo awal sudah pernah diposting.']);
        }

        $balances = FinanceOpeningBalance::query()->with('account:id,code,name')->get();

        if ($balances->isEmpty()) {
            throw ValidationException::withMessages(['opening' => 'Belum ada saldo awal yang diisi.']);
        }

        $lines = [];
        foreach ($balances as $balance) {
            $lines[] = [
                'account_id' => $balance->account_id,
                'debit' => $balance->jenis_saldo === 'debet' ? $balance->saldo : 0,
                'credit' => $balance->jenis_saldo === 'kredit' ? $balance->saldo : 0,
                'description' => 'Saldo awal '.$balance->account?->code,
            ];
        }

        $debet = Money::sum(array_column($lines, 'debit'));
        $kredit = Money::sum(array_column($lines, 'credit'));

        if (! Money::eq($debet, $kredit)) {
            throw ValidationException::withMessages([
                'opening' => 'Saldo awal belum seimbang: total debet '.Money::format($debet).' ≠ total kredit '.Money::format($kredit)
                    .' (selisih '.Money::format(Money::sub($debet, $kredit)).'). Seimbangkan dulu — misalnya dengan menambah saldo awal pada akun ekuitas/modal — sebelum diposting.',
            ]);
        }

        return DB::transaction(function () use ($balances, $actor, $tanggal, $lines) {
            $transaction = $this->ledger->post(
                type: \App\Models\FinancialTransaction::TYPE_OPENING,
                description: 'Saldo awal periode',
                lines: $lines,
                context: [
                    'date' => $tanggal ?? now()->toDateString(),
                    'sumber_modul' => SumberModul::SALDO_AWAL,
                    'created_by' => $actor->id,
                    'metadata' => ['accounts' => $balances->count()],
                ],
            );

            foreach ($balances as $balance) {
                $balance->forceFill([
                    'posted' => true,
                    'posted_at' => now(),
                    'posted_by' => $actor->id,
                    'transaction_id' => $transaction->id,
                ])->save();
            }

            return $transaction;
        });
    }

    /** Ringkasan untuk layar saldo awal: seimbang atau belum, dan selisihnya. */
    public function summary(): array
    {
        $balances = $this->all();

        $debet = Money::sum($balances->map(fn (FinanceOpeningBalance $b): string => $b->jenis_saldo === 'debet' ? (string) $b->saldo : '0'));
        $kredit = Money::sum($balances->map(fn (FinanceOpeningBalance $b): string => $b->jenis_saldo === 'kredit' ? (string) $b->saldo : '0'));

        return [
            'total_debit' => $debet,
            'total_credit' => $kredit,
            'difference' => Money::sub($debet, $kredit),
            'balanced' => Money::eq($debet, $kredit),
            'posted' => $this->sudahDiposting(),
            'accounts' => $balances->count(),
            'by_root' => $balances
                ->groupBy(fn (FinanceOpeningBalance $b): string => CoaHierarchy::dariAkun($b->account) ?? '?')
                ->map(fn ($group, string $akar): array => [
                    'root' => $akar,
                    'label' => CoaHierarchy::label($akar),
                    'total_debit' => Money::sum($group->map(fn ($b): string => $b->jenis_saldo === 'debet' ? (string) $b->saldo : '0')),
                    'total_credit' => Money::sum($group->map(fn ($b): string => $b->jenis_saldo === 'kredit' ? (string) $b->saldo : '0')),
                ])
                ->values()
                ->all(),
        ];
    }

    protected function sudahDiposting(): bool
    {
        return FinanceOpeningBalance::query()->posted()->exists();
    }
}
