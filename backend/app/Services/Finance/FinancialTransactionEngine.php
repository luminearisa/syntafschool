<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinancePeriod;
use App\Models\FinancialTransaction;
use App\Models\FinancialTransactionLine;
use App\Services\Finance\Accounting\DanaPolicy;
use App\Services\Finance\Accounting\UnitResolver;
use App\Support\Accounting\CoaHierarchy;
use App\Support\Accounting\JournalValidator;
use App\Support\Accounting\SumberModul;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mesin buku besar double-entry (§46, §47, §49, §51, §61).
 *
 * SATU-SATUNYA jalan menulis `finance_transactions`. Setiap uang masuk/keluar
 * yang sudah confirmed bermuara ke sini, sehingga:
 * - total debit == total credit selalu terjaga (§47);
 * - transaksi tidak bisa masuk periode closed (§49) — koreksi lewat `reverse()`,
 *   bukan mengedit jurnal lama (§51);
 * - saldo kas/bank konkret (subledger) bergerak atomik bersama jurnalnya, dan
 *   tidak pernah berubah tanpa transaction record (§61).
 *
 * Pemisahan control account vs subledger: baris jurnal memakai akun COA (mis.
 * `1100 Kas`) sebagai control account, sedangkan `current_balance` yang bergerak
 * adalah baris kas/bank konkret (mis. `KAS-SEKOLAH`) yang sama-sama menunjuk COA
 * itu. Karena satu COA bisa dinaungi beberapa kas konkret, pemanggil (yang tahu
 * akun konkretnya) menyuplai `balances` secara eksplisit — engine tidak menebak.
 *
 * `DB::transaction` bersarang aman: di dalam transaksi pemanggil ia jadi savepoint.
 */
class FinancialTransactionEngine
{
    /**
     * Toleransi pembulatan float — HANYA untuk jalur kas/bank (subledger) yang
     * nilainya masih float dari kolom saldo.
     *
     * Keseimbangan jurnal TIDAK memakai toleransi ini: ia dibandingkan tepat
     * sebagai string BCMath lewat JournalValidator, karena selisih satu sen
     * adalah ketidakseimbangan yang harus terlihat, bukan dibulatkan diam-diam.
     */
    private const FLOAT_EPSILON = 0.005;

    public function __construct(
        private readonly FinanceNumberingService $numbering,
        private readonly ?UnitResolver $units = null,
        private readonly ?DanaPolicy $dana = null,
    ) {}

    /** Penentu unit; disediakan container, dapat diisi manual pada pengujian. */
    protected function units(): UnitResolver
    {
        return $this->units ?? app(UnitResolver::class);
    }

    /** Penjaga peruntukan dana. */
    protected function dana(): DanaPolicy
    {
        return $this->dana ?? app(DanaPolicy::class);
    }

    /**
     * Posting satu jurnal seimbang + gerakkan saldo kas/bank konkret.
     *
     * §41: jurnal yang dihasilkan di sini LANGSUNG berstatus posted dan tidak
     * pernah dibuka ulang untuk diedit — koreksi hanya lewat `reverse()`.
     * `posted_at`/`posted_by` mencatat momen menjadi immutable.
     *
     * @param  list<array{account_id: int, debit?: float, credit?: float, description?: string|null, cost_center_id?: int|null, reference?: string|null}>  $lines
     * @param  array{
     *     date?: Carbon|string|null,
     *     reference_type?: string|null, reference_id?: int|null,
     *     cost_center_id?: int|null, created_by?: int|null,
     *     metadata?: array<string, mixed>|null,
     *     balances?: list<array{account: FinanceCashAccount|FinanceBankAccount, delta: float}>
     * }  $context
     */
    public function post(string $type, string $description, array $lines, array $context = []): FinancialTransaction
    {
        if (! in_array($type, FinancialTransaction::TYPES, true)) {
            throw new \InvalidArgumentException("Tipe transaksi tidak dikenal: {$type}");
        }

        $normalized = $this->normalizeLines($lines);
        $totals = $this->assertBalanced($normalized);

        $date = $this->resolveDate($context['date'] ?? null);
        $period = $this->resolveOpenPeriod($date);
        $balances = $context['balances'] ?? [];

        // Dimensi standar & penjaga peruntukan dijalankan SEBELUM transaksi
        // dibuka: penolakan karena dana/pusat biaya tidak boleh meninggalkan
        // jejak setengah jadi.
        $sumberModul = $this->resolveSumberModul($context);
        $normalized = $this->prepareDimensi($normalized, $sumberModul, $context);

        return DB::transaction(function () use ($type, $description, $normalized, $totals, $sumberModul, $date, $period, $context, $balances): FinancialTransaction {
            // Kunci subledger lebih dulu (urutan id deterministik → hindari deadlock
            // saat dua transfer berlawanan arah berjalan bersamaan).
            $locked = $this->lockBalances($balances);

            $createdBy = $context['created_by'] ?? null;

            $transaction = FinancialTransaction::create([
                'transaction_number' => $this->numbering->next(FinanceNumberingService::CODE_TRANSACTION),
                'transaction_date' => $date->toDateString(),
                'description' => $description,
                'type' => $type,
                'sumber_modul' => $sumberModul,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
                'cost_center_id' => $context['cost_center_id'] ?? null,
                'period_id' => $period->id,
                // Jumlah dari validator: string BCMath, bukan hasil pembulatan float.
                'total_debit' => $totals['total_debit'],
                'total_credit' => $totals['total_credit'],
                'status' => FinancialTransaction::STATUS_POSTED,
                'posted_at' => Carbon::now(),
                'posted_by' => $createdBy,
                'metadata' => $context['metadata'] ?? null,
                'created_by' => $createdBy,
            ]);

            $createdLines = [];

            foreach ($normalized as $line) {
                $createdLines[] = FinancialTransactionLine::create([
                    'finance_transaction_id' => $transaction->id,
                    'account_id' => $line['account_id'],
                    'account_name' => $line['account_name'] ?? null,
                    'cost_center_id' => $line['cost_center_id'] ?? $context['cost_center_id'] ?? null,
                    'kode_unit' => $line['kode_unit'] ?? null,
                    'kode_dana' => $line['kode_dana'] ?? null,
                    'kode_persediaan' => $line['kode_persediaan'] ?? null,
                    'kuantiti' => $line['kuantiti'] ?? null,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'description' => $line['description'] ?? null,
                    'reference' => $line['reference'] ?? null,
                ]);
            }

            $this->stampSubledgers($createdLines, $balances);
            $this->applyBalances($balances, $locked);

            return $transaction->load('lines.account');
        });
    }

    /**
     * Tandai baris jurnal mana yang menggerakkan rekening kas/bank konkret.
     *
     * Baris jurnal berdiri di atas control account COA (mis. `1100 Kas`), sedangkan
     * saldo operasional hidup di laci (`KAS-SEKOLAH`). Tanpa stempel ini, cached
     * `current_balance` tidak bisa diverifikasi terhadap buku besar — ia jadi
     * sumber kebenaran tersendiri, padahal §1 memintanya menjadi optimasi yang
     * tetap dapat direkonstruksi. Setelah distempel berlaku
     * `saldo = opening + Σ baris berstempel rekening itu`.
     *
     * Stempel hanya dipasang kalau pemetaannya tak ambigu: tepat satu pergerakan
     * saldo DAN tepat satu baris belum-terklaim pada (COA, sisi, nominal) yang
     * sama. Jurnal yang mengagregasi beberapa laci dalam satu baris (mis. setoran
     * gabungan) sengaja dibiarkan tanpa stempel — menebak lebih buruk daripada
     * tidak menandai, karena hasil tebakan tidak bisa dibedakan dari fakta.
     *
     * @param  list<FinancialTransactionLine>  $lines
     * @param  list<array{account: FinanceCashAccount|FinanceBankAccount, delta: float}>  $balances
     */
    protected function stampSubledgers(array $lines, array $balances): void
    {
        if ($balances === [] || $lines === []) {
            return;
        }

        // Kelompokkan pergerakan per (COA, sisi). Delta positif = uang masuk ke
        // rekening = sisi debit; negatif = keluar = sisi credit.
        $groups = [];

        foreach ($balances as $entry) {
            $account = $entry['account'];
            $delta = round((float) $entry['delta'], 2);

            if ($delta === 0.0) {
                continue;
            }

            $side = $delta > 0 ? 'debit' : 'credit';
            $key = $this->coaId($account).'|'.$side;
            $groups[$key]['amount'] = round(($groups[$key]['amount'] ?? 0) + abs($delta), 2);
            $groups[$key]['entries'] = ($groups[$key]['entries'] ?? 0) + 1;
            $groups[$key]['account'] = $account;
        }

        $claimed = [];

        foreach ($groups as $key => $group) {
            if ($group['entries'] !== 1) {
                continue;
            }

            [$accountId, $side] = explode('|', $key);

            $matches = array_values(array_filter(
                $lines,
                fn (FinancialTransactionLine $line): bool => ! isset($claimed[$line->id])
                    && (int) $line->account_id === (int) $accountId
                    && abs((float) $line->{$side} - $group['amount']) <= self::FLOAT_EPSILON
                    && (float) $line->{$side} > 0
            ));

            if (count($matches) !== 1) {
                continue;
            }

            $line = $matches[0];
            $claimed[$line->id] = true;

            $line->update([
                'subledger_type' => FinancialTransactionLine::subledgerTypeForAccount($group['account']),
                'subledger_id' => $group['account']->id,
            ]);
        }
    }

    /**
     * Batalkan jurnal dengan baris lawan (debit↔credit ditukar). Jurnal asli
     * TIDAK diubah nominalnya, hanya ditandai `reversed` dan ditautkan ke
     * jurnal pembalik (§51) — pembukuan tetap bisa ditelusuri dua arah.
     *
     * Pergerakan saldo subledger dibalik lewat `balances` eksplisit dari pemanggil
     * (yang tahu akun kas/bank konkret dari dokumen asalnya).
     *
     * @param  list<array{account: FinanceCashAccount|FinanceBankAccount, delta: float}>  $balances
     */
    public function reverse(FinancialTransaction $original, string $reason, ?int $actorId = null, array $balances = [], ?Carbon $tanggal = null): FinancialTransaction
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib diisi.']);
        }

        return DB::transaction(function () use ($original, $reason, $actorId, $balances, $tanggal): FinancialTransaction {
            // Penjaga "sudah pernah dibatalkan" hanya berarti bila tidak ada
            // reversal lain yang sedang berjalan pada baris yang sama: tanpa
            // kunci, dua permintaan paralel sama-sama membaca `posted` dan
            // menghasilkan dua jurnal pembalik — saldo bergerak dua kali (§3, §5).
            $fresh = FinancialTransaction::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === FinancialTransaction::STATUS_REVERSED) {
                throw ValidationException::withMessages([
                    'transaction_id' => "Jurnal {$fresh->transaction_number} sudah pernah dibatalkan.",
                ]);
            }

            $original->load('lines');
            $counterLines = [];
            foreach ($original->lines as $line) {
                $counterLines[] = [
                    'account_id' => $line->account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => $line->description,
                    'cost_center_id' => $line->cost_center_id,
                    // Dimensi disalin PER BARIS agar jurnal pembalik meniadakan
                    // angka di unit & dana yang persis sama. Tanpa ini, pembatalan
                    // akan memindahkan angka antar unit/dana dan laporan per unit
                    // berbeda dari kenyataan.
                    'kode_unit' => $line->kode_unit,
                    'kode_dana' => $line->kode_dana,
                    'kode_persediaan' => $line->kode_persediaan,
                    'kuantiti' => $line->kuantiti,
                ];
            }

            $counter = $this->post($fresh->type, "Pembatalan {$fresh->transaction_number}: {$reason}", $counterLines, [
                // Tanggal pembalikan boleh ditentukan pemanggil (akrual dibalik
                // pada tanggal baliknya, bukan pada tanggal orang menekan tombol).
                'date' => $tanggal ?? Carbon::now(),
                'sumber_modul' => $fresh->sumber_modul,
                'reference_type' => $fresh->reference_type,
                'reference_id' => $fresh->reference_id,
                'cost_center_id' => $fresh->cost_center_id,
                'created_by' => $actorId,
                'balances' => $balances,
            ]);

            $fresh->status = FinancialTransaction::STATUS_REVERSED;
            $fresh->reversed_by_transaction_id = $counter->id;
            $fresh->save();

            return $counter;
        });
    }

    // ==================== Helper bentuk jurnal umum ====================
    // Dinamakan sesuai peristiwa bisnis supaya pemanggil tidak menyusun baris
    // debit/kredit manual berulang kali; semuanya tetap lewat `post()`.

    /**
     * Uang masuk ke kas/bank (pembayaran tagihan, setoran). Debit kas/bank,
     * credit akun lawan (piutang/pendapatan). Saldo konkret bertambah.
     */
    public function cashReceipt(FinanceCashAccount|FinanceBankAccount $into, int $creditAccountId, float $amount, string $description, array $context = []): FinancialTransaction
    {
        $this->assertPositive($amount);

        return $this->post($context['type'] ?? FinancialTransaction::TYPE_PAYMENT, $description, [
            $this->debit($this->coaId($into), $amount, $description),
            $this->credit($creditAccountId, $amount, $description),
        ], $this->withBalance($context, $into, $amount));
    }

    /**
     * Uang keluar dari kas/bank (pengeluaran, pembayaran vendor, refund).
     * Debit akun lawan (beban/piutang), credit kas/bank. Saldo konkret berkurang.
     */
    public function cashDisbursement(FinanceCashAccount|FinanceBankAccount $from, int $debitAccountId, float $amount, string $description, array $context = []): FinancialTransaction
    {
        $this->assertPositive($amount);

        return $this->post($context['type'] ?? FinancialTransaction::TYPE_EXPENSE, $description, [
            $this->debit($debitAccountId, $amount, $description),
            $this->credit($this->coaId($from), $amount, $description),
        ], $this->withBalance($context, $from, -$amount));
    }

    /**
     * Mutasi internal antar kas/bank (transfer). Debit tujuan, credit sumber;
     * saldo sumber berkurang, tujuan bertambah — tidak menyentuh pendapatan.
     */
    public function transfer(FinanceCashAccount|FinanceBankAccount $from, FinanceCashAccount|FinanceBankAccount $to, float $amount, string $description, array $context = []): FinancialTransaction
    {
        $this->assertPositive($amount);

        if ($this->coaId($from) === $this->coaId($to) && $from::class === $to::class && $from->id === $to->id) {
            throw ValidationException::withMessages(['destination' => 'Akun sumber dan tujuan transfer tidak boleh sama.']);
        }

        $context['balances'] = array_merge($context['balances'] ?? [], [
            ['account' => $from, 'delta' => -$amount],
            ['account' => $to, 'delta' => $amount],
        ]);

        return $this->post(FinancialTransaction::TYPE_TRANSFER, $description, [
            $this->debit($this->coaId($to), $amount, $description),
            $this->credit($this->coaId($from), $amount, $description),
        ], $context);
    }

    /**
     * Pengakuan pendapatan akrual (tagihan/invoice diterbitkan). Debit piutang,
     * credit pendapatan — belum ada uang masuk, jadi tidak menyentuh kas/bank.
     */
    public function accrueRevenue(int $receivableAccountId, int $revenueAccountId, float $amount, string $description, array $context = []): FinancialTransaction
    {
        $this->assertPositive($amount);

        return $this->post($context['type'] ?? FinancialTransaction::TYPE_REVENUE, $description, [
            $this->debit($receivableAccountId, $amount, $description),
            $this->credit($revenueAccountId, $amount, $description),
        ], $context);
    }

    /**
     * Saldo awal kas/bank (§50). Debit kas/bank, credit ekuitas opening balance.
     * Dipakai sekali saat setup; saldo konkret ikut dinaikkan.
     */
    public function openingBalance(FinanceCashAccount|FinanceBankAccount $account, int $equityAccountId, float $amount, string $description, array $context = []): FinancialTransaction
    {
        $this->assertPositive($amount);

        return $this->post(FinancialTransaction::TYPE_OPENING, $description, [
            $this->debit($this->coaId($account), $amount, $description),
            $this->credit($equityAccountId, $amount, $description),
        ], $this->withBalance($context, $account, $amount));
    }

    // ==================== Line & balance builders ====================

    /** @return array{account_id: int, debit: float, credit: float, description: string|null} */
    public function debit(int $accountId, float $amount, ?string $description = null): array
    {
        return ['account_id' => $accountId, 'debit' => round($amount, 2), 'credit' => 0.0, 'description' => $description];
    }

    /** @return array{account_id: int, debit: float, credit: float, description: string|null} */
    public function credit(int $accountId, float $amount, ?string $description = null): array
    {
        return ['account_id' => $accountId, 'debit' => 0.0, 'credit' => round($amount, 2), 'description' => $description];
    }

    /**
     * Tambahkan satu pergerakan saldo subledger ke context. Delta positif = saldo
     * bertambah, negatif = berkurang.
     */
    protected function withBalance(array $context, FinanceCashAccount|FinanceBankAccount $account, float $delta): array
    {
        $context['balances'] = array_merge($context['balances'] ?? [], [
            ['account' => $account, 'delta' => $delta],
        ]);

        return $context;
    }

    /** COA control account yang dinaungi akun kas/bank konkret. */
    protected function coaId(FinanceCashAccount|FinanceBankAccount $account): int
    {
        return (int) ($account instanceof FinanceBankAccount
            ? $account->finance_account_id
            : $account->account_id);
    }

    // ==================== Validasi & internal ====================

    /**
     * @param  list<array{account_id: int, debit?: float, credit?: float, description?: string|null, cost_center_id?: int|null, reference?: string|null}>  $lines
     * @return list<array{account_id: int, debit: float, credit: float, description: string|null, cost_center_id: int|null, reference: string|null}>
     */
    protected function normalizeLines(array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Jurnal minimal memiliki satu baris.']);
        }

        $normalized = [];
        $accountIds = [];

        foreach ($lines as $line) {
            $accountId = (int) ($line['account_id'] ?? 0);

            if ($accountId <= 0) {
                throw ValidationException::withMessages(['lines' => 'Setiap baris jurnal wajib menunjuk akun.']);
            }

            $accountIds[] = $accountId;

            // Uang masuk sebagai float di batas sistem, lalu seluruh perhitungan
            // memakai string BCMath. Setelah titik ini tidak ada float lagi.
            $normalized[] = [
                'account_id' => $accountId,
                'debit' => Money::of($line['debit'] ?? 0),
                'credit' => Money::of($line['credit'] ?? 0),
                'description' => $line['description'] ?? null,
                // §5: penelusuran level baris — unit mana yang menanggung dan
                // nomor dokumen apa yang melahirkannya. Tanpa dilewatkan di sini,
                // nilai ini hilang sebelum sempat disimpan.
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'reference' => $line['reference'] ?? null,
                // Dimensi standar: unit bisnis & dana. Keduanya boleh kosong pada
                // pemanggil lama, dan diisi/diwarisi di post().
                'kode_unit' => $line['kode_unit'] ?? null,
                'kode_dana' => $line['kode_dana'] ?? null,
                // Dimensi persediaan: baris jurnal yang menggerakkan stok menyebut
                // barang & jumlahnya, supaya kartu persediaan dan buku besar tidak
                // dapat saling menyimpang.
                'kode_persediaan' => $line['kode_persediaan'] ?? null,
                'kuantiti' => isset($line['kuantiti']) && $line['kuantiti'] !== null
                    ? \App\Support\Money::quantity($line['kuantiti'])
                    : null,
            ];
        }

        $accounts = $this->assertAccountsPostable($accountIds);

        foreach ($normalized as $index => $line) {
            $akun = $accounts[$line['account_id']] ?? null;

            // Nama akun di-snapshot: laporan bertahun lalu harus tetap terbaca
            // walau nama akunnya diubah kelak.
            $normalized[$index]['account_name'] = $akun?->name;
            $normalized[$index]['account_code'] = $akun?->code;
        }

        return $normalized;
    }

    /** Hanya akun daun (tanpa anak) yang boleh dipakai memposting (§47). */
    /** @return array<int, FinanceAccount> akun keyed by id, untuk snapshot nama akun */
    protected function assertAccountsPostable(array $accountIds): array
    {
        $unique = array_values(array_unique($accountIds));

        $accounts = FinanceAccount::query()->whereIn('id', $unique)->get()->keyBy('id');

        foreach ($unique as $id) {
            $account = $accounts->get($id);

            if ($account === null) {
                throw ValidationException::withMessages(['lines' => "Akun {$id} tidak ditemukan."]);
            }

            if (! $account->is_active) {
                throw ValidationException::withMessages(['lines' => "Akun {$account->code} tidak aktif."]);
            }

            if (! $account->isLeaf()) {
                throw ValidationException::withMessages(['lines' => "Akun {$account->code} adalah akun induk; posting hanya ke akun rincian."]);
            }
        }

        return $accounts->all();
    }

    /** @param  list<array{account_id: int, debit: float, credit: float, description: string|null}>  $lines */
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{total_debit: string, total_credit: string}
     */
    /**
     * Asal modul jurnal. Bila disebut, nilainya wajib terdaftar di SumberModul —
     * supaya "jurnal ini lahir dari modul apa?" selalu punya jawaban pasti dan
     * pemetaan unit bawaan per modul tidak menebak-nebak.
     *
     * @param  array<string, mixed>  $context
     */
    protected function resolveSumberModul(array $context): ?string
    {
        $sumber = $context['sumber_modul'] ?? null;

        if ($sumber === null || $sumber === '') {
            return null;
        }

        if (! SumberModul::isValid((string) $sumber)) {
            throw ValidationException::withMessages([
                'sumber_modul' => "Modul '{$sumber}' tidak terdaftar sebagai sumber jurnal yang sah.",
            ]);
        }

        return (string) $sumber;
    }

    /**
     * Menyiapkan dimensi standar pada baris: pewarisan dana dari kepala dokumen,
     * penjaga peruntukan, kewajiban pusat biaya untuk akun beban, lalu resolusi
     * unit bisnis per baris.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    protected function prepareDimensi(array $lines, ?string $sumberModul, array $context): array
    {
        $danaDokumen = $context['kode_dana'] ?? null;

        if ($danaDokumen !== null) {
            foreach ($lines as $index => $line) {
                // Dana boleh disebut di kepala dokumen (semua baris mewarisi) atau
                // per baris: satu kas keluar bisa membebani dua dana sekaligus.
                $lines[$index]['kode_dana'] = $line['kode_dana'] ?? $danaDokumen;
            }
        }

        $this->dana()->assertPeruntukan($lines, $sumberModul);

        JournalValidator::validatePusatBiaya($lines, $sumberModul ?? SumberModul::JURNAL_UMUM);

        $unitDokumen = $context['kode_unit'] ?? $this->units()->defaultUnitFor($sumberModul);

        return $this->units()->resolveForLines($lines, $unitDokumen);
    }

    protected function assertBalanced(array $lines): array
    {
        // Aturan double-entry dijalankan oleh validator bersama, dengan
        // perbandingan string BCMath — bukan float bertoleransi. Selisih satu sen
        // sekalipun adalah ketidakseimbangan, dan itulah yang harus terlihat.
        return JournalValidator::validateBalanced($lines);
    }

    protected function resolveDate(Carbon|string|null $date): Carbon
    {
        if ($date === null) {
            return Carbon::now();
        }

        return $date instanceof Carbon ? $date : Carbon::parse($date);
    }

    /** Periode yang memuat tanggal transaksi; menolak bila tak ada atau closed (§49). */
    protected function resolveOpenPeriod(Carbon $date): FinancePeriod
    {
        $period = FinancePeriod::forDate($date);

        if ($period === null) {
            throw ValidationException::withMessages([
                'date' => 'Tidak ada periode fiskal untuk tanggal '.$date->toDateString().'. Buka periode terlebih dahulu.',
            ]);
        }

        if (! $period->isOpen()) {
            throw ValidationException::withMessages([
                'date' => 'Periode '.$period->code.' sudah ditutup; transaksi tidak boleh diposting ke periode closed (§49).',
            ]);
        }

        return $period;
    }

    /**
     * Kunci baris kas/bank konkret urutan id menaik.
     *
     * @param  list<array{account: FinanceCashAccount|FinanceBankAccount, delta: float}>  $balances
     * @return array<string, FinanceCashAccount|FinanceBankAccount> keyed by "class:id"
     */
    protected function lockBalances(array $balances): array
    {
        $locked = [];

        // Urutkan kunci agar dua proses mengunci dengan urutan sama → tanpa deadlock.
        $keys = [];
        foreach ($balances as $entry) {
            $account = $entry['account'];
            $keys[$account::class.':'.$account->id] = true;
        }
        ksort($keys);

        foreach (array_keys($keys) as $composite) {
            [$class, $id] = explode(':', $composite);
            /** @var FinanceCashAccount|FinanceBankAccount $row */
            $row = $class::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $locked[$composite] = $row;
        }

        return $locked;
    }

    /**
     * Terapkan delta ke saldo konkret. Beberapa pergerakan boleh menunjuk akun
     * yang sama (diakumulasikan). Saldo tidak boleh negatif — kelebihan tarik
     * adalah bug pemanggil dan harus gagal, bukan diam-diam minus.
     *
     * @param  list<array{account: FinanceCashAccount|FinanceBankAccount, delta: float}>  $balances
     * @param  array<string, FinanceCashAccount|FinanceBankAccount>  $locked
     */
    protected function applyBalances(array $balances, array $locked): void
    {
        $deltas = [];
        foreach ($balances as $entry) {
            $account = $entry['account'];
            $key = $account::class.':'.$account->id;
            $deltas[$key] = round(($deltas[$key] ?? 0) + (float) $entry['delta'], 2);
        }

        foreach ($deltas as $key => $delta) {
            if ($delta === 0.0) {
                continue;
            }

            $row = $locked[$key];
            $before = round((float) $row->current_balance, 2);
            $after = round($before + $delta, 2);

            if ($after < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Saldo '.$row->name.' tidak mencukupi (tersedia Rp '.number_format($before, 0, ',', '.').').',
                ]);
            }

            $row->current_balance = $after;
            $row->save();

            // Segarkan instance yang dipegang pemanggil agar saldo berikutnya benar.
            foreach ($balances as $b) {
                if ($key === $b['account']::class.':'.$b['account']->id) {
                    $b['account']->setRawAttributes($row->getAttributes(), true);
                }
            }
        }
    }

    protected function assertPositive(float $amount): void
    {
        if (! is_finite($amount) || round($amount, 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal harus lebih besar dari nol.']);
        }

        if (abs(round($amount, 2) - $amount) > self::FLOAT_EPSILON) {
            throw ValidationException::withMessages(['amount' => 'Nominal maksimum dua angka di belakang koma (sampai sen).']);
        }
    }
}
