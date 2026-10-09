<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceCashTransaction;
use App\Models\FinancePeriod;
use App\Models\FinancialTransaction;
use App\Models\FinancialTransactionLine;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Transaksi kas standalone (Phase 4 §2) lewat HTTP.
 *
 * §2 melarang satu bentuk kegagalan: angka saldo berubah tanpa jurnal. Karena
 * service tidak pernah menulis `current_balance` (hanya ledger engine yang
 * boleh), yang diuji di sini adalah KONSEKUENSI accounting-nya: setiap kas
 * masuk/keluar menghasilkan jurnal seimbang pada COA kas (1100) melawan akun
 * dokumen itu, baris jurnalnya distempel rekening yang bergerak (§1), dan saldo
 * cached bisa dihitung ulang dari buku besar sampai selisih nol.
 *
 * Yang dijaga juga: akun lawan tidak boleh berupa rekening uang (kas/bank) —
 * memindah uang antar rekening adalah transfer dan punya workflow sendiri (§3);
 * uang masuk yang lawannya kewajiban bukan pendapatan (§27); kegagalan di tengah
 * jalan tidak meninggalkan dokumen maupun jurnal separuh (§49); dan pencatat kas
 * tidak otomatis berhak menutup periode (§15 pemisahan tugas).
 */
class FinanceCashTransactionTest extends TestCase
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
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function cash(string $code = 'KAS-SEKOLAH'): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', $code)->firstOrFail();
    }

    private function account(string $code): FinanceAccount
    {
        return FinanceAccount::query()->where('code', $code)->firstOrFail();
    }

    private function openCash(float $amount, string $code = 'KAS-SEKOLAH'): void
    {
        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/cash-accounts/{$this->cash($code)->id}/opening-balance", [
            'amount' => $amount,
            'note' => 'Saldo awal kas',
        ])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'cash_account_id' => $this->cash()->id,
            'type' => FinanceCashTransaction::TYPE_CASH_IN,
            'amount' => 75000,
            'contra_account_id' => $this->account('4500')->id,
            'reference' => 'SLIP-001',
            'description' => 'Setoran penerimaan lain-lain',
        ], $overrides);
    }

    private function journalFor(int $cashTransactionId): FinancialTransaction
    {
        return FinancialTransaction::query()
            ->with('lines.account')
            ->where('reference_type', FinanceCashTransaction::class)
            ->where('reference_id', $cashTransactionId)
            ->firstOrFail();
    }

    /** @return array<string, FinancialTransactionLine> keyed by kode COA */
    private function linesByCode(FinancialTransaction $journal): array
    {
        return $journal->lines->keyBy(fn (FinancialTransactionLine $line): string => $line->account->code)->all();
    }

    private function journalCount(): int
    {
        return FinancialTransaction::query()->count();
    }

    // =================================================================
    // §2 — kas masuk/keluar selalu bermuara ke jurnal
    // =================================================================

    public function test_kas_masuk_memposting_jurnal_dan_menaikkan_saldo_rekening(): void
    {
        $this->openCash(200000);
        $journalsBefore = $this->journalCount();

        $this->actingAsRole('finance-officer');
        $response = $this->postJson('/api/v1/finance/cash-transactions', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.type', FinanceCashTransaction::TYPE_CASH_IN)
            ->assertJsonPath('data.type_label', 'Kas Masuk')
            ->assertJsonPath('data.direction', 'in');

        $this->assertEquals(75000.0, (float) $response->json('data.amount'));

        $number = (string) $response->json('data.transaction_number');
        $this->assertMatchesRegularExpression('/^CSH-\d{6}-\d{5}$/', $number);

        $document = FinanceCashTransaction::query()->firstOrFail();
        $this->assertSame($number, $document->transaction_number);
        $this->assertNotNull($document->finance_transaction_id);

        // Saldo bergerak +75.000 karena jurnal, bukan UPDATE saldo (§1, §2).
        $this->assertEquals(275000.0, (float) $this->cash()->fresh()->current_balance);
        $this->assertSame($journalsBefore + 1, $this->journalCount());

        $journal = $this->journalFor((int) $document->id);
        $this->assertSame(FinancialTransaction::TYPE_REVENUE, $journal->type);
        $this->assertEquals(round((float) $journal->total_debit, 2), round((float) $journal->total_credit, 2));
        $this->assertSame($document->id, (int) $journal->reference_id);

        $lines = $this->linesByCode($journal);
        // Dr 1100 Kas (control account COA), Cr 4500 pendapatan lain-lain.
        $this->assertEquals(75000.0, (float) $lines['1100']->debit);
        $this->assertEquals(0.0, (float) $lines['1100']->credit);
        $this->assertEquals(75000.0, (float) $lines['4500']->credit);
    }

    public function test_baris_jurnal_kas_distempel_rekening_yang_bergerak(): void
    {
        $this->openCash(200000);

        $this->actingAsRole('finance-officer');
        $document = $this->postJson('/api/v1/finance/cash-transactions', $this->payload())->assertStatus(201)->json('data');

        $lines = $this->linesByCode($this->journalFor((int) $document['id']));

        // Baris pada COA kas menunjuk laci konkretnya → cached balance terbukti
        // dari buku besar (§1), bukan kolom yang dipercaya apa adanya.
        $this->assertSame(FinancialTransactionLine::SUBLEDGER_CASH, $lines['1100']->subledger_type);
        $this->assertSame($this->cash()->id, (int) $lines['1100']->subledger_id);

        // Akun lawan bukan rekening → tidak boleh ada stempel.
        $this->assertNull($lines['4500']->subledger_type);
        $this->assertNull($lines['4500']->subledger_id);
    }

    public function test_kas_keluar_mengurangi_saldo_dan_memposting_debit_beban(): void
    {
        $this->openCash(200000);

        $this->actingAsRole('finance-officer');
        $document = $this->postJson('/api/v1/finance/cash-transactions', $this->payload([
            'type' => FinanceCashTransaction::TYPE_CASH_OUT,
            'amount' => 50000,
            'contra_account_id' => $this->account('5200')->id,
            'description' => 'Pembelian ATK tunai',
        ]))->assertStatus(201)->json('data');

        $this->assertEquals(150000.0, (float) $this->cash()->fresh()->current_balance);
        $this->assertSame('out', $document['direction']);

        $journal = $this->journalFor((int) $document['id']);
        $this->assertSame(FinancialTransaction::TYPE_EXPENSE, $journal->type);

        $lines = $this->linesByCode($journal);
        $this->assertEquals(50000.0, (float) $lines['5200']->debit);
        $this->assertEquals(50000.0, (float) $lines['1100']->credit);
        $this->assertSame($this->cash()->id, (int) $lines['1100']->subledger_id);
    }

    public function test_kas_masuk_lawan_hutang_bukan_pendapatan(): void
    {
        // §27: uang diterima belum tentu revenue — lawannya kewajiban (titipan).
        $this->openCash(100000);

        $this->actingAsRole('finance-officer');
        $document = $this->postJson('/api/v1/finance/cash-transactions', $this->payload([
            'amount' => 30000,
            'contra_account_id' => $this->account('2200')->id,
            'description' => 'Titipan uang saku santri',
        ]))->assertStatus(201)->json('data');

        $journal = $this->journalFor((int) $document['id']);
        $this->assertSame(FinancialTransaction::TYPE_PAYMENT, $journal->type);

        $types = $journal->lines->map(fn (FinancialTransactionLine $line): string => (string) $line->account->type);

        $this->assertSame(['asset', 'liability'], $types->all());
        $this->assertNotContains('revenue', $types->all(), 'Titipan tidak boleh mengkredit pendapatan.');
        $this->assertEquals(30000.0, (float) $this->account('2200')->lines()->sum('credit'));
    }

    // =================================================================
    // §3 — kas/bank tidak boleh jadi jalur pintas transfer
    // =================================================================

    public function test_akun_lawan_berupa_rekening_kas_ditolak(): void
    {
        $this->openCash(100000);
        $journalsBefore = $this->journalCount();

        $this->actingAsRole('finance-officer');
        $response = $this->postJson('/api/v1/finance/cash-transactions', $this->payload([
            'contra_account_id' => $this->account('1100')->id,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('contra_account_id');
        $this->assertStringContainsString(
            '/finance/transfers',
            (string) $response->json('errors.contra_account_id.0'),
        );

        $this->assertSame(0, FinanceCashTransaction::query()->count());
        $this->assertSame($journalsBefore, $this->journalCount());
        $this->assertEquals(100000.0, (float) $this->cash()->fresh()->current_balance);
    }

    // =================================================================
    // §49 — kegagalan tidak boleh meninggalkan jejak separuh
    // =================================================================

    public function test_penarikan_melebihi_saldo_ditolak_tanpa_meninggalkan_jejak(): void
    {
        $this->openCash(100000);
        $journalsBefore = $this->journalCount();

        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/cash-transactions', $this->payload([
            'type' => FinanceCashTransaction::TYPE_CASH_OUT,
            'amount' => 150000,
            'contra_account_id' => $this->account('5200')->id,
        ]))->assertStatus(422);

        $this->assertSame(0, FinanceCashTransaction::query()->count());
        $this->assertSame($journalsBefore, $this->journalCount());
        $this->assertEquals(100000.0, (float) $this->cash()->fresh()->current_balance);
    }

    public function test_transaksi_kas_tidak_bisa_masuk_periode_closed(): void
    {
        $this->openCash(100000);
        $journalsBefore = $this->journalCount();

        $period = FinancePeriod::query()
            ->whereDate('start_date', '<=', Carbon::today())
            ->whereDate('end_date', '>=', Carbon::today())
            ->firstOrFail();

        // Pencatat kas tidak berhak menutup periode (§15 pemisahan tugas).
        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/periods/{$period->id}/close")->assertStatus(403);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/periods/{$period->id}/close")->assertOk();

        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/cash-transactions', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $this->assertSame(0, FinanceCashTransaction::query()->count());
        $this->assertSame($journalsBefore, $this->journalCount());
        $this->assertEquals(100000.0, (float) $this->cash()->fresh()->current_balance);
    }

    // =================================================================
    // §1 — saldo = opening + mutasi buku besar, per rekening
    // =================================================================

    public function test_saldo_kas_dapat_dihitung_ulang_dari_buku_besar(): void
    {
        $this->openCash(500000);

        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/cash-transactions', $this->payload(['amount' => 120000]))->assertStatus(201);
        $this->postJson('/api/v1/finance/cash-transactions', $this->payload([
            'type' => FinanceCashTransaction::TYPE_CASH_OUT,
            'amount' => 45000,
            'contra_account_id' => $this->account('5200')->id,
        ]))->assertStatus(201);

        $cash = $this->cash()->fresh();
        $this->assertEquals(575000.0, (float) $cash->current_balance);

        $this->actingAsRole('finance-admin');
        $reconciliation = $this->getJson('/api/v1/finance/subledgers')->assertOk()->json('data');

        $row = collect($reconciliation['rows'])
            ->first(fn (array $r): bool => $r['type'] === 'cash' && $r['id'] === (int) $cash->id);

        $this->assertNotNull($row);
        $this->assertEquals(575000.0, (float) $row['current_balance']);
        // Opening 500k + masuk 120k − keluar 45k, semuanya baris jurnal berstempel.
        $this->assertEquals(575000.0, (float) $row['ledger_balance']);
        $this->assertEquals(0.0, (float) $row['difference']);
        $this->assertSame(3, $row['journal_lines']);
        $this->assertTrue($reconciliation['in_agreement']);
        $this->assertEquals(575000.0, (float) $reconciliation['totals']['current_balance']);
        $this->assertEquals(0.0, (float) $reconciliation['totals']['difference']);

        $movement = $this->getJson("/api/v1/finance/subledgers/cash/{$cash->id}/movements")
            ->assertOk()
            ->json('data');

        $this->assertEquals(620000.0, (float) $movement['totals']['debit']);
        $this->assertEquals(45000.0, (float) $movement['totals']['credit']);
        $this->assertEquals(575000.0, (float) $movement['totals']['closing_balance']);

        $rows = $movement['rows'];
        $this->assertCount(3, $rows);
        $this->assertSame(['1100', '1100', '1100'], array_column($rows, 'account_code'));
        $this->assertEquals(
            [500000.0, 620000.0, 575000.0],
            array_map(static fn (array $r): float => round((float) $r['running_balance'], 2), $rows),
        );

        // Setiap pergerakan punya nomor jurnal → bisa ditelusuri (§38).
        foreach ($rows as $line) {
            $this->assertMatchesRegularExpression('/^JRN-/', (string) $line['transaction_number']);
        }
    }

    public function test_mutasi_rekening_bisa_difilter_rentang_tanggal(): void
    {
        $this->openCash(500000);

        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/cash-transactions', $this->payload(['amount' => 100000]))->assertStatus(201);
        $cashOut = $this->postJson('/api/v1/finance/cash-transactions', $this->payload([
            'type' => FinanceCashTransaction::TYPE_CASH_OUT,
            'amount' => 20000,
            'contra_account_id' => $this->account('5200')->id,
        ]))->assertStatus(201)->json('data');

        $cashId = $this->cash()->id;
        $journal = $this->journalFor((int) $cashOut['id']);
        $this->actingAsRole('finance-admin');

        // Rentang yang memuat semuanya: opening + masuk − keluar.
        $all = $this->getJson("/api/v1/finance/subledgers/cash/{$cashId}/movements")
            ->assertOk()
            ->json('data');
        $this->assertCount(3, $all['rows']);
        $this->assertEquals(580000.0, (float) $all['totals']['closing_balance']);
        $this->assertContains($journal->transaction_number, array_column($all['rows'], 'transaction_number'));

        // Rentang yang sama sekali tidak memuat jurnal: barisnya kosong, tetapi
        // posisi rekening tetap saldo penuh — yang terfilter hanya mutasinya.
        $past = $this->getJson("/api/v1/finance/subledgers/cash/{$cashId}/movements?from=2020-01-01&to=2020-12-31")
            ->assertOk()
            ->json('data');

        $this->assertSame([], $past['rows']);
        $this->assertEquals(0.0, (float) $past['totals']['movement']);
        $this->assertEquals(580000.0, (float) $past['account']['current_balance']);
        $this->assertEquals(0.0, (float) $past['account']['difference']);
    }

    public function test_laporan_kas_bank_menampilkan_saldo_buku_besar_dan_selisih(): void
    {
        $this->openCash(250000);

        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/cash-transactions', $this->payload(['amount' => 25000]))->assertStatus(201);

        $this->actingAsRole('finance-admin');
        $rows = $this->getJson('/api/v1/finance/reports/cash_bank')->assertOk()->json('data.rows');

        $row = collect($rows)->first(fn (array $r): bool => $r['code'] === 'KAS-SEKOLAH');
        $this->assertNotNull($row);
        $this->assertEquals(275000.0, (float) $row['current_balance']);
        $this->assertEquals(275000.0, (float) $row['ledger_balance']);
        $this->assertEquals(0.0, (float) $row['difference']);
    }

    // =================================================================
    // §15, §38 — otorisasi dan penelusuran
    // =================================================================

    public function test_kasir_boleh_membaca_tetapi_tidak_mencatat_transaksi_kas(): void
    {
        $this->actingAsRole('kasir');
        $this->getJson('/api/v1/finance/cash-transactions')->assertOk();
        $this->postJson('/api/v1/finance/cash-transactions', $this->payload())->assertStatus(403);

        $this->assertSame(0, FinanceCashTransaction::query()->count());
        $this->assertSame(0, $this->journalCount());
    }

    public function test_dokumen_kas_dan_jurnal_saling_menunjuk_dalam_penelusuran(): void
    {
        $this->openCash(100000);

        $this->actingAsRole('finance-officer');
        $document = $this->postJson('/api/v1/finance/cash-transactions', $this->payload())->assertStatus(201)->json('data');

        $journal = $this->journalFor((int) $document['id']);

        $this->assertSame(
            (int) $journal->id,
            (int) FinanceCashTransaction::query()->whereKey($document['id'])->value('finance_transaction_id'),
        );

        $this->actingAsRole('finance-admin');
        $trail = $this->getJson("/api/v1/finance/journals/{$journal->id}/trace")->assertOk()->json('data.trace.trail');

        // Rantai §38: jurnal → dokumen sumbernya, dengan nomor dan nominal yang
        // sama — angka di laporan selalu bisa diturunkan ke transaksi kas ini.
        $this->assertSame(['journal', 'cash_transaction'], array_column($trail, 'stage'));
        $this->assertSame($journal->transaction_number, $trail[0]['number']);
        $this->assertSame($document['transaction_number'], $trail[1]['number']);
        $this->assertEquals(75000.0, (float) $trail[1]['amount']);
    }
}
