<?php

namespace App\Services\Finance;

use App\Models\FinanceBankAccount;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBankStatementLine;
use App\Models\FinanceReconciliation;
use App\Models\FinanceReconciliationItem;
use App\Models\FinancialTransaction;
use App\Models\FinancialTransactionLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rekening koran bank + pencocokan otomatis (Phase 4 §4).
 *
 * Alur §4: Import → Parse → Detect Existing Transactions → Match → Review →
 * Reconcile → Post Adjustment if Needed. Dua batas yang dijaga di sini:
 *
 * **Mengimpor rekening koran tidak pernah membuat jurnal.** Baris statement
 * hanyalah data dari sisi bank; tabelnya bahkan tidak punya kolom
 * `finance_transaction_id`. Jurnal hanya lahir dari workflow resmi (payment,
 * expense, transfer, bank transaction, adjustment). Kalau import ikut memposting,
 * setiap setoran orang tua tercatat dua kali.
 *
 * **Saldo buku tidak disalin ke statement.** Book Balance selalu dibaca dari
 * rekening (`finance_bank_accounts`), sehingga hanya ada satu sumber kebenaran
 * dan §1 tidak dilanggar.
 *
 * Pencocokan diarahkan ke BARIS JURNAL bertanda subledger (§1), bukan ke
 * dokumen: semua pergerakan uang melewati jurnal, sedangkan baris
 * `finance_bank_transactions` hanya ada untuk transaksi yang diketik manual —
 * payment via transfer bank tidak memilikinya. Satu baris jurnal hanya boleh
 * diklaim satu baris statement, jadi dua rekening koran tidak bisa sama-sama
 * mengklaim setoran yang sama.
 *
 * Presisi mengikuti §4 (reference, nomor dokumen, nomor jurnal, nominal+tanggal,
 * nominal saja) dan tiap hasil menyimpan `match_method`, supaya layar review
 * bisa mempertanyakan kenapa suatu baris dianggap cocok. Kasus ambigu sengaja
 * dibiarkan `unmatched`: pencocokan paksa menghasilkan rekonsiliasi yang tampak
 * rapi tapi salah.
 */
class FinanceBankStatementService
{
    private const TOLERANCE = 0.005;

    public function __construct(
        private readonly FinanceNumberingService $numbering,
        private readonly FinanceReconciliationService $reconciliations,
        private readonly FinanceSettingsService $settings,
    ) {}

    // ==================== Import ====================

    /**
     * Simpan satu rekening koran beserta barisnya (§4), lalu cocokkan otomatis.
     *
     * Idempoten pada (`bank_account_id`, `external_reference`): file yang sama
     * diimpor ulang tidak menghasilkan statement atau baris ganda; baris baru
     * ditambahkan, baris yang sudah ada dibiarkan (pencocokannya tetap berlaku).
     *
     * @param  array{bank_account_id: int, statement_date: Carbon|string, opening_balance: float,
     *               closing_balance: float, source?: string|null, external_reference?: string|null,
     *               filename?: string|null, notes?: string|null, auto_match?: bool,
     *               lines: list<array<string, mixed>>}  $data
     */
    public function import(array $data, ?User $actor = null): FinanceBankStatement
    {
        $bank = $this->resolveBank((int) ($data['bank_account_id'] ?? 0));
        $source = $this->resolveSource((string) ($data['source'] ?? FinanceBankStatement::SOURCE_MANUAL));
        $statementDate = $this->resolveDate($data['statement_date'] ?? null, 'statement_date');
        $rows = $this->normalizeRows($data['lines'] ?? []);
        $opening = round((float) ($data['opening_balance'] ?? 0), 2);
        $closing = round((float) ($data['closing_balance'] ?? 0), 2);
        $externalReference = $this->nullableText($data['external_reference'] ?? null, 100, 'external_reference');

        $movement = round(array_sum(array_column($rows, 'amount')), 2);

        // Parse-check: rekening koran yang tidak konsisten terhadap dirinya
        // sendiri bukan bukti yang layak direkonsiliasi.
        if (abs($opening + $movement - $closing) > self::TOLERANCE) {
            throw ValidationException::withMessages([
                'closing_balance' => 'Rekening koran tidak konsisten: saldo awal '.number_format($opening, 2).' ditambah mutasi '
                    .number_format($movement, 2).' = '.number_format($opening + $movement, 2).', bukan saldo akhir '
                    .number_format($closing, 2).'. Periksa saldo atau baris yang terlewat.',
            ]);
        }

        return DB::transaction(function () use ($data, $bank, $source, $statementDate, $rows, $opening, $closing, $externalReference, $actor): FinanceBankStatement {
            [$statement, $isReuse] = $this->openHeader($data, $bank, $source, $statementDate, $opening, $closing, $externalReference, $actor);

            $this->putLines($statement, $rows, $isReuse);

            // Setelah diimpor, rekening koran selalu menunggu tangan manusia:
            // reconciliation tidak boleh terjadi tanpa review (§4).
            $this->markInReview($statement);

            if (($data['auto_match'] ?? true) !== false) {
                $this->autoMatch($statement, $actor);
            }

            return $statement->fresh()->load(['lines', 'bankAccount']);
        });
    }

    /** Tambah satu baris rekening koran (layar review / entri manual). */
    public function addLine(FinanceBankStatement $statement, array $data, ?User $actor = null): FinanceBankStatementLine
    {
        $row = $this->normalizeRows([$data])[0];

        return DB::transaction(function () use ($statement, $row, $actor): FinanceBankStatementLine {
            $fresh = $this->lockStatement($statement);
            $this->assertEditable($fresh);

            if (filled($row['external_id']) && $fresh->lines()->where('external_id', $row['external_id'])->exists()) {
                throw ValidationException::withMessages([
                    'external_id' => 'Baris dengan ID bank ini sudah ada di rekening koran tersebut.',
                ]);
            }

            $sequence = ((int) $fresh->lines()->max('sequence')) + 1;

            $line = FinanceBankStatementLine::create(array_merge($row, [
                'bank_statement_id' => $fresh->id,
                'sequence' => $sequence,
                'running_balance' => $this->runningBalance($fresh, $row, $sequence),
                'status' => FinanceBankStatementLine::STATUS_UNMATCHED,
            ]));

            $this->markInReview($fresh);
            $this->autoMatch($fresh, $actor);

            return $line->fresh();
        });
    }

    /**
     * Ubah baris rekening koran. Nominal yang berubah melepas pencocokan lama:
     * klaim menunjuk jurnal dengan angka yang tidak lagi sama, dan membiarkannya
     * berarti rekonsiliasi mengesahkan angka yang salah.
     */
    public function updateLine(FinanceBankStatementLine $line, array $data): FinanceBankStatementLine
    {
        return DB::transaction(function () use ($line, $data): FinanceBankStatementLine {
            $statement = $this->lockStatement($line->statement);
            $this->assertEditable($statement);

            $fresh = FinanceBankStatementLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertLineEditable($fresh);

            $row = $this->normalizeRow($data + [
                'line_date' => $data['line_date'] ?? $fresh->line_date?->toDateString(),
                'reference' => $data['reference'] ?? $fresh->reference,
                'description' => $data['description'] ?? $fresh->description,
                'debit' => $data['debit'] ?? $fresh->debit,
                'credit' => $data['credit'] ?? $fresh->credit,
                'external_id' => $data['external_id'] ?? $fresh->external_id,
            ], (int) $fresh->id);

            $amountChanged = abs((float) $fresh->amount - $row['amount']) > self::TOLERANCE;

            $fresh->update(array_merge($row, [
                'running_balance' => $row['running_balance'] ?? $fresh->running_balance,
                'status' => FinanceBankStatementLine::STATUS_UNMATCHED,
                'match_method' => null,
                'journal_line_id' => null,
                'matched_by' => null,
                'matched_at' => null,
            ]));

            if ($amountChanged) {
                $this->autoMatch($statement);
            }

            return $fresh->refresh();
        });
    }

    public function removeLine(FinanceBankStatementLine $line): void
    {
        DB::transaction(function () use ($line): void {
            $statement = $this->lockStatement($line->statement);
            $this->assertEditable($statement);

            $fresh = FinanceBankStatementLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertLineEditable($fresh);

            $fresh->delete();
        });
    }

    /**
     * Batalkan rekening koran yang masih proses. Tidak ada jurnal yang ikut
     * dihapus — memang tidak pernah ada (§4).
     */
    public function cancel(FinanceBankStatement $statement, ?User $actor = null): FinanceBankStatement
    {
        return DB::transaction(function () use ($statement): FinanceBankStatement {
            $fresh = $this->lockStatement($statement);
            $this->assertEditable($fresh);

            if ($fresh->lines()->where('status', FinanceBankStatementLine::STATUS_RECONCILED)->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'Rekening koran ini sudah dipakai rekonsiliasi final; batalkan rekonsiliasinya lebih dulu.',
                ]);
            }

            $fresh->update(['status' => FinanceBankStatement::STATUS_CANCELLED]);

            return $fresh;
        });
    }

    // ==================== Pencocokan ====================

    /**
     * Cocokkan otomatis baris yang masih unmatched (§4 "Detect" + "Match").
     *
     * @return array<string, int>
     */
    public function autoMatch(FinanceBankStatement $statement, ?User $actor = null): array
    {
        return DB::transaction(function () use ($statement, $actor): array {
            // Endpoint auto-match bisa dipanggil dua kali bersamaan (atau bersamaan
            // dengan unggah baris). Daftar "sudah diklaim" dibaca dari snapshot,
            // jadi tanpa kunci header satu baris jurnal bisa diklaim dua baris
            // statement berbeda — rekonsiliasi mengesahkan angka yang sama dua kali
            // (§3, §5). Urutan lock: statement → baris, sama seperti matchManually.
            $fresh = $this->lockStatement($statement);
            $fresh->load(['lines', 'bankAccount']);

            if (! $fresh->isEditable()) {
                return $this->counts($fresh) + ['newly_matched' => 0, 'ambiguous' => 0];
            }

            $pool = $this->candidatePool($fresh->bankAccount);
            $claimed = $this->claimedJournalLineIds($fresh->id);
            $window = $this->dateWindow();

            $matched = 0;
            $ambiguous = 0;

            foreach ($fresh->lines as $line) {
                if ($line->status !== FinanceBankStatementLine::STATUS_UNMATCHED || $line->journal_line_id !== null) {
                    continue;
                }

                $ranked = $this->rank($line, $pool, $claimed, $window);

                if ($ranked['candidate'] === null) {
                    $ambiguous += $ranked['ambiguous'] ? 1 : 0;

                    continue;
                }

                $claimed[$ranked['candidate']['journal_line_id']] = true;

                if (! $this->claim($line, $ranked['candidate'], $ranked['method'], $actor)) {
                    // Kandidatnya ternyata sudah dipakai pencocokan lain. Baris
                    // statement ini tetap unmatched dan muncul sebagai kandidat
                    // review; jangan dihitung sebagai cocok.
                    continue;
                }

                $matched++;
            }

            if ($matched > 0) {
                $this->markInReview($fresh);
            }

            return $this->counts($fresh) + ['newly_matched' => $matched, 'ambiguous' => $ambiguous];
        });
    }

    /**
     * Cocokkan manual satu baris statement ke satu baris jurnal (layar review).
     *
     * Lawan yang boleh dipakai: baris jurnal posted/reversed milik rekening bank
     * yang sama (lewat stempel subledger §1) dengan nominal dan arah yang sama.
     * Melonggarkan salah satunya membuat rekonsiliasi mencocokkan angka yang
     * memang tidak cocok, dan selisihnya hilang tanpa jejak.
     */
    public function matchManually(
        FinanceBankStatementLine $line,
        FinancialTransactionLine $journalLine,
        ?User $actor = null,
    ): FinanceBankStatementLine {
        return DB::transaction(function () use ($line, $journalLine, $actor): FinanceBankStatementLine {
            $statement = $this->lockStatement($line->statement);
            $this->assertEditable($statement);

            $fresh = FinanceBankStatementLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertLineEditable($fresh);

            $journal = FinancialTransactionLine::query()->whereKey($journalLine->id)->with('transaction')->lockForUpdate()->firstOrFail();
            $this->assertJournalLineUsable($journal, $statement, $fresh);

            $side = $fresh->isInflow() ? 'debit' : 'credit';
            $expected = round(abs((float) $fresh->amount), 2);

            if (abs((float) $journal->{$side} - $expected) > self::TOLERANCE) {
                throw ValidationException::withMessages([
                    'journal_line_id' => 'Nominal atau arah baris jurnal tidak sama dengan baris rekening koran ini. Bila bank memotong biaya berbeda, catat selisihnya lewat adjustment (§51) — jangan dipaksakan cocok.',
                ]);
            }

            $fresh->update([
                'status' => FinanceBankStatementLine::STATUS_MATCHED,
                'match_method' => 'manual',
                'journal_line_id' => $journal->id,
                'matched_by' => $actor?->id,
                'matched_at' => Carbon::now(),
                'notes' => null,
            ]);

            $this->markInReview($statement);

            return $fresh->refresh();
        });
    }

    /** Lepas pencocokan sebuah baris (kembali ke unmatched). */
    public function unmatch(FinanceBankStatementLine $line, ?User $actor = null): FinanceBankStatementLine
    {
        return DB::transaction(function () use ($line, $actor): FinanceBankStatementLine {
            $statement = $this->lockStatement($line->statement);
            $this->assertEditable($statement);

            $fresh = FinanceBankStatementLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertLineEditable($fresh);

            $fresh->update([
                'status' => FinanceBankStatementLine::STATUS_UNMATCHED,
                'match_method' => null,
                'journal_line_id' => null,
                'matched_by' => $actor?->id,
                'matched_at' => null,
            ]);

            return $fresh->refresh();
        });
    }

    /**
     * Kecualikan baris yang memang bukan mutasi kita (§4 EXCLUDED), mis. potongan
     * unit lain di rekening bersama. Alasan wajib diisi: pengecualian mengurangi
     * bukti yang diperiksa, jadi keputusannya harus terbaca di audit trail (§22).
     */
    public function exclude(FinanceBankStatementLine $line, string $reason, ?User $actor = null): FinanceBankStatementLine
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan pengecualian wajib diisi.']);
        }

        return DB::transaction(function () use ($line, $reason, $actor): FinanceBankStatementLine {
            $statement = $this->lockStatement($line->statement);
            $this->assertEditable($statement);

            $fresh = FinanceBankStatementLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $this->assertLineEditable($fresh);

            $fresh->update([
                'status' => FinanceBankStatementLine::STATUS_EXCLUDED,
                'match_method' => 'manual',
                'journal_line_id' => null,
                'matched_by' => $actor?->id,
                'matched_at' => Carbon::now(),
                'notes' => $reason,
            ]);

            return $fresh->refresh();
        });
    }

    /**
     * Kandidat baris jurnal untuk satu baris statement (layar review §4):
     * pergerakan rekening ini, searah, senominal, belum diklaim statement mana pun.
     *
     * Toleransi tanggal TIDAK dipakai di sini: mesin pencocok otomatis harus
     * konservatif, tapi layar review boleh mempertunjukkan mutasi yang nomimalnya
     * sama walau bank membukukannya jauh lebih lambat — `matchManually()` sendiri
     * tidak menggugat tanggal, hanya nominal dan arah. Kandidat diurutkan dari
     * yang terdekat tanggalnya supaya manusia melihat calon paling masuk akal
     * lebih dulu.
     *
     * @return list<array<string, mixed>>
     */
    public function candidatesFor(FinanceBankStatementLine $line, int $limit = 25): array
    {
        $line->loadMissing('statement.bankAccount');

        $pool = $this->candidatePool($line->statement->bankAccount, $this->claimedJournalLineIds());
        $direction = $line->isInflow() ? 'in' : 'out';
        $amount = round(abs((float) $line->amount), 2);
        $anchor = $line->line_date;

        $near = array_values(array_filter(
            $pool,
            fn (array $candidate): bool => $candidate['direction'] === $direction
                && abs($candidate['amount'] - $amount) <= self::TOLERANCE,
        ));

        usort($near, fn (array $a, array $b): int => $this->dateDistance($a['transaction_date'], $anchor)
            <=> $this->dateDistance($b['transaction_date'], $anchor));

        return array_slice($near, 0, max(1, $limit));
    }

    // ==================== Review & rekonsiliasi ====================

    /**
     * Ringkasan review §4: Book Balance vs Bank Balance vs Difference, plus
     * hitungan baris per status. Book balance dibaca dari rekening, bukan
     * disalin dari kolom statement.
     *
     * @return array<string, mixed>
     */
    public function summary(FinanceBankStatement $statement): array
    {
        $statement->loadMissing(['lines', 'bankAccount']);

        $book = round((float) $statement->bankAccount->current_balance, 2);
        $bankBalance = round((float) $statement->closing_balance, 2);

        return [
            'book_balance' => $book,
            'bank_balance' => $bankBalance,
            'difference' => round($bankBalance - $book, 2),
            'movement_total' => $statement->movementTotal(),
            'counts' => $this->counts($statement),
            'unmatched_amount' => round((float) $statement->lines
                ->where('status', FinanceBankStatementLine::STATUS_UNMATCHED)
                ->sum('amount'), 2),
            'excluded_amount' => round((float) $statement->lines
                ->where('status', FinanceBankStatementLine::STATUS_EXCLUDED)
                ->sum('amount'), 2),
            'ready_to_reconcile' => $statement->lines->isNotEmpty()
                && $statement->lines->every(fn (FinanceBankStatementLine $line): bool => $line->isResolved()),
        ];
    }

    /**
     * Pergerakan buku besar rekening ini yang tidak ada di rekening koran
     * (§4 "Detect"): belum diklaim baris statement mana pun dan jatuh tempo
     * dalam rentang statement. Inilah daftar "kita mencatat, bank tidak" —
     * calon selisih yang harus dijelaskan, bukan ditutupi.
     *
     * @return list<array<string, mixed>>
     */
    public function unclaimedMovements(FinanceBankStatement $statement): array
    {
        $statement->loadMissing(['lines', 'bankAccount']);

        $pool = $this->candidatePool($statement->bankAccount, $this->claimedJournalLineIds());
        $from = $statement->lines->pluck('line_date')->filter()->min();

        return array_values(array_filter(
            $pool,
            function (array $candidate) use ($from, $statement): bool {
                if ($candidate['transaction_date'] === null) {
                    return false;
                }

                $date = Carbon::parse($candidate['transaction_date']);

                if ($date->greaterThan($statement->statement_date)) {
                    return false;
                }

                return $from === null || $date->greaterThanOrEqualTo($from);
            },
        ));
    }

    /**
     * Rekonsiliasi resmi dari rekening koran (§4/§45): buat header + baris dari
     * statement, lalu finalkan. Hanya boleh bila semua baris sudah selesai
     * (matched/reconciled/excluded).
     *
     * Selisih Book vs Bank dihitung `FinanceReconciliationService::finalize()`:
     * nol → rekonsiliasi selesai; selain itu → discrepancy yang hanya bisa ditutup
     * lewat workflow adjustment resmi (§51), bukan lewat layar ini.
     */
    public function reconcile(FinanceBankStatement $statement, ?User $actor = null): FinanceReconciliation
    {
        return DB::transaction(function () use ($statement, $actor): FinanceReconciliation {
            $fresh = $this->lockStatement($statement);
            $fresh->load(['lines', 'bankAccount']);
            $this->assertEditable($fresh);

            $unresolved = $fresh->lines->where('status', FinanceBankStatementLine::STATUS_UNMATCHED);

            if ($unresolved->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'Rekening koran belum selesai: '.$unresolved->count()
                        .' baris masih belum cocok. Cocokkan, atau kecualikan dengan alasan, sebelum rekonsiliasi.',
                ]);
            }

            $excluded = $fresh->lines->where('status', FinanceBankStatementLine::STATUS_EXCLUDED);

            $reconciliation = $this->reconciliations->open([
                'bank_account_id' => $fresh->bank_account_id,
                'bank_statement_id' => $fresh->id,
                'statement_date' => $fresh->statement_date,
                'period_start' => $this->periodStart($fresh),
                'period_end' => $fresh->statement_date,
                'bank_balance' => (float) $fresh->closing_balance,
                'notes' => $excluded->isEmpty()
                    ? 'Rekonsiliasi dari rekening koran '.$fresh->statement_number.'.'
                    : 'Rekonsiliasi dari rekening koran '.$fresh->statement_number
                        .'; '.$excluded->count().' baris dikecualikan ('.number_format((float) $excluded->sum('amount'), 2)
                        .') dengan alasan tercatat di baris statement.',
            ], $actor);

            foreach ($fresh->lines as $line) {
                $this->reconciliations->addItem($reconciliation, [
                    'transaction_date' => $line->line_date,
                    'description' => $line->description ?: $line->reference,
                    'bank_amount' => (float) $line->amount,
                    'bank_statement_line_id' => $line->id,
                    'journal_line_id' => $line->journal_line_id,
                    'match_status' => $this->itemStatusFor($line),
                    'notes' => $line->notes,
                ], $actor);
            }

            return $this->reconciliations->finalize($reconciliation, $actor);
        });
    }

    // ==================== Internal: header & baris ====================

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: FinanceBankStatement, 1: bool} header + apakah reuse import ulang
     */
    protected function openHeader(
        array $data,
        FinanceBankAccount $bank,
        string $source,
        Carbon $statementDate,
        float $opening,
        float $closing,
        ?string $externalReference,
        ?User $actor,
    ): array {
        $existing = $externalReference === null
            ? null
            : FinanceBankStatement::query()
                ->where('bank_account_id', $bank->id)
                ->where('external_reference', $externalReference)
                ->first();

        if ($existing !== null) {
            if (! $existing->isEditable()) {
                throw ValidationException::withMessages([
                    'external_reference' => "Rekening koran {$existing->statement_number} sudah difinalkan dan tidak bisa diimpor ulang.",
                ]);
            }

            $existing->update([
                'statement_date' => $statementDate->toDateString(),
                'opening_balance' => $opening,
                'closing_balance' => $closing,
                'source' => $source,
                'filename' => $this->nullableText($data['filename'] ?? null, 200, 'filename'),
                'notes' => $this->nullableText($data['notes'] ?? null, 500, 'notes'),
            ]);

            return [$existing->refresh(), true];
        }

        return [FinanceBankStatement::create([
            'statement_number' => $this->numbering->next(FinanceNumberingService::CODE_BANK_STATEMENT),
            'bank_account_id' => $bank->id,
            'statement_date' => $statementDate->toDateString(),
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'source' => $source,
            'external_reference' => $externalReference,
            'filename' => $this->nullableText($data['filename'] ?? null, 200, 'filename'),
            'status' => FinanceBankStatement::STATUS_IMPORTED,
            'notes' => $this->nullableText($data['notes'] ?? null, 500, 'notes'),
            'created_by' => $actor?->id,
        ]), false];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function putLines(FinanceBankStatement $statement, array $rows, bool $isReuse): void
    {
        $existing = $statement->lines()->get()->keyBy('external_id');
        $sequence = (int) $statement->lines()->max('sequence');

        foreach ($rows as $index => $row) {
            $prior = filled($row['external_id']) ? $existing->get($row['external_id']) : null;

            if ($prior !== null) {
                // Baris yang tidak berubah dibiarkan apa adanya — pencocokannya
                // tetap berlaku. Baris yang nominalnya berubah ditolak, bukan
                // diperbaiki diam-diam: klaim lamanya menunjuk jurnal yang salah.
                if (abs((float) $prior->amount - $row['amount']) > self::TOLERANCE) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.external_id" => 'Baris '.$row['external_id'].' berubah nominal ('
                            .number_format((float) $prior->amount, 2).' → '.number_format($row['amount'], 2)
                            .'). Batalkan rekening koran ini lalu impor ulang.',
                    ]);
                }

                continue;
            }

            // Import ulang header yang sama hanya aman kalau barisnya punya ID
            // bank; tanpa itu baris baru menumpuk jadi duplikat.
            if ($isReuse && $existing->count() > 0 && $row['external_id'] === null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.external_id" => 'Import ulang rekening koran yang sama menuntut setiap baris memiliki external_id agar tidak menghasilkan baris ganda.',
                ]);
            }

            $sequence++;

            FinanceBankStatementLine::create(array_merge($row, [
                'bank_statement_id' => $statement->id,
                'sequence' => $sequence,
                'running_balance' => $this->runningBalance($statement, $row, $sequence),
                'status' => FinanceBankStatementLine::STATUS_UNMATCHED,
            ]));
        }
    }

    /**
     * Saldo berjalan menurut bank: angka dari file dipakai apa adanya, kalau tidak
     * ada dihitung dari saldo berjalan baris sebelumnya + mutasi baris ini.
     *
     * @param  array<string, mixed>  $row
     */
    protected function runningBalance(FinanceBankStatement $statement, array $row, int $sequence): float
    {
        if ($row['running_balance'] !== null) {
            return (float) $row['running_balance'];
        }

        $prior = FinanceBankStatementLine::query()
            ->where('bank_statement_id', $statement->id)
            ->where('sequence', '<', $sequence)
            ->orderByDesc('sequence')
            ->first();

        $from = $prior?->running_balance !== null
            ? (float) $prior->running_balance
            : (float) $statement->opening_balance;

        return round($from + (float) $row['amount'], 2);
    }

    /**
     * Validasi + normalisasi satu baris rekening koran.
     *
     * Arah mengikuti rekening koran Indonesia: debit = uang keluar, credit = uang
     * masuk. `amount` SELALU dihitung (credit − debit), tidak diambil dari input,
     * supaya keseimbangan `opening + Σ amount = closing` tidak bisa dimanipulasi.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalizeRow(array $row, int $index): array
    {
        $key = 'lines.'.$index;
        $debit = round((float) ($row['debit'] ?? 0), 2);
        $credit = round((float) ($row['credit'] ?? 0), 2);

        if ($debit < 0 || $credit < 0) {
            throw ValidationException::withMessages([$key => 'Debit/credit rekening koran tidak boleh negatif.']);
        }

        if ($debit > 0 && $credit > 0) {
            throw ValidationException::withMessages([$key => 'Satu baris rekening koran hanya boleh debit atau credit, bukan keduanya.']);
        }

        if ($debit === 0.0 && $credit === 0.0) {
            throw ValidationException::withMessages([$key => 'Baris rekening koran harus punya nominal (debit atau credit).']);
        }

        $running = $row['running_balance'] ?? null;

        return [
            'line_date' => filled($row['line_date'] ?? null) ? $this->resolveDate($row['line_date'], $key)->toDateString() : null,
            'reference' => $this->nullableText($row['reference'] ?? null, 100, $key),
            'description' => $this->nullableText($row['description'] ?? null, 500, $key),
            'debit' => $debit,
            'credit' => $credit,
            'amount' => round($credit - $debit, 2),
            'external_id' => $this->nullableText($row['external_id'] ?? null, 100, $key),
            'running_balance' => $running === null ? null : round((float) $running, 2),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function normalizeRows(mixed $lines): array
    {
        if (! is_array($lines) || $lines === []) {
            throw ValidationException::withMessages(['lines' => 'Rekening koran harus memuat minimal satu baris mutasi.']);
        }

        $rows = array_values(array_filter($lines, fn (mixed $row): bool => is_array($row)));

        if ($rows === []) {
            throw ValidationException::withMessages(['lines' => 'Baris rekening koran harus berupa daftar objek mutasi.']);
        }

        $normalized = [];

        foreach ($rows as $index => $row) {
            $normalized[] = $this->normalizeRow($row, $index);
        }

        return $normalized;
    }

    protected function resolveBank(int $id): FinanceBankAccount
    {
        $bank = FinanceBankAccount::query()->whereKey($id)->first();

        if ($bank === null) {
            throw ValidationException::withMessages(['bank_account_id' => 'Rekening bank tidak ditemukan.']);
        }

        return $bank;
    }

    protected function resolveSource(string $source): string
    {
        if (! in_array($source, FinanceBankStatement::SOURCES, true)) {
            throw ValidationException::withMessages(['source' => 'Sumber rekening koran harus manual, paste, atau csv.']);
        }

        return $source;
    }

    protected function resolveDate(mixed $value, string $key): Carbon
    {
        if (filled($value)) {
            return Carbon::parse($value);
        }

        throw ValidationException::withMessages([$key => 'Tanggal rekening koran wajib diisi.']);
    }

    protected function nullableText(mixed $value, int $max, string $key): ?string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$key => 'Teks '.ucfirst($key).' melebihi '.$max.' karakter.']);
        }

        return $text;
    }

    // ==================== Internal: matching ====================

    /**
     * Kandidat = baris jurnal bertanda subledger rekening ini. Baris tanpa stempel
     * sengaja tidak dijadikan kandidat: ia mengagregasi beberapa rekening (mis.
     * setoran gabungan), jadi memasangkan rekening koran ke sana berarti mengarang.
     *
     * @param  array<int, true>  $claimed
     * @return list<array<string, mixed>>
     */
    protected function candidatePool(FinanceBankAccount $bank, array $claimed = []): array
    {
        $lines = FinancialTransactionLine::query()
            ->with('transaction')
            ->where('subledger_type', FinancialTransactionLine::SUBLEDGER_BANK)
            ->where('subledger_id', $bank->id)
            ->whereHas('transaction', fn (Builder $q) => $q->whereIn('status', FinancialTransaction::LEDGER_STATUSES))
            ->orderBy('id')
            ->limit(2000)
            ->get();

        $documents = $this->documentNumbers($lines);

        return $lines
            ->reject(fn (FinancialTransactionLine $line): bool => isset($claimed[$line->id]))
            ->map(fn (FinancialTransactionLine $line): array => $this->poolEntry($line, $documents))
            ->values()
            ->all();
    }

    /**
     * Bentuk satu kandidat untuk pencocokan + tampilan review.
     *
     * @param  array<string, string>  $documents  "class:id" => nomor dokumen ternormalisasi
     * @return array<string, mixed>
     */
    protected function poolEntry(FinancialTransactionLine $line, array $documents = []): array
    {
        $journal = $line->transaction;
        $isInflow = (float) $line->debit > 0;

        $documentNumber = $documents[$journal->reference_type.':'.$journal->reference_id] ?? null;

        $tokens = array_values(array_unique(array_filter(
            array_map(
                fn (?string $token): string => $this->normalizeToken($token),
                [$journal->transaction_number, $line->reference, $documentNumber],
            ),
            fn (string $token): bool => $token !== '',
        )));

        return [
            'journal_line_id' => (int) $line->id,
            'finance_transaction_id' => (int) $journal->id,
            'journal_number' => $journal->transaction_number,
            'transaction_date' => $journal->transaction_date?->toDateString(),
            'journal_type' => $journal->type,
            'journal_type_label' => $journal->typeLabel(),
            'description' => $journal->description,
            'document_key' => $journal->reference_type === null
                ? null
                : (FinanceJournalService::DOCUMENTS[$journal->reference_type]['key'] ?? null),
            'document_number' => $documentNumber === null ? null : (string) $documentNumber,
            'debit' => round((float) $line->debit, 2),
            'credit' => round((float) $line->credit, 2),
            'direction' => $isInflow ? 'in' : 'out',
            'amount' => round($isInflow ? (float) $line->debit : (float) $line->credit, 2),
            'tokens' => $tokens,
        ];
    }

    /**
     * Nomor dokumen sumber milik jurnal kandidat: nomor pembayaran/kuitansi/
     * invoice/transfer yang bisa muncul di keterangan rekening koran (§4).
     *
     * @param  Collection<int, FinancialTransactionLine>  $lines
     * @return array<string, string>
     */
    protected function documentNumbers(Collection $lines): array
    {
        $grouped = [];

        foreach ($lines as $line) {
            $journal = $line->transaction;

            if ($journal === null || $journal->reference_type === null || $journal->reference_id === null) {
                continue;
            }

            if (! isset(FinanceJournalService::DOCUMENTS[$journal->reference_type])) {
                continue;
            }

            $grouped[$journal->reference_type][] = (int) $journal->reference_id;
        }

        $numbers = [];

        foreach ($grouped as $class => $ids) {
            $column = FinanceJournalService::DOCUMENTS[$class]['number'];

            foreach ($class::query()->whereIn('id', array_values(array_unique($ids)))->get([$column, 'id']) as $document) {
                $token = $this->normalizeToken($document->getAttribute($column));

                if ($token !== '') {
                    $numbers[$class.':'.$document->getKey()] = $token;
                }
            }
        }

        return $numbers;
    }

    /**
     * Beri peringkat sinyal §4 dari yang terkuat: ID bank → referensi → nomor
     * dokumen di keterangan → nominal+tanggal sama → nominal dalam toleransi
     * tanggal. Berhenti pada sinyal pertama yang menghasilkan tepat satu
     * kandidat; kalau semuanya ambigu, baris dibiarkan unmatched.
     *
     * @param  list<array<string, mixed>>  $pool
     * @param  array<int, true>  $claimed
     * @return array{candidate: array<string, mixed>|null, method: string|null, ambiguous: bool}
     */
    protected function rank(FinanceBankStatementLine $line, array $pool, array $claimed, int $windowDays): array
    {
        $direction = $line->isInflow() ? 'in' : 'out';
        $amount = round(abs((float) $line->amount), 2);

        $byAmount = array_values(array_filter(
            $pool,
            fn (array $candidate): bool => ! isset($claimed[$candidate['journal_line_id']])
                && $candidate['direction'] === $direction
                && abs($candidate['amount'] - $amount) <= self::TOLERANCE,
        ));

        if ($byAmount === []) {
            return ['candidate' => null, 'method' => null, 'ambiguous' => false];
        }

        $ambiguous = false;

        $signals = ['external_id' => $line->external_id, 'reference' => $line->reference];

        foreach ($signals as $method => $token) {
            $needle = $this->normalizeToken($token);

            if ($needle === '') {
                continue;
            }

            $hits = $this->filter($byAmount, fn (array $c): bool => in_array($needle, $c['tokens'], true));

            if (count($hits) === 1) {
                return ['candidate' => $hits[0], 'method' => $method, 'ambiguous' => false];
            }

            $ambiguous = $ambiguous || count($hits) > 1;
        }

        // Keterangan bank sering memuat nomor bukti kita ("TRF-202609-00012").
        if (mb_strlen($line->description ?? '') >= 8) {
            $haystack = $this->normalizeToken($line->description);

            $hits = $this->filter($byAmount, fn (array $c): bool => $c['document_number'] !== null
                && mb_strlen($c['document_number']) >= 6
                && str_contains($haystack, $c['document_number']));

            if (count($hits) === 1) {
                return ['candidate' => $hits[0], 'method' => 'document_number', 'ambiguous' => false];
            }

            $ambiguous = $ambiguous || count($hits) > 1;
        }

        if ($line->line_date === null) {
            return ['candidate' => null, 'method' => null, 'ambiguous' => $ambiguous];
        }

        $exact = $this->filter($byAmount, fn (array $c): bool => $c['transaction_date'] === $line->line_date->toDateString());

        if (count($exact) === 1) {
            return ['candidate' => $exact[0], 'method' => 'amount_date', 'ambiguous' => false];
        }

        $ambiguous = $ambiguous || count($exact) > 1;

        if ($windowDays > 0) {
            $near = $this->filter($byAmount, fn (array $c): bool => $this->withinWindow($c['transaction_date'], $line->line_date, $windowDays));

            if (count($near) === 1) {
                return ['candidate' => $near[0], 'method' => 'amount', 'ambiguous' => false];
            }
        }

        return ['candidate' => null, 'method' => null, 'ambiguous' => $ambiguous];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    protected function filter(array $candidates, callable $predicate): array
    {
        return array_values(array_filter($candidates, $predicate));
    }

    /**
     * Tandai satu baris statement menunjuk satu baris jurnal.
     *
     * @return bool false bila klaim dilewati karena baris jurnal sudah diklaim
     *              pemanggil lain (snapshot kandidat boleh basi, kuncinya tidak)
     */
    protected function claim(FinanceBankStatementLine $line, array $candidate, string $method, ?User $actor): bool
    {
        // Kunci baris jurnal sebelum menulis klaim. Dua pencocokan pada statement
        // berbeda boleh saja melihat kandidat yang sama dari snapshot yang sama;
        // kuncinya membuat yang datang kedua melihat klaim pertama dan mundur,
        // alih-alih memakainya dua kali (§5).
        $journal = FinancialTransactionLine::query()
            ->whereKey($candidate['journal_line_id'])
            ->lockForUpdate()
            ->first();

        if ($journal === null) {
            return false;
        }

        if (FinanceBankStatementLine::query()
            ->where('journal_line_id', $journal->id)
            ->where('id', '!=', $line->id)
            ->exists()) {
            return false;
        }

        $line->update([
            'status' => FinanceBankStatementLine::STATUS_MATCHED,
            'match_method' => $method,
            'journal_line_id' => $candidate['journal_line_id'],
            'matched_by' => $actor?->id,
            'matched_at' => Carbon::now(),
        ]);

        return true;
    }

    /** Baris jurnal hanya boleh ditunjuk satu baris statement (semua header). */
    protected function assertJournalLineUsable(
        FinancialTransactionLine $journal,
        FinanceBankStatement $statement,
        FinanceBankStatementLine $line,
    ): void {
        $journal->loadMissing('transaction');

        if (! in_array($journal->transaction->status, FinancialTransaction::LEDGER_STATUSES, true)) {
            throw ValidationException::withMessages(['journal_line_id' => 'Hanya baris jurnal posted/reversed yang bisa dicocokkan.']);
        }

        if ($journal->subledger_type === null) {
            throw ValidationException::withMessages([
                'journal_line_id' => 'Baris jurnal ini tidak menunjuk satu rekening kas/bank (pergerakan agregat), jadi tidak bisa dipakai pencocokan rekening koran.',
            ]);
        }

        if ($journal->subledger_type !== FinancialTransactionLine::SUBLEDGER_BANK
            || (int) $journal->subledger_id !== (int) $statement->bank_account_id) {
            throw ValidationException::withMessages(['journal_line_id' => 'Baris jurnal bukan pergerakan rekening bank pada rekening koran ini.']);
        }

        $claimedBy = FinanceBankStatementLine::query()
            ->where('journal_line_id', $journal->id)
            ->where('id', '!=', $line->id)
            ->first();

        if ($claimedBy !== null) {
            throw ValidationException::withMessages([
                'journal_line_id' => 'Baris jurnal sudah dicocokkan ke baris rekening koran lain ('.$claimedBy->statement?->statement_number.').',
            ]);
        }
    }

    /**
     * Baris jurnal yang sudah diklaim statement lain.
     *
     * @return array<int, true>
     */
    protected function claimedJournalLineIds(?int $exceptStatementId = null): array
    {
        return FinanceBankStatementLine::query()
            ->whereNotNull('journal_line_id')
            ->when($exceptStatementId !== null, fn (Builder $q) => $q->where('bank_statement_id', '!=', $exceptStatementId))
            ->pluck('journal_line_id')
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();
    }

    protected function dateWindow(): int
    {
        return max(0, min(30, (int) $this->settings->get('reconciliation_date_tolerance_days', 3)));
    }

    protected function withinWindow(?string $journalDate, mixed $statementDate, int $windowDays): bool
    {
        if ($journalDate === null || ! ($statementDate instanceof Carbon)) {
            return true;
        }

        return abs(Carbon::parse($journalDate)->diffInDays($statementDate)) <= $windowDays;
    }

    /** Jarak tanggal kandidat ke tanggal baris statement; tanpa tanggal = paling jauh. */
    protected function dateDistance(?string $journalDate, ?Carbon $anchor): int
    {
        if ($journalDate === null) {
            return PHP_INT_MAX;
        }

        if ($anchor === null) {
            return 0;
        }

        return (int) abs(Carbon::parse($journalDate)->diffInDays($anchor));
    }

    protected function normalizeToken(mixed $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', '', (string) ($value ?? '')) ?? ''));
    }

    // ==================== Internal: statement ====================

    protected function lockStatement(FinanceBankStatement $statement): FinanceBankStatement
    {
        return FinanceBankStatement::query()->whereKey($statement->id)->lockForUpdate()->firstOrFail();
    }

    protected function assertEditable(FinanceBankStatement $statement): void
    {
        if (! $statement->isEditable()) {
            throw ValidationException::withMessages([
                'status' => "Rekening koran {$statement->statement_number} sudah {$statement->statusLabel()} dan tidak bisa diubah.",
            ]);
        }
    }

    protected function assertLineEditable(FinanceBankStatementLine $line): void
    {
        if ($line->status === FinanceBankStatementLine::STATUS_RECONCILED) {
            throw ValidationException::withMessages([
                'line' => 'Baris rekening koran yang sudah direkonsiliasi tidak bisa diubah.',
            ]);
        }
    }

    protected function markInReview(FinanceBankStatement $statement): void
    {
        if ($statement->status === FinanceBankStatement::STATUS_IMPORTED) {
            $statement->update(['status' => FinanceBankStatement::STATUS_IN_REVIEW]);
        }
    }

    /**
     * @return array{total: int, unmatched: int, matched: int, reconciled: int, excluded: int}
     */
    protected function counts(FinanceBankStatement $statement): array
    {
        $byStatus = $statement->lines
            ->groupBy(fn (FinanceBankStatementLine $line): string => $line->status)
            ->map(fn (Collection $group): int => $group->count());

        $fill = fn (string $status): int => (int) ($byStatus[$status] ?? 0);

        return [
            'total' => $statement->lines->count(),
            'unmatched' => $fill(FinanceBankStatementLine::STATUS_UNMATCHED),
            'matched' => $fill(FinanceBankStatementLine::STATUS_MATCHED),
            'reconciled' => $fill(FinanceBankStatementLine::STATUS_RECONCILED),
            'excluded' => $fill(FinanceBankStatementLine::STATUS_EXCLUDED),
        ];
    }

    protected function itemStatusFor(FinanceBankStatementLine $line): string
    {
        return match ($line->status) {
            FinanceBankStatementLine::STATUS_EXCLUDED => FinanceReconciliationItem::MATCH_IGNORED,
            FinanceBankStatementLine::STATUS_RECONCILED => FinanceReconciliationItem::MATCH_RECONCILED,
            default => FinanceReconciliationItem::MATCH_MATCHED,
        };
    }

    protected function periodStart(FinanceBankStatement $statement): ?string
    {
        $earliest = $statement->lines->pluck('line_date')->filter()->min();

        return $earliest === null ? null : $earliest->toDateString();
    }
}
