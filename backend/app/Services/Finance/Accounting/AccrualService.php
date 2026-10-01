<?php

namespace App\Services\Finance\Accounting;

use App\Models\FinanceAccount;
use App\Models\FinanceAccrual;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Finance\FinancialTransactionEngine;
use App\Support\Accounting\CoaHierarchy;
use App\Support\Accounting\SumberModul;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Akrual: mengakui pendapatan/beban di periode yang benar, lalu membalikkannya.
 *
 * Setiap akrual lahir dengan tanggal balik, dan pembalikannya otomatis saat
 * tanggal itu tiba. Alternatifnya — bergantung pada siapa yang ingat membalik —
 * adalah cara paling umum pendapatan tercatat dua kali.
 *
 * Sisi akunnya tidak bebas:
 *   - akrual **pendapatan**  → debet piutang akrual (kelompok 1), kredit pendapatan (kelompok 4);
 *   - akrual **beban**       → debet beban (kelompok 5), kredit utang akrual (kelompok 2).
 * Menukar sisi itu menghasilkan neraca yang tidak masuk akal, jadi ditolak di sini.
 */
class AccrualService
{
    public function __construct(private readonly FinancialTransactionEngine $ledger) {}

    /** @return \Illuminate\Database\Eloquent\Collection<int, FinanceAccrual> */
    public function all(array $filters = [])
    {
        return FinanceAccrual::query()
            ->with(['akunAkrual:id,code,name', 'akunLawan:id,code,name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['jenis'] ?? null, fn ($q, $jenis) => $q->where('jenis', $jenis))
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, ?int $id = null, ?User $actor = null): FinanceAccrual
    {
        $accrual = $id === null ? new FinanceAccrual() : FinanceAccrual::query()->findOrFail($id);

        if ($accrual->status === 'posted' || $accrual->status === 'reversed') {
            throw ValidationException::withMessages([
                'status' => 'Akrual yang sudah diposting tidak dapat disunting; batalkan lewat pembalikan, lalu buat yang baru.',
            ]);
        }

        $accrual->fill([
            'kode' => $data['kode'] ?? $accrual->kode,
            'tanggal' => $data['tanggal'] ?? $accrual->tanggal,
            'tanggal_balik' => $data['tanggal_balik'] ?? $accrual->tanggal_balik,
            'jenis' => $data['jenis'] ?? $accrual->jenis,
            'akun_akrual_id' => $data['akun_akrual_id'] ?? $accrual->akun_akrual_id,
            'akun_lawan_id' => $data['akun_lawan_id'] ?? $accrual->akun_lawan_id,
            'jumlah' => $data['jumlah'] ?? $accrual->jumlah,
            'deskripsi' => $data['deskripsi'] ?? $accrual->deskripsi,
            'kode_unit' => $data['kode_unit'] ?? $accrual->kode_unit,
            'kode_dana' => $data['kode_dana'] ?? $accrual->kode_dana,
            'created_by' => $accrual->created_by ?? $actor?->id,
        ]);

        if (empty($accrual->kode)) {
            throw ValidationException::withMessages(['kode' => 'Kode akrual wajib diisi.']);
        }

        if (! in_array($accrual->jenis, ['pendapatan', 'beban'], true)) {
            throw ValidationException::withMessages(['jenis' => 'Jenis akrual harus pendapatan atau beban.']);
        }

        if (Money::lte($accrual->jumlah ?? 0, 0)) {
            throw ValidationException::withMessages(['jumlah' => 'Jumlah akrual harus lebih besar dari nol.']);
        }

        if ($accrual->tanggal_balik !== null && $accrual->tanggal !== null
            && Carbon::parse($accrual->tanggal_balik)->lte(Carbon::parse($accrual->tanggal))) {
            throw ValidationException::withMessages([
                'tanggal_balik' => 'Tanggal balik harus SESUDAH tanggal pengakuan; akrual yang dibalik pada hari yang sama tidak pernah bertemu dua periode.',
            ]);
        }

        $this->assertAkun($accrual);
        $accrual->save();

        return $accrual->refresh();
    }

    public function post(FinanceAccrual $accrual, User $actor): FinancialTransaction
    {
        if ($accrual->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => "Akrual {$accrual->kode} sudah berstatus {$accrual->status}.",
            ]);
        }

        return DB::transaction(function () use ($accrual, $actor): FinancialTransaction {
            $lines = $accrual->jenis === 'pendapatan'
                ? [
                    ['account_id' => $accrual->akun_akrual_id, 'debit' => $accrual->jumlah, 'description' => "Piutang akrual {$accrual->kode}"],
                    ['account_id' => $accrual->akun_lawan_id, 'credit' => $accrual->jumlah, 'description' => $accrual->deskripsi ?: "Pengakuan pendapatan akrual {$accrual->kode}"],
                ]
                : [
                    ['account_id' => $accrual->akun_lawan_id, 'debit' => $accrual->jumlah, 'description' => $accrual->deskripsi ?: "Beban akrual {$accrual->kode}"],
                    ['account_id' => $accrual->akun_akrual_id, 'credit' => $accrual->jumlah, 'description' => "Utang akrual {$accrual->kode}"],
                ];

            $transaction = $this->ledger->post(
                type: FinancialTransaction::TYPE_ADJUSTMENT,
                description: "Akrual {$accrual->kode}".($accrual->deskripsi ? ' — '.$accrual->deskripsi : ''),
                lines: $lines,
                context: [
                    'date' => $accrual->tanggal,
                    'sumber_modul' => SumberModul::ACCRUE,
                    'reference_type' => FinanceAccrual::class,
                    'reference_id' => $accrual->id,
                    'created_by' => $actor->id,
                    'kode_unit' => $accrual->kode_unit,
                    'kode_dana' => $accrual->kode_dana,
                ],
            );

            $accrual->forceFill(['status' => 'posted', 'transaction_id' => $transaction->id])->save();

            return $transaction;
        });
    }

    /**
     * Membalik seluruh akrual yang tanggal baliknya sudah tiba.
     *
     * Idempotent: hanya akrual berstatus `posted` yang diambil, dan statusnya
     * berpindah ke `reversed` di dalam transaksi yang sama dengan pembalikannya.
     *
     * @return array{dibalik: int, total: string, rows: list<array<string, mixed>>}
     */
    public function reverseDue(?Carbon $tanggal = null, ?User $actor = null): array
    {
        $batas = $tanggal ?? Carbon::now();

        $rows = [];
        $total = '0.00';

        foreach (FinanceAccrual::query()
            ->where('status', 'posted')
            ->whereDate('tanggal_balik', '<=', $batas->toDateString())
            ->orderBy('tanggal_balik')
            ->get() as $accrual) {

            DB::transaction(function () use ($accrual, $actor, $batas, &$total, &$rows): void {
                $transaction = $accrual->transaction_id !== null
                    ? FinancialTransaction::query()->find($accrual->transaction_id)
                    : null;

                if ($transaction === null || $transaction->status === FinancialTransaction::STATUS_REVERSED) {
                    $accrual->forceFill(['status' => 'reversed'])->save();

                    return;
                }

                $pembalik = $this->ledger->reverse(
                    $transaction,
                    "Pembalikan akrual otomatis {$accrual->kode}",
                    $actor?->id,
                    [],
                    Carbon::parse($accrual->tanggal_balik),
                );

                $accrual->forceFill([
                    'status' => 'reversed',
                    'reversal_transaction_id' => $pembalik->id,
                ])->save();

                $total = Money::add($total, $accrual->jumlah);
                $rows[] = [
                    'accrual_id' => $accrual->id,
                    'kode' => $accrual->kode,
                    'jenis' => $accrual->jenis,
                    'jumlah' => (string) $accrual->jumlah,
                    'reversal_transaction_id' => $pembalik->id,
                ];
            });
        }

        return ['dibalik' => count($rows), 'total' => $total, 'rows' => $rows];
    }

    protected function assertAkun(FinanceAccrual $accrual): void
    {
        $akun = FinanceAccount::query()
            ->whereIn('id', array_filter([$accrual->akun_akrual_id, $accrual->akun_lawan_id]))
            ->get()
            ->keyBy('id');

        $akrual = $akun->get((int) $accrual->akun_akrual_id);
        $lawan = $akun->get((int) $accrual->akun_lawan_id);

        if ($akrual === null || $lawan === null) {
            throw ValidationException::withMessages(['akun_akrual_id' => 'Akun akrual dan akun lawan wajib dipilih dan harus ada.']);
        }

        foreach (['akun_akrual_id' => $akrual, 'akun_lawan_id' => $lawan] as $field => $a) {
            if (! $a->isLeaf()) {
                throw ValidationException::withMessages([$field => "Akun {$a->code} adalah akun induk; pilih akun rincian."]);
            }
        }

        $akarAkrual = CoaHierarchy::dariAkun($akrual);
        $akarLawan = CoaHierarchy::dariAkun($lawan);

        $diharapkan = $accrual->jenis === 'pendapatan'
            ? ['akrual' => CoaHierarchy::ASET, 'lawan' => CoaHierarchy::PENDAPATAN]
            : ['akrual' => CoaHierarchy::KEWAJIBAN, 'lawan' => CoaHierarchy::BEBAN];

        if ($akarAkrual !== $diharapkan['akrual']) {
            $pesan = $accrual->jenis === 'pendapatan'
                ? "Akrual pendapatan harus memakai akun piutang akrual (kelompok 1); akun {$akrual->code} bukan kelompok 1."
                : "Akrual beban harus memakai akun utang akrual (kelompok 2); akun {$akrual->code} bukan kelompok 2.";

            throw ValidationException::withMessages(['akun_akrual_id' => $pesan]);
        }

        if ($akarLawan !== $diharapkan['lawan']) {
            $pesan = $accrual->jenis === 'pendapatan'
                ? "Akrual pendapatan harus diakui pada akun pendapatan (kelompok 4); akun {$lawan->code} bukan kelompok 4."
                : "Akrual beban harus dibebankan ke akun beban (kelompok 5); akun {$lawan->code} bukan kelompok 5.";

            throw ValidationException::withMessages(['akun_lawan_id' => $pesan]);
        }
    }
}
