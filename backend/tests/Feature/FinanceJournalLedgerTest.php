<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinanceReceipt;
use App\Models\FinanceVendor;
use App\Models\FinancialTransaction;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Jurnal umum, buku besar, penelusuran dan hutang lewat HTTP (§4, §38, §47).
 *
 * Buku besar yang dibangun per skenario:
 *   opening kas 5.000.000 → invoice santri 850.000 (SPP 350k + asrama 500k) →
 *   bayar tunai 850.000 (+kuitansi) → invoice vendor A 1.100.000 (jatuh tempo
 *   10 Sep) dibayar sebagian 400.000 → vendor B 500.000 belum jatuh tempo →
 *   vendor C 200.000 dibayar lunas.
 *
 * Yang dijaga: `/finance/transactions` dan `/finance/journals` membaca himpunan
 * yang sama (jurnal = `finance_transactions`, tanpa tabel kedua §71) dengan hak
 * berbeda; daftar menutup di debit == kredit (§5); rantai §38 tembus dari JRN
 * sampai santri; buku besar berjalan pada sisi normal akun dan tidak dijumlahkan
 * lintas akun (§35–§37); saldo buku besar bisa disilangkan dengan subledger kas
 * (§27); daftar hutang sama dengan akun 2100 di buku besar (§30); jurnal posted
 * hanya bisa dibalik — tidak diedit/dihapus — dan pembalikan langsung dilarang
 * bila jurnal menggerakkan kas (§34, §41, §51).
 */
class FinanceJournalLedgerTest extends TestCase
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

    private function account(string $code): int
    {
        return (int) FinanceAccount::query()->where('code', $code)->value('id');
    }

    private function cash(): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', 'KAS-SEKOLAH')->firstOrFail();
    }

    private function student(string $nis): Student
    {
        return Student::query()->firstOrCreate(['nis' => $nis], ['full_name' => 'Santri '.$nis, 'is_active' => true]);
    }

    private function vendor(string $code): FinanceVendor
    {
        return FinanceVendor::query()->firstOrCreate(
            ['code' => $code],
            ['name' => 'Vendor '.$code, 'vendor_type' => FinanceVendor::TYPE_SUPPLIER, 'status' => FinanceVendor::STATUS_ACTIVE, 'is_active' => true],
        );
    }

    /** Invoice santri 850.000 (SPP 350k + asrama 500k) — jurnal akrual terbit. */
    private function issueInvoice(): FinanceInvoice
    {
        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => 'PKT-JRN'],
            ['name' => 'Paket Jurnal', 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $package->items()->createMany([
                ['fee_type_id' => FinanceFeeType::query()->where('code', 'SPP')->value('id'), 'amount' => 350000, 'frequency' => 'monthly', 'is_active' => true],
                ['fee_type_id' => FinanceFeeType::query()->where('code', 'UANG_ASRAMA')->value('id'), 'amount' => 500000, 'frequency' => 'monthly', 'is_active' => true],
            ]);
        }

        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => '2026-09',
            'student_ids' => [$this->student('JRN001')->id],
            'due_date' => '2026-09-30',
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::find(
            $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    /** Kas diisi lewat jurnal opening balance — satu-satunya cara sah (§27). */
    private function openCash(float $amount): void
    {
        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/cash-accounts/{$this->cash()->id}/opening-balance", [
            'amount' => $amount,
            'note' => 'Saldo awal kas',
        ])->assertOk();
    }

    /**
     * Alur piutang: opening kas, invoice terbit, dilunasi tunai (+kuitansi).
     *
     * @return array{invoice: FinanceInvoice, payment: int, accrual: int, settlement: int}
     */
    private function seedReceivableFlow(): array
    {
        $this->openCash(5000000);

        $invoice = $this->issueInvoice();

        $this->actingAsRole('finance-admin');
        $paymentId = (int) $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated()->json('data.id');

        return [
            'invoice' => $invoice,
            'payment' => $paymentId,
            'accrual' => $this->journalId(FinanceInvoice::class, (int) $invoice->id, FinancialTransaction::TYPE_INVOICE),
            'settlement' => $this->journalId(FinancePayment::class, $paymentId, FinancialTransaction::TYPE_PAYMENT),
        ];
    }

    private function receiveVendorInvoice(string $vendorCode, float $subtotal, float $tax, string $dueDate): int
    {
        $this->actingAsRole('finance-admin');

        return (int) $this->postJson('/api/v1/finance/vendor-invoices', [
            'vendor_id' => $this->vendor($vendorCode)->id,
            'invoice_date' => '2026-09-01',
            'due_date' => $dueDate,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'account_id' => $this->account('5200'),
        ])->assertCreated()->json('data.id');
    }

    private function payVendorInvoice(int $id, float $amount): void
    {
        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/vendor-invoices/{$id}/pay", [
            'amount' => $amount,
            'cash_account_id' => $this->cash()->id,
        ])->assertOk();
    }

    /**
     * Buku besar penuh: piutang lunas + tiga invoice vendor (sebagian, belum
     * jatuh tempo, lunas) sehingga hutang berjalan tinggal 1.200.000.
     *
     * @return array<string, mixed>
     */
    private function seedBooks(): array
    {
        $flow = $this->seedReceivableFlow();

        $overdue = $this->receiveVendorInvoice('V-JRN-A', 1000000, 100000, '2026-09-10');
        $this->payVendorInvoice($overdue, 400000);

        $current = $this->receiveVendorInvoice('V-JRN-B', 500000, 0, '2027-12-31');
        $settled = $this->receiveVendorInvoice('V-JRN-C', 200000, 0, '2026-09-05');
        $this->payVendorInvoice($settled, 200000);

        return $flow + ['ap_overdue' => $overdue, 'ap_current' => $current, 'ap_settled' => $settled];
    }

    private function journalId(string $referenceType, int $referenceId, string $type): int
    {
        return (int) FinancialTransaction::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('type', $type)
            ->where('status', FinancialTransaction::STATUS_POSTED)
            ->value('id');
    }

    /** Sidik jari baris jurnal: id akun + kedua sisinya (untuk uji imutabilitas). */
    private function lineFingerprint(FinancialTransaction $journal): string
    {
        return $journal->load('lines')->lines
            ->map(fn ($line): string => $line->account_id.':'.$line->debit.':'.$line->credit)
            ->implode('|');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function journalList(array $query = []): array
    {
        $this->actingAsRole('finance-admin');

        return $this->getJson('/api/v1/finance/journals'.($query ? '?'.http_build_query($query) : ''))
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function ledger(int $accountId = 0, array $query = []): array
    {
        $this->actingAsRole('finance-manager');
        $path = $accountId > 0 ? "/api/v1/finance/ledger/{$accountId}" : '/api/v1/finance/ledger';

        return $this->getJson($path.($query ? '?'.http_build_query($query) : ''))->assertOk()->json('data');
    }

    // =================================================================
    // §47 daftar & detail jurnal
    // =================================================================

    public function test_daftar_jurnal_menutup_di_sisi_debit_sama_dengan_kredit(): void
    {
        $this->seedBooks();

        $rows = $this->journalList(['all' => 1]);
        $this->assertCount(FinancialTransaction::query()->count(), $rows);

        foreach ($rows as $row) {
            $this->assertTrue($row['is_balanced'], "Jurnal {$row['transaction_number']} tidak seimbang");
            $this->assertSame($row['transaction_number'], $row['journal_number']);
            $this->assertSame(FinancialTransaction::STATUS_POSTED, $row['status']);
        }

        $this->actingAsRole('finance-admin');
        $totals = $this->getJson('/api/v1/finance/journals?all=1')->assertOk()->json('meta.totals');

        // 8 jurnal: opening 5jt, akrual 850rb, pelunasan 850rb, tiga pengakuan
        // hutang (1,1jt + 500rb + 200rb), dua pembayaran vendor (400rb + 200rb).
        $this->assertSame(8, $totals['journals']);
        $this->assertEquals(9100000.0, $totals['debit']);
        $this->assertEquals(9100000.0, $totals['credit']);
        $this->assertTrue($totals['balanced']);
    }

    public function test_transaksi_dan_jurnal_adalah_himpunan_yang_sama(): void
    {
        $this->seedBooks();

        $this->actingAsRole('finance-admin');
        $asTransactions = collect($this->getJson('/api/v1/finance/transactions?all=1')->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
        $asJournals = collect($this->journalList(['all' => 1]))->pluck('id')->sort()->values()->all();

        // Satu entitas, dua sudut pandang baca — bukan tabel jurnal kedua (§71).
        $this->assertNotEmpty($asTransactions);
        $this->assertSame($asTransactions, $asJournals);
    }

    public function test_filter_jurnal_per_tipe_akun_dan_dokumen_sumber(): void
    {
        $books = $this->seedBooks();

        $accruals = $this->journalList(['all' => 1, 'type' => FinancialTransaction::TYPE_INVOICE]);
        $this->assertSame([$books['accrual']], collect($accruals)->pluck('id')->all());

        // Filter akun menembus baris: hanya jurnal yang menyinggung 4100.
        $byAccount = $this->journalList(['all' => 1, 'account_id' => $this->account('4100')]);
        $this->assertSame([$books['accrual']], collect($byAccount)->pluck('id')->all());

        // Kunci dokumen pendek diterima, bukan hanya FQCN.
        $byDocument = $this->journalList(['all' => 1, 'reference_type' => 'payment']);
        $this->assertSame([$books['settlement']], collect($byDocument)->pluck('id')->all());

        $exact = $this->journalList([
            'all' => 1,
            'reference_type' => FinancePayment::class,
            'reference_id' => $books['payment'],
        ]);
        $this->assertSame([$books['settlement']], collect($exact)->pluck('id')->all());

        $this->actingAsRole('finance-admin');
        $resp = $this->getJson('/api/v1/finance/journals?all=1&from=2028-01-01')->assertOk();
        $this->assertSame([], $resp->json('data'));
        $this->assertSame(0, $resp->json('meta.totals.journals'));
        $this->assertTrue($resp->json('meta.totals.balanced'));
    }

    public function test_detail_jurnal_menampilkan_baris_pada_sisi_normal_akun(): void
    {
        $books = $this->seedBooks();

        $this->actingAsRole('finance-manager');
        $detail = $this->getJson("/api/v1/finance/journals/{$books['accrual']}")->assertOk()->json('data');

        $this->assertSame('Pengakuan pendapatan '.$books['invoice']->invoice_number, $detail['description']);
        $this->assertSame($books['invoice']->invoice_number, $detail['document_number']);
        $this->assertSame(FinanceInvoice::class, $detail['reference_type']);
        $this->assertSame('accrual', $detail['basis']);
        $this->assertNotEmpty($detail['posted_by_name']);
        $this->assertEquals(850000.0, $detail['total_debit']);

        $lines = collect($detail['lines'])->keyBy('account_code');

        // Arah dibaca dari sisi normal akun: piutang (debit) dan pendapatan
        // (kredit) sama-sama bertambah positif, bukan neto mentah (§37).
        $this->assertEquals(850000.0, $lines['1300']['movement']);
        $this->assertEquals(350000.0, $lines['4100']['movement']);
        $this->assertEquals(500000.0, $lines['4200']['movement']);
        $this->assertSame('debit', $lines['1300']['normal_balance']);
        $this->assertSame('credit', $lines['4100']['normal_balance']);
        $this->assertCount(3, $detail['lines']);
    }

    // =================================================================
    // §38 penelusuran
    // =================================================================

    public function test_penelusuran_jurnal_inklusi_tembus_sampai_santri(): void
    {
        // Tagihan yang masih berpiutang: accrual saja, tanpa pembayaran.
        $this->openCash(5000000);
        $invoice = $this->issueInvoice();
        $accrual = $this->journalId(FinanceInvoice::class, (int) $invoice->id, FinancialTransaction::TYPE_INVOICE);

        $this->actingAsRole('auditor');
        $trace = $this->getJson("/api/v1/finance/journals/{$accrual}/trace")->assertOk()->json('data.trace');

        $stages = collect($trace['trail'])->pluck('stage')->all();
        $this->assertSame(['journal', 'invoice', 'billing', 'student'], $stages);

        $invoiceStep = collect($trace['trail'])->firstWhere('stage', 'invoice');
        $this->assertSame($invoice->invoice_number, $invoiceStep['number']);
        $this->assertEquals(850000.0, $invoiceStep['amount']);
        $this->assertEquals(850000.0, $invoiceStep['remaining_amount']);
        $this->assertSame('issued', $invoiceStep['receivable_status']);

        // Baris item ikut membawa akun pendapatan masing-masing (§12).
        $items = collect($invoiceStep['items'])->keyBy('account_code');
        $this->assertEquals(350000.0, $items['4100']['amount']);
        $this->assertEquals(500000.0, $items['4200']['amount']);
        $this->assertNotNull($items['4100']['fee_type']);

        $this->assertSame('JRN001', collect($trace['trail'])->firstWhere('stage', 'student')['number']);

        // Jurnal akrual belum punya jejak uang — dan memang tidak boleh ada (§8).
        $this->assertNotContains('payment', $stages);
        $this->assertNotContains('receipt', $stages);
        $this->assertEquals(850000.0, $trace['totals']['debit']);
        $this->assertEquals(850000.0, $trace['totals']['credit']);
        $this->assertSame([$accrual], collect($trace['related_journals'])->pluck('id')->all());
    }

    public function test_penelusuran_jurnal_pembayaran_menampilkan_rantai_uang_lengkap(): void
    {
        $books = $this->seedBooks();

        $this->actingAsRole('auditor');
        $payload = $this->getJson("/api/v1/finance/journals/{$books['settlement']}/trace")->assertOk()->json('data');

        $trail = collect($payload['trace']['trail']);
        $this->assertSame(
            ['journal', 'payment', 'receipt', 'invoice', 'billing', 'student'],
            $trail->pluck('stage')->all(),
        );

        // Setiap tahap menunjuk dokumen aslinya, lengkap dengan nomornya (§38).
        $this->assertSame($payload['journal']['transaction_number'], $trail[0]['number']);
        $this->assertSame(FinancePayment::class, $trail[1]['type']);
        $this->assertSame($books['payment'], $trail[1]['id']);
        $this->assertSame(FinanceReceipt::class, $trail[2]['type']);
        $this->assertSame($books['invoice']->invoice_number, $trail[3]['number']);

        $invoiceStep = $trail->firstWhere('stage', 'invoice');
        $this->assertEquals(0.0, $invoiceStep['remaining_amount']);
        $this->assertSame('paid', $invoiceStep['receivable_status']);

        $related = collect($payload['trace']['related_journals'])->pluck('transaction_number')->all();
        $this->assertCount(2, $related);
        $this->assertContains($payload['journal']['transaction_number'], $related);
        $this->assertContains(
            $this->journalList(['all' => 1, 'type' => FinancialTransaction::TYPE_INVOICE])[0]['transaction_number'],
            $related,
        );

        $accounts = collect($payload['trace']['accounts'])->keyBy('account_code');
        $this->assertEquals(850000.0, $accounts['1100']['debit']);
        $this->assertEquals(850000.0, $accounts['1300']['credit']);
    }

    // =================================================================
    // §35–§37 buku besar + §47 saldo akun
    // =================================================================

    public function test_buku_besar_menutup_per_akun_bukan_lintas_akun(): void
    {
        $this->seedBooks();

        $payload = $this->ledger();
        $closing = $payload['totals']['closing_balances'];

        $this->assertEquals(5250000.0, $closing['1100']); // 5jt + 850rb − 400rb − 200rb
        $this->assertEquals(0.0, $closing['1300']);
        $this->assertEquals(1200000.0, $closing['2100']);
        $this->assertEquals(5000000.0, $closing['3100']);
        $this->assertEquals(350000.0, $closing['4100']);
        $this->assertEquals(1800000.0, $closing['5200']);

        // Saldo tersedia per akun, bukan digabung jadi satu angka (§37).
        $this->assertEquals($payload['totals']['debit'], $payload['totals']['credit']);
        $this->assertGreaterThan(1, collect($payload['rows'])->pluck('account_code')->unique()->count());
    }

    public function test_buku_besar_satu_akun_hanya_memuat_akun_tersebut(): void
    {
        $this->seedBooks();

        $payload = $this->ledger($this->account('1100'));

        $this->assertSame('1100', $payload['account']['code']);
        $this->assertNotEmpty($payload['rows']);
        $this->assertSame(['1100'], collect($payload['rows'])->pluck('account_code')->unique()->all());
        $this->assertEquals(5250000.0, (float) end($payload['rows'])['running_balance']);
        $this->assertEquals(['1100' => 5250000.0], $payload['totals']['closing_balances']);

        // Rentang setelah seluruh jurnal → tanpa mutasi sama sekali (§35).
        $oktober = $this->ledger($this->account('1100'), ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame([], $oktober['rows']);
        $this->assertEquals([], $oktober['totals']['closing_balances']);
    }

    public function test_saldo_akun_menyilangkan_buku_besar_dengan_subledger_kas(): void
    {
        $this->seedBooks();

        $this->actingAsRole('finance-manager');
        $data = $this->getJson('/api/v1/finance/accounts/'.$this->account('1100').'/balance')->assertOk()->json('data');

        $this->assertSame('1100', $data['account']['code']);
        $this->assertSame('debit', $data['account']['normal_balance']);
        $this->assertEquals(0.0, $data['balance']['opening_balance']);
        $this->assertEquals(5250000.0, $data['balance']['period_movement']);
        $this->assertEquals(5250000.0, $data['balance']['balance']);

        // Rekonsiliasi: buku besar akun kas == jumlah saldo rekening konkret (§27).
        $subledger = collect($data['balance']['subledger'])->keyBy('code');
        $this->assertEquals(5250000.0, $subledger['KAS-SEKOLAH']['current_balance']);
        $this->assertEquals(0.0, $subledger['KAS-PESANTREN']['current_balance']);
        $this->assertEquals($data['balance']['balance'], $data['balance']['subledger_balance']);

        // Akun non-kas tidak punya subledger.
        $ekuitas = $this->getJson('/api/v1/finance/accounts/'.$this->account('3100').'/balance')
            ->assertOk()->json('data.balance');
        $this->assertSame([], $ekuitas['subledger']);
        $this->assertEquals(5000000.0, $ekuitas['balance']);
    }

    // =================================================================
    // §30 hutang / accounts payable
    // =================================================================

    public function test_daftar_hutang_hanya_memuat_kewajiban_berjalan(): void
    {
        $books = $this->seedBooks();

        $this->actingAsRole('finance-manager');
        $rows = $this->getJson('/api/v1/finance/payables?all=1')->assertOk()->json('data');

        // Vendor C sudah lunas → bukan hutang lagi.
        $this->assertEqualsCanonicalizing([$books['ap_overdue'], $books['ap_current']], collect($rows)->pluck('id')->all());
        $this->assertEquals(700000.0, (float) collect($rows)->firstWhere('id', $books['ap_overdue'])['remaining_amount']);

        $aging = $this->getJson('/api/v1/finance/payables/aging?as_of=2026-09-26')->assertOk()->json('data');
        $buckets = collect($aging['buckets'])->pluck('total', 'bucket')->all();

        $this->assertEquals(1200000.0, $aging['grand_total']);
        $this->assertEquals(700000.0, $buckets['1_30']);
        $this->assertEquals(500000.0, $buckets['current']);

        $byVendor = collect($aging['by_vendor'])->keyBy('vendor');
        $this->assertCount(2, $byVendor);
        $this->assertEquals(700000.0, $byVendor['Vendor V-JRN-A']['total']);
        $this->assertEquals(500000.0, $byVendor['Vendor V-JRN-B']['total']);

        $summary = $this->getJson('/api/v1/finance/payables/summary?as_of=2026-09-26')->assertOk()->json('data');
        $this->assertSame(2, $summary['invoice_count']);
        $this->assertSame(1, $summary['overdue_count']);
        $this->assertEquals(1200000.0, $summary['total_payable']);
        $this->assertEquals(700000.0, $summary['total_overdue']);

        // Daftar penagihan dan buku besar harus damai: sisa AP == saldo 2100 (§30).
        $ledger = $this->ledger($this->account('2100'));
        $this->assertEquals($summary['total_payable'], (float) end($ledger['rows'])['running_balance']);
    }

    public function test_laporan_payables_memakai_sumber_baris_yang_sama(): void
    {
        $this->seedBooks();

        $this->actingAsRole('finance-admin');
        $rows = $this->getJson('/api/v1/finance/reports/payables?as_of=2026-09-26')->assertOk()->json('data.rows');

        $this->assertCount(2, $rows);
        $this->assertEquals(1200000.0, round(collect($rows)->sum('remaining_amount'), 2));

        $oldest = collect($rows)->sortByDesc('days_overdue')->first();
        $this->assertSame('1_30', $oldest['bucket']);
        $this->assertSame(16, $oldest['days_overdue']);
    }

    // =================================================================
    // §34, §41, §51 imutabilitas & pembalikan
    // =================================================================

    public function test_jurnal_posted_tidak_bisa_diedit_atau_dihapus(): void
    {
        $books = $this->seedBooks();

        $this->actingAsRole('finance-admin');
        $this->patchJson("/api/v1/finance/journals/{$books['accrual']}", ['description' => 'diubah'])->assertStatus(405);
        $this->putJson("/api/v1/finance/journals/{$books['accrual']}", ['description' => 'diubah'])->assertStatus(405);
        $this->deleteJson("/api/v1/finance/journals/{$books['accrual']}")->assertStatus(405);
        $this->postJson('/api/v1/finance/journals', ['description' => 'jurnal manual'])->assertStatus(405);

        // Tidak satu pun baris jurnal bertambah atau berubah.
        $this->assertSame(8, FinancialTransaction::query()->count());
        $this->assertSame(
            'Pengakuan pendapatan '.$books['invoice']->invoice_number,
            (string) FinancialTransaction::query()->find($books['accrual'])->description,
        );
    }

    public function test_membalik_jurnal_inklusi_menambah_jurnal_baru_tanpa_mengubah_aslinya(): void
    {
        $books = $this->seedBooks();

        $original = FinancialTransaction::query()->findOrFail($books['accrual']);
        $linesBefore = $this->lineFingerprint($original);
        $totalBefore = (float) $original->total_debit;

        $this->actingAsRole('finance-manager');
        $counter = $this->postJson("/api/v1/finance/journals/{$books['accrual']}/reverse", [
            'reason' => 'Salah terbitkan tagihan, dibatalkan lalu diterbitkan ulang',
        ])->assertCreated()->json('data');

        // Jurnal asli tetap utuh — hanya statusnya yang menunjuk pembaliknya (§41).
        $original->refresh();
        $this->assertSame(FinancialTransaction::STATUS_REVERSED, $original->status);
        $this->assertSame($counter['id'], (int) $original->reversed_by_transaction_id);
        $this->assertSame($linesBefore, $this->lineFingerprint($original));
        $this->assertEquals($totalBefore, (float) $original->total_debit);

        // Baris pembalik memirror dengan sisi bertukar (§51).
        $this->assertTrue($counter['is_balanced']);
        $this->assertStringContainsString('Pembatalan', $counter['description']);
        $sides = collect($counter['lines'])->keyBy('account_code');
        $this->assertEquals(0.0, $sides['1300']['debit']);
        $this->assertEquals(850000.0, $sides['1300']['credit']);
        $this->assertEquals(350000.0, $sides['4100']['debit']);
        $this->assertEquals(0.0, $sides['4100']['credit']);

        // Buku besar memuat KEDUA jurnal: yang asli tidak hilang dari saldo, ia
        // dinolkan oleh pembaliknya (§51). Mengeluarkan yang asli justru membuat
        // pembalikan jadi dua kali kesalahan.
        $posted = fn (int $accountId): array => collect($this->ledger($accountId)['rows'])
            ->pluck('transaction_number')->unique()->all();

        foreach (['1300', '4100'] as $code) {
            $this->assertContains($original->transaction_number, $posted($this->account($code)));
            $this->assertContains($counter['transaction_number'], $posted($this->account($code)));
        }

        $pendapatan = $this->ledger($this->account('4100'))['rows'];
        $this->assertEquals(0.0, (float) end($pendapatan)['running_balance']);

        $this->assertSame($counter['transaction_number'], $original->load('reversal')->reversal->transaction_number);

        // Jurnal yang sama tidak bisa dibalik dua kali.
        $this->postJson("/api/v1/finance/journals/{$books['accrual']}/reverse", ['reason' => 'Dibalik dua kali'])
            ->assertStatus(422);
    }

    public function test_jurnal_pembayaran_tidak_bisa_dibalik_langsung_karena_gerakkan_kas(): void
    {
        $books = $this->seedBooks();

        $cashBefore = (float) $this->cash()->fresh()->current_balance;

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/journals/{$books['settlement']}/reverse", [
            'reason' => 'Mencoba membalik jurnal kas tanpa dokumen sumber',
        ])->assertStatus(422)->assertJsonValidationErrors('journal');

        // Tidak ada jurnal baru, saldo kas utuh, jurnal asli tetap posted.
        $this->assertSame(8, FinancialTransaction::query()->count());
        $this->assertSame($cashBefore, (float) $this->cash()->fresh()->current_balance);
        $this->assertSame(FinancialTransaction::STATUS_POSTED,
            (string) FinancialTransaction::query()->find($books['settlement'])->status);
    }

    public function test_alasan_pembalikan_wajib_diisi(): void
    {
        $books = $this->seedBooks();

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/journals/{$books['accrual']}/reverse", ['reason' => 'ok'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/finance/journals/{$books['accrual']}/reverse", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertSame(8, FinancialTransaction::query()->count());
    }

    public function test_hak_baca_dan_hak_membalik_jurnal_terpisah(): void
    {
        $books = $this->seedBooks();

        // Pencatat membaca hasil catatannya, tetapi tidak membalik jurnal (§3).
        $this->actingAsRole('finance-officer');
        $this->getJson('/api/v1/finance/transactions')->assertOk();
        $this->getJson('/api/v1/finance/ledger')->assertOk();
        $this->postJson("/api/v1/finance/journals/{$books['accrual']}/reverse", ['reason' => 'Bukan wewenang officer'])
            ->assertForbidden();

        // Auditor membaca semuanya dan tetap read-only (§56).
        $this->actingAsRole('auditor');
        $this->getJson("/api/v1/finance/journals/{$books['accrual']}/trace")->assertOk();
        $this->postJson("/api/v1/finance/journals/{$books['accrual']}/reverse", ['reason' => 'Auditor tidak memposting'])
            ->assertForbidden();

        // Kasir tidak melihat jurnal, buku besar, maupun saldo akun.
        $this->actingAsRole('kasir');
        $this->getJson('/api/v1/finance/journals')->assertForbidden();
        $this->getJson('/api/v1/finance/transactions')->assertForbidden();
        $this->getJson('/api/v1/finance/ledger')->assertForbidden();
        $this->getJson('/api/v1/finance/accounts/'.$this->account('1100').'/balance')->assertForbidden();

        $this->actingAsRole('siswa');
        $this->getJson('/api/v1/finance/payables')->assertForbidden();
    }
}
