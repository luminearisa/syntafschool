<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceBankStatement;
use App\Models\FinanceBankStatementLine;
use App\Models\FinanceBankTransaction;
use App\Models\FinanceReconciliation;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Finance\FinanceSettingsService;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rekening koran bank + pencocokan + rekonsiliasi (Phase 4 §4) lewat HTTP.
 *
 * §4 menuntut alur Import → Parse → Detect → Match → Review → Reconcile →
 * Adjustment bila perlu, dan satu larangan yang jadi inti pengujian ini:
 * **mengimpor rekening koran tidak boleh membuat jurnal.** Karena itu jumlah
 * jurnal diuji sebelum/sesudah import — kalau ada yang bocor, test ini yang
 * pertama gagal.
 *
 * Yang dibuktikan selain itu: pencocokan memakai baris jurnal bertanda subledger
 * (§1) sehingga payment via bank yang tidak punya baris `finance_bank_transactions`
 * tetap tercocokkan; tiap tingkat sinyal (§4: reference, nomor dokumen,
 * nominal+tanggal, toleransi tanggal dari konfigurasi) menghasilkan `match_method`
 * yang terbaca; nominal ambigu dibiarkan belum cocok alih-alih ditebak; satu baris
 * jurnal tidak bisa diklaim dua baris statement; reconcile menolak rekening koran
 * yang belum selesai; dan selisih Book vs Bank hanya bisa ditutup lewat workflow
 * adjustment resmi (§51), bukan dengan mengedit angka di layar rekonsiliasi.
 */
class FinanceBankStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $email = str_replace('-', '.', $role).'@schoolerp.test';

        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function bank(string $code = 'BANK-OPS'): FinanceBankAccount
    {
        return FinanceBankAccount::query()->where('code', $code)->firstOrFail();
    }

    private function account(string $code): FinanceAccount
    {
        return FinanceAccount::query()->where('code', $code)->firstOrFail();
    }

    /** Saldo awal lewat API: jurnal opening ikut distempel, jadi buku & ledger sama. */
    private function openBank(float $amount, string $code = 'BANK-OPS'): void
    {
        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/bank-accounts/{$this->bank($code)->id}/opening-balance", [
            'amount' => $amount,
            'note' => 'Saldo awal bank',
        ])->assertOk();
    }

    /** Setoran bank (uang masuk) — menghasilkan jurnal Dr 1200 berstempel rekening. */
    private function deposit(float $amount, ?string $reference = null): FinancialTransaction
    {
        $this->actingAsRole('finance-officer');

        $id = (int) $this->postJson('/api/v1/finance/bank-transactions', [
            'bank_account_id' => $this->bank()->id,
            'type' => 'deposit',
            'amount' => $amount,
            'contra_account_id' => $this->account('4500')->id,
            'reference' => $reference,
            'description' => 'Setoran rekening koran',
        ])->assertCreated()->json('data.id');

        return FinancialTransaction::query()
            ->with('lines')
            ->where('reference_type', FinanceBankTransaction::class)
            ->where('reference_id', $id)
            ->firstOrFail();
    }

    /** Penarikan/biaya bank (uang keluar). */
    private function withdrawal(float $amount): FinancialTransaction
    {
        $this->actingAsRole('finance-officer');

        $id = (int) $this->postJson('/api/v1/finance/bank-transactions', [
            'bank_account_id' => $this->bank()->id,
            'type' => 'fee',
            'amount' => $amount,
            'contra_account_id' => $this->account('5600')->id,
            'description' => 'Biaya admin bank',
        ])->assertCreated()->json('data.id');

        return FinancialTransaction::query()
            ->with('lines')
            ->where('reference_type', FinanceBankTransaction::class)
            ->where('reference_id', $id)
            ->firstOrFail();
    }

    private function journalCount(): int
    {
        return FinancialTransaction::query()->count();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function importPayload(array $lines, array $overrides = []): array
    {
        $movement = round(array_sum(array_map(
            fn (array $line): float => (float) ($line['credit'] ?? 0) - (float) ($line['debit'] ?? 0),
            $lines,
        )), 2);

        return array_merge([
            'bank_account_id' => $this->bank()->id,
            'statement_date' => Carbon::today()->toDateString(),
            'opening_balance' => 1000000,
            'closing_balance' => round(1000000 + $movement, 2),
            'source' => 'csv',
            'external_reference' => 'CORAN-2026-09',
            'filename' => 'coran-september.csv',
            'lines' => $lines,
        ], $overrides);
    }

    private function importAsOfficer(array $lines, array $overrides = []): int
    {
        $this->actingAsRole('finance-officer');

        return (int) $this->postJson('/api/v1/finance/bank-statements', $this->importPayload($lines, $overrides))
            ->assertCreated()
            ->json('data.id');
    }

    /** @return Collection<int, FinanceBankStatementLine> */
    private function lines(int $statementId)
    {
        return FinanceBankStatementLine::query()
            ->where('bank_statement_id', $statementId)
            ->orderBy('sequence')
            ->get();
    }

    private function line(int $statementId, int $index): FinanceBankStatementLine
    {
        return $this->lines($statementId)[$index];
    }

    // =================================================================
    // §4 — import adalah data bank, bukan jurnal
    // =================================================================

    public function test_import_rekening_koran_tidak_membuat_jurnal(): void
    {
        $this->openBank(1000000);
        $before = $this->journalCount();

        $this->actingAsRole('finance-officer');
        $response = $this->postJson('/api/v1/finance/bank-statements', $this->importPayload([
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 250000, 'description' => 'Setoran SPP', 'external_id' => 'BK-1'],
            ['line_date' => Carbon::today()->toDateString(), 'debit' => 2500, 'description' => 'Biaya admin', 'external_id' => 'BK-2'],
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.source', 'csv')
            ->assertJsonPath('data.status', FinanceBankStatement::STATUS_IN_REVIEW);

        $this->assertMatchesRegularExpression('/^STMT-/', $response->json('data.statement_number'));
        $this->assertSame($before, $this->journalCount(), 'Import rekening koran tidak boleh membuat jurnal (§4).');

        $lines = $this->lines((int) $response->json('data.id'));
        $this->assertCount(2, $lines);
        // amount = credit - debit: arah mengikuti rekening koran, bukan buku kita.
        $this->assertEquals(250000.0, (float) $lines[0]->amount);
        $this->assertEquals(-2500.0, (float) $lines[1]->amount);
        // Running balance dihitung dari saldo awal ketika file tidak menyediakannya.
        $this->assertEquals(1250000.0, (float) $lines[0]->running_balance);
        $this->assertEquals(1247500.0, (float) $lines[1]->running_balance);
    }

    public function test_rekening_koran_yang_tidak_konsisten_ditolak(): void
    {
        $this->openBank(1000000);

        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/bank-statements', $this->importPayload(
            [['credit' => 250000, 'external_id' => 'BK-1']],
            ['closing_balance' => 1400000, 'external_reference' => 'CORAN-JELEK'],
        ))->assertStatus(422)->assertJsonValidationErrors('closing_balance');

        $this->assertSame(0, FinanceBankStatement::query()->count());
    }

    public function test_import_ulang_file_yang_sama_idempoten(): void
    {
        $this->openBank(1000000);
        $lines = [['line_date' => Carbon::today()->toDateString(), 'credit' => 250000, 'external_id' => 'BK-1']];

        $first = $this->importAsOfficer($lines);
        $second = $this->importAsOfficer($lines);

        $this->assertSame($first, $second, 'Rekening koran dengan referensi bank yang sama tidak boleh lahir dua kali.');
        $this->assertSame(1, FinanceBankStatement::query()->count());
        $this->assertSame(1, $this->lines($first)->count());
    }

    // =================================================================
    // §4 — tingkat pencocokan
    // =================================================================

    public function test_cocok_dari_nomor_jurnal_lewat_reference(): void
    {
        $this->openBank(1000000);
        $journal = $this->deposit(250000);

        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 250000, 'reference' => $journal->transaction_number],
        ]);

        $line = $this->line($statementId, 0);
        $this->assertSame(FinanceBankStatementLine::STATUS_MATCHED, $line->status);
        $this->assertSame('reference', $line->match_method);
        $this->assertSame(
            (int) $journal->lines->firstWhere('account_id', $this->account('1200')->id)->id,
            (int) $line->journal_line_id,
        );
    }

    public function test_cocok_dari_nomor_dokumen_di_keterangan_bank(): void
    {
        $this->openBank(1000000);
        $journal = $this->deposit(300000, 'BDG-SETOR-991');

        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 300000, 'description' => 'TransferSetor BdG-Setor-991 a/n Bendahara'],
        ]);

        $line = $this->line($statementId, 0);
        $this->assertSame(FinanceBankStatementLine::STATUS_MATCHED, $line->status);
        $this->assertSame('document_number', $line->match_method);
        $this->assertSame((int) $journal->id, (int) $line->journalLine->finance_transaction_id);
    }

    public function test_cocok_dari_nominal_dan_tanggal_sama(): void
    {
        $this->openBank(1000000);
        $this->deposit(250000);

        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 250000],
        ]);

        $this->assertSame('amount_date', $this->line($statementId, 0)->match_method);
    }

    public function test_toleransi_tanggal_datang_dari_konfigurasi(): void
    {
        $this->openBank(1000000);
        $this->deposit(250000);
        $this->deposit(275000);

        // Bank membukukan H+2 dari tanggal buku — masih di dalam default 3 hari,
        // jadi hanya nominal yang sama dan tanggalnya berdekatan.
        $longgar = $this->importAsOfficer([
            ['line_date' => Carbon::today()->addDays(2)->toDateString(), 'credit' => 250000],
        ], ['external_reference' => 'CORAN-TOL-LONGGAR']);
        $this->assertSame('amount', $this->line($longgar, 0)->match_method);

        // Toleransi 0 hari: selisih tanggal yang sama tidak boleh diterima lagi.
        app(FinanceSettingsService::class)->update(['reconciliation_date_tolerance_days' => 0]);
        $ketat = $this->importAsOfficer([
            ['line_date' => Carbon::today()->addDays(2)->toDateString(), 'credit' => 275000],
        ], ['external_reference' => 'CORAN-TOL-KETAT']);
        $this->assertSame(FinanceBankStatementLine::STATUS_UNMATCHED, $this->line($ketat, 0)->status);

        // Naikkan jadi 5 hari lalu jalankan ulang pencocokan: baris yang sama cocok.
        app(FinanceSettingsService::class)->update(['reconciliation_date_tolerance_days' => 5]);
        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/bank-statements/{$ketat}/auto-match")->assertOk();

        $this->assertSame('amount', $this->line($ketat, 0)->match_method);
    }

    public function test_nominal_ambigu_dibiarkan_belum_cocok(): void
    {
        $this->openBank(1000000);
        $this->deposit(500000);
        $this->deposit(500000);

        $this->actingAsRole('finance-officer');
        $response = $this->postJson('/api/v1/finance/bank-statements', $this->importPayload(
            [['line_date' => Carbon::today()->toDateString(), 'credit' => 500000]],
            ['external_reference' => 'CORAN-AMBIGU'],
        ))->assertCreated();

        $statementId = (int) $response->json('data.id');

        $line = $this->line($statementId, 0);
        $this->assertSame(FinanceBankStatementLine::STATUS_UNMATCHED, $line->status);
        $this->assertNull($line->journal_line_id, 'Dua kandidat dengan angka sama tidak boleh ditebak (§4).');
        $this->assertSame(1, (int) $response->json('data.review.counts.unmatched'));
        $this->assertSame(1, (int) $response->json('data.review.counts.total'));
        $this->assertSame(500000.0, (float) $response->json('data.review.unmatched_amount'));
        $this->assertFalse((bool) $response->json('data.review.ready_to_reconcile'));
    }

    public function test_cocok_manual_satu_baris_jurnal(): void
    {
        $this->openBank(1000000);
        $journal = $this->withdrawal(2500);
        $journalLine = $journal->lines->firstWhere('account_id', $this->account('1200')->id);

        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->addDays(9)->toDateString(), 'debit' => 2500, 'description' => 'Admin bulanan'],
        ]);

        // Sengaja di luar toleransi tanggal: baris tetap unmatched dan kandidat
        // ditawarkan ke manusia, bukan dipaksakan otomatis.
        $this->assertSame(FinanceBankStatementLine::STATUS_UNMATCHED, $this->line($statementId, 0)->status);

        $lineId = (int) $this->line($statementId, 0)->id;
        $this->actingAsRole('finance-officer');
        $this->getJson("/api/v1/finance/bank-statement-lines/{$lineId}/candidates")
            ->assertOk()
            ->assertJsonPath('data.candidates.0.journal_line_id', (int) $journalLine->id);

        $this->postJson("/api/v1/finance/bank-statement-lines/{$lineId}/match", [
            'journal_line_id' => (int) $journalLine->id,
        ])->assertOk()->assertJsonPath('data.match_method', 'manual');
    }

    public function test_mencocokkan_dengan_nominal_berbeda_ditolak(): void
    {
        $this->openBank(1000000);
        $journal = $this->withdrawal(2500);
        $journalLine = $journal->lines->firstWhere('account_id', $this->account('1200')->id);

        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->toDateString(), 'debit' => 3000, 'description' => 'Admin bank'],
        ]);

        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/bank-statements/'.$statementId.'/lines', [
            'line_date' => Carbon::today()->addDays(30)->toDateString(),
            'debit' => 2500,
            'description' => 'Admin bank (baris kedua, tidak tercocok otomatis)',
        ])->assertCreated();

        $secondLineId = (int) $this->lines($statementId)->last()->id;

        // Arah baris statement ini keluar (debit) dan nominalnya sama dengan
        // baris jurnal — yang berbeda hanya tanggal, jadi match manual sah.
        $this->postJson("/api/v1/finance/bank-statement-lines/{$secondLineId}/match", [
            'journal_line_id' => (int) $journalLine->id,
        ])->assertOk();

        // Baris pertama (3000) tidak boleh menunjuk jurnal 2500.
        $this->postJson('/api/v1/finance/bank-statement-lines/'
            .(int) $this->line($statementId, 0)->id.'/match', [
                'journal_line_id' => (int) $journalLine->id,
            ])->assertStatus(422)->assertJsonValidationErrors('journal_line_id');
    }

    public function test_satu_baris_jurnal_hanya_bisa_dicocokkan_sekali(): void
    {
        $this->openBank(1000000);
        $journal = $this->deposit(250000);
        $journalLine = $journal->lines->firstWhere('account_id', $this->account('1200')->id);

        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 250000, 'external_id' => 'BK-A'],
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 250000, 'external_id' => 'BK-B'],
        ]);

        $lines = $this->lines($statementId);
        $this->assertSame(1, $lines->where('status', FinanceBankStatementLine::STATUS_MATCHED)->count());
        $this->assertSame(FinanceBankStatementLine::STATUS_UNMATCHED, $lines[1]->status);

        // Klaim ganda lewat match manual juga ditolak.
        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/bank-statement-lines/'.(int) $lines[1]->id.'/match', [
            'journal_line_id' => (int) $journalLine->id,
        ])->assertStatus(422)->assertJsonValidationErrors('journal_line_id');
    }

    // =================================================================
    // §4 — review, exclude, detect
    // =================================================================

    public function test_mengecualikan_baris_wajib_alasan(): void
    {
        $this->openBank(1000000);

        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->toDateString(), 'debit' => 100000, 'description' => 'Mutasi unit lain'],
        ], ['external_reference' => 'CORAN-EXCL']);

        $lineId = (int) $this->line($statementId, 0)->id;
        $this->actingAsRole('finance-officer');

        $this->postJson("/api/v1/finance/bank-statement-lines/{$lineId}/exclude", [])->assertStatus(422);

        $this->postJson("/api/v1/finance/bank-statement-lines/{$lineId}/exclude", [
            'reason' => 'Potongan unit lain di rekening bersama',
        ])->assertOk()->assertJsonPath('data.status', FinanceBankStatementLine::STATUS_EXCLUDED);

        $this->assertSame('Potongan unit lain di rekening bersama', $this->line($statementId, 0)->notes);
    }

    public function test_pergerakan_buku_yang_tidak_ada_di_koran_terlihat(): void
    {
        $this->openBank(1000000);
        $this->deposit(250000);
        $this->deposit(125000);

        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 250000, 'external_id' => 'BK-1'],
        ], ['external_reference' => 'CORAN-DETECT']);

        $this->actingAsRole('finance-officer');
        $unclaimedResponse = $this->getJson("/api/v1/finance/bank-statements/{$statementId}/unclaimed")
            ->assertOk();
        $unclaimed = $unclaimedResponse->json('data.rows');

        // Saldo awal (1.000.000) dan setoran 125.000 tidak ada di koran; setoran
        // 250.000 sudah diklaim baris statement sehingga tidak muncul lagi.
        $amounts = array_map(fn (array $row): float => (float) $row['amount'], $unclaimed);
        $this->assertContains(125000.0, $amounts);
        $this->assertContains(1000000.0, $amounts);
        $this->assertNotContains(250000.0, $amounts);
        $this->assertSame(2, (int) $unclaimedResponse->json('data.totals.lines'));
        $this->assertEquals(1125000.0, (float) $unclaimedResponse->json('data.totals.in'));
        $this->assertEquals(0.0, (float) $unclaimedResponse->json('data.totals.out'));

        $summary = $this->getJson("/api/v1/finance/bank-statements/{$statementId}/summary")
            ->assertOk()
            ->json('data');

        // Book balance dibaca dari rekening, bukan disalin ke statement (§1).
        $this->assertEquals(1375000.0, (float) $summary['book_balance']);
        $this->assertEquals(1250000.0, (float) $summary['bank_balance']);
        $this->assertEquals(-125000.0, (float) $summary['difference']);

        // "Siap rekonsiliasi" mengukur baris yang sudah direview, bukan selisih
        // saldo: angkanya boleh tidak cocok dan justru itu yang harus terlihat
        // sebelum direkonsiliasi (§45).
        $this->assertTrue($summary['ready_to_reconcile']);
    }

    // =================================================================
    // §4/§45 — reconcile
    // =================================================================

    public function test_reconcile_menolak_rekening_koran_yang_belum_selesai(): void
    {
        $this->openBank(1000000);

        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 250000, 'external_id' => 'BK-1'],
        ], ['external_reference' => 'CORAN-BELUM']);

        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/bank-statements/{$statementId}/reconcile")
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');

        $this->assertSame(0, FinanceReconciliation::query()->count());
    }

    public function test_reconcile_selisih_nol_menutup_baris_dan_statement(): void
    {
        $this->openBank(1000000);
        $this->deposit(250000);
        $this->withdrawal(2500);

        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 250000, 'description' => 'Setoran SPP', 'external_id' => 'BK-1'],
            ['line_date' => Carbon::today()->toDateString(), 'debit' => 2500, 'description' => 'Biaya admin', 'external_id' => 'BK-2'],
        ], ['external_reference' => 'CORAN-OK']);

        $this->assertSame(0, $this->lines($statementId)->where('status', FinanceBankStatementLine::STATUS_UNMATCHED)->count());

        $this->actingAsRole('finance-officer');
        $reconId = (int) $this->postJson("/api/v1/finance/bank-statements/{$statementId}/reconcile")
            ->assertCreated()
            ->assertJsonPath('data.status', FinanceReconciliation::STATUS_RECONCILED)
            ->assertJsonPath('data.difference', 0)
            ->assertJsonPath('data.bank_statement_id', $statementId)
            ->json('data.id');

        $this->assertSame(FinanceBankStatement::STATUS_RECONCILED, FinanceBankStatement::find($statementId)->status);
        $this->assertSame(
            2,
            $this->lines($statementId)->where('status', FinanceBankStatementLine::STATUS_RECONCILED)->count(),
        );

        // Rantai §28: item rekonsiliasi menunjuk baris statement DAN baris jurnal.
        $items = $this->getJson("/api/v1/finance/reconciliations/{$reconId}")
            ->assertOk()
            ->json('data.items');

        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertNotNull($item['bank_statement_line_id']);
            $this->assertNotNull($item['journal_line_id']);
            $this->assertSame('reconciled', $item['match_status']);
        }

        // Rekonsiliasi final tidak bisa diubah (§45).
        $this->postJson("/api/v1/finance/bank-statements/{$statementId}/reconcile")
            ->assertStatus(422)->assertJsonValidationErrors('status');

        // Saldo rekening tetap terbukti dari buku besar (§1).
        $row = $this->getJson('/api/v1/finance/subledgers')->assertOk()->json('data.rows');
        $bankRow = collect($row)->firstWhere('code', $this->bank()->code);
        $this->assertEquals(0.0, (float) $bankRow['difference']);
    }

    public function test_selisih_ditutup_lewat_adjustment_resmi_bukan_lewat_edit(): void
    {
        $this->openBank(1000000);
        $this->deposit(250000);

        // Bank memotong biaya admin yang belum kita catat sama sekali. Barisnya
        // tidak punya pasangan di buku, jadi ia dikecualikan dengan alasan — dan
        // justru di situlah selisih Book vs Bank terlihat (§4).
        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 250000, 'description' => 'Setoran SPP', 'external_id' => 'BK-1'],
            ['line_date' => Carbon::today()->toDateString(), 'debit' => 2500, 'description' => 'Biaya admin belum dicatat', 'external_id' => 'BK-2'],
        ], ['external_reference' => 'CORAN-SELISIH']);

        $this->assertSame('amount_date', $this->line($statementId, 0)->match_method);
        $this->assertSame(FinanceBankStatementLine::STATUS_UNMATCHED, $this->line($statementId, 1)->status);

        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/bank-statement-lines/'.(int) $this->line($statementId, 1)->id.'/exclude', [
            'reason' => 'Biaya admin bank yang belum dibukukan',
        ])->assertOk();

        $reconId = (int) $this->postJson("/api/v1/finance/bank-statements/{$statementId}/reconcile")
            ->assertCreated()
            ->assertJsonPath('data.status', FinanceReconciliation::STATUS_DISCREPANCY)
            ->assertJsonPath('data.difference', -2500)
            ->json('data.id');

        // Statement tetap terbuka dan barisnya tidak naik ke reconciled.
        $this->assertSame(FinanceBankStatement::STATUS_IN_REVIEW, FinanceBankStatement::find($statementId)->status);
        $this->assertSame(
            1,
            $this->lines($statementId)->where('status', FinanceBankStatementLine::STATUS_MATCHED)->count(),
        );

        // Buku tidak bisa "disamakan" lewat layar rekonsiliasi. Jalurnya workflow
        // adjustment: ajukan (officer) → setujui + posting (manager) (§51, §15).
        $this->actingAsRole('finance-officer');
        $adjustmentId = (int) $this->postJson('/api/v1/finance/adjustments', [
            'account_id' => $this->account('1200')->id,
            'contra_account_id' => $this->account('5600')->id,
            'bank_account_id' => $this->bank()->id,
            'direction' => 'decrease',
            'amount' => 2500,
            'submit' => true,
            'reason' => 'Biaya admin bank dari rekening koran (rekonsiliasi '.$reconId.')',
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/adjustments/{$adjustmentId}/approve")->assertOk();
        $this->postJson("/api/v1/finance/adjustments/{$adjustmentId}/post")->assertOk();

        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/bank-statements/{$statementId}/reconcile")
            ->assertCreated()
            ->assertJsonPath('data.status', FinanceReconciliation::STATUS_RECONCILED)
            ->assertJsonPath('data.difference', 0);

        $this->assertSame(FinanceBankStatement::STATUS_RECONCILED, FinanceBankStatement::find($statementId)->status);
        $this->assertSame(
            1,
            $this->lines($statementId)->where('status', FinanceBankStatementLine::STATUS_RECONCILED)->count(),
        );
    }

    public function test_baris_yang_sudah_rekonsiliasi_tidak_bisa_diubah(): void
    {
        $this->openBank(1000000);
        $this->deposit(250000);

        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 250000, 'external_id' => 'BK-1'],
        ], ['external_reference' => 'CORAN-FINAL']);

        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/bank-statements/{$statementId}/reconcile")->assertCreated();

        $lineId = (int) $this->line($statementId, 0)->id;

        $this->patchJson("/api/v1/finance/bank-statement-lines/{$lineId}", ['credit' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->postJson("/api/v1/finance/bank-statements/{$statementId}/auto-match")
            ->assertOk();

        $this->assertEquals(250000.0, (float) $this->line($statementId, 0)->credit);
    }

    // =================================================================
    // §15 — pemisahan tugas & permission
    // =================================================================

    public function test_kasir_tidak_bisa_mengimpor_atau_merekonsiliasi(): void
    {
        $this->openBank(1000000);

        $this->actingAsRole('kasir');
        $this->getJson('/api/v1/finance/bank-statements')->assertStatus(403);
        $this->postJson('/api/v1/finance/bank-statements', $this->importPayload([
            ['credit' => 250000, 'external_id' => 'BK-1'],
        ]))->assertStatus(403);
    }

    public function test_auditor_baca_saja_rekening_koran(): void
    {
        $this->openBank(1000000);
        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->toDateString(), 'debit' => 2500, 'external_id' => 'BK-1'],
        ], ['external_reference' => 'CORAN-AUDIT']);

        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/bank-statements')->assertOk();
        $this->getJson("/api/v1/finance/bank-statements/{$statementId}")->assertOk();
        $this->postJson("/api/v1/finance/bank-statements/{$statementId}/reconcile")->assertStatus(403);
    }

    public function test_pencatat_mengimpor_penyetuju_merekonsiliasi(): void
    {
        // Pencatat (officer) punya bank.manage; penyetuju (manager) tidak — ia
        // yang menutup rekonsiliasi. Satu orang tidak dua-duanya (§15).
        $this->openBank(1000000);
        $this->deposit(250000);

        $statementId = $this->importAsOfficer([
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 250000, 'external_id' => 'BK-1'],
        ], ['external_reference' => 'CORAN-HAK-AKSES']);

        $this->actingAsRole('finance-manager');
        $this->postJson('/api/v1/finance/bank-statements', $this->importPayload([
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 250000],
        ], ['external_reference' => 'CORAN-MANAGER']))->assertStatus(403);

        $reconcile = $this->postJson("/api/v1/finance/bank-statements/{$statementId}/reconcile")
            ->assertCreated()
            ->assertJsonPath('data.status', FinanceReconciliation::STATUS_RECONCILED)
            ->assertJsonPath('data.bank_statement_id', $statementId)
            ->assertJsonPath('data.statement_status', FinanceBankStatement::STATUS_RECONCILED);

        // Rantai balik ke rekening koran sumbernya ikut terbaca di respons (§28).
        $this->assertMatchesRegularExpression('/^STMT-/', (string) $reconcile->json('data.bank_statement_number'));
    }

    public function test_import_mencatat_audit_trail_dengan_aktor(): void
    {
        $this->openBank(1000000);
        $actor = $this->actingAsRole('finance-officer');

        $id = (int) $this->postJson('/api/v1/finance/bank-statements', $this->importPayload([
            ['line_date' => Carbon::today()->toDateString(), 'credit' => 250000, 'external_id' => 'BK-1'],
        ], ['external_reference' => 'CORAN-ACTOR']))->assertCreated()->json('data.id');

        $this->assertSame((int) $actor->id, (int) FinanceBankStatement::find($id)->created_by);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'finance',
            'subject_type' => FinanceBankStatement::class,
            'subject_id' => $id,
            'event' => 'FINANCE_BANK_STATEMENT_CREATED',
        ]);
    }
}
