<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceAdjustment;
use App\Models\FinanceBilling;
use App\Models\FinanceCashAccount;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinancialTransaction;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Write-off piutang (§32–§34) lewat HTTP.
 *
 * Yang diverifikasi: permintaan hanya menyebut invoice/nominal/alasan — akun
 * jurnal (Beban Write-Off 5700 / Piutang 1300) datang dari kebijakan; alur
 * approval memisahkan peminta dan penyetuju; posting membuat jurnal BARU dan
 * mengurangi sisa tagihan tanpa menyentuh `paid_amount` (write-off bukan
 * pembayaran §33); nominal melebihi sisa ditolak; invoice yang sisa piutangnya
 * habis hilang dari AR tapi tetap terbaca `written_off`; jurnal pendapatan dari
 * penerbitan invoice tidak berubah (tidak ada revenue kedua §7).
 */
class FinanceWriteOffTest extends TestCase
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

    private function student(string $nis): Student
    {
        return Student::query()->firstOrCreate(
            ['nis' => $nis],
            ['full_name' => 'Santri '.$nis, 'is_active' => true],
        );
    }

    /** Billing + invoice terbit 850.000 (SPP 350k + Asrama 500k). */
    private function issueInvoice(string $nis, string $packageCode): FinanceInvoice
    {
        $this->actingAsRole('finance-admin');

        $feeTypes = ['SPP', 'UANG_ASRAMA'];
        $amounts = ['SPP' => 350000, 'UANG_ASRAMA' => 500000];

        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => $packageCode],
            ['name' => 'Paket '.$packageCode, 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $package->items()->createMany(collect($feeTypes)->map(fn (string $code): array => [
                'fee_type_id' => FinanceFeeType::query()->where('code', $code)->value('id'),
                'amount' => $amounts[$code],
                'frequency' => 'monthly',
                'is_active' => true,
            ])->all());
        }

        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => '2026-09',
            'student_ids' => [$this->student($nis)->id],
            'due_date' => '2026-09-25',
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::find(
            $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    /** @return array{0: int, 1: FinanceInvoice} id adjustment + invoice */
    private function requestWriteOff(FinanceInvoice $invoice, float $amount, string $reason = 'Santri keluar, piutang tak tertagih'): int
    {
        $this->actingAsRole('finance-officer');

        return (int) $this->postJson("/api/v1/finance/invoices/{$invoice->id}/write-off", [
            'amount' => $amount,
            'reason' => $reason,
            'adjustment_date' => '2026-09-20',
        ])->assertCreated()->json('data.id');
    }

    /** Setujui lalu posting sebagai finance-manager. */
    private function approveAndPost(int $id): void
    {
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/adjustments/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/finance/adjustments/{$id}/post")->assertOk();
    }

    private function journalFor(int $adjustmentId): FinancialTransaction
    {
        return FinancialTransaction::query()
            ->with('lines.account')
            ->where('reference_type', FinanceAdjustment::class)
            ->where('reference_id', $adjustmentId)
            ->where('status', FinancialTransaction::STATUS_POSTED)
            ->firstOrFail();
    }

    /** Bayar lewat endpoint payment biasa — satu-satunya jalur uang masuk (§17). */
    private function pay(FinanceInvoice $invoice, float $amount): void
    {
        $this->actingAsRole('finance-admin');

        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => 'cash',
            'cash_account_id' => FinanceCashAccount::query()->where('code', 'KAS-SEKOLAH')->value('id'),
        ])->assertCreated();
    }

    // =================================================================

    public function test_permintaan_write_off_memakai_akun_dari_kebijakan(): void
    {
        $invoice = $this->issueInvoice('WO001', 'PKT-WO1');
        $id = $this->requestWriteOff($invoice, 300000);

        $this->actingAsRole('finance-officer');
        $detail = $this->getJson("/api/v1/finance/adjustments/{$id}")->assertOk()->json('data');

        $this->assertSame(FinanceAdjustment::KIND_WRITE_OFF, $detail['kind']);
        $this->assertSame(FinanceAdjustment::STATUS_SUBMITTED, $detail['status']);
        $this->assertSame($invoice->id, $detail['invoice_id']);
        $this->assertSame($invoice->invoice_number, $detail['invoice_number']);
        // Akun datang dari policy (§20), dan detail invoice ikut terisi.
        $this->assertSame($this->account('5700'), $detail['account_id']);
        $this->assertSame($this->account('1300'), $detail['contra_account_id']);
        $this->assertSame('Piutang (Tagihan Santri)', $detail['contra_account_name']);
    }

    public function test_posting_mengurangi_sisa_tagihan_tanpa_menyentuh_paid_amount(): void
    {
        $invoice = $this->issueInvoice('WO002', 'PKT-WO2');
        $id = $this->requestWriteOff($invoice, 300000);
        $this->approveAndPost($id);

        $invoice->refresh();
        $this->assertSame(300000.0, (float) $invoice->written_off_amount);
        $this->assertSame(550000.0, round((float) $invoice->remaining_amount, 2));
        // §33: write-off bukan pembayaran — `paid_amount` tidak boleh ikut naik.
        // Status dokumen pun tetap ISSUED: penagihan berlanjut untuk sisanya.
        $this->assertSame(0.0, (float) $invoice->paid_amount);
        $this->assertSame(FinanceInvoice::STATUS_ISSUED, $invoice->status);

        $billing = FinanceBilling::find($invoice->billing_id);
        $this->assertSame(550000.0, round((float) $billing->remaining_amount, 2));
        $this->assertSame(0.0, (float) $billing->paid_amount);

        $journal = $this->journalFor($id);
        $this->assertSame(FinancialTransaction::TYPE_WRITE_OFF, $journal->type);
        $this->assertTrue($journal->isBalanced());

        $sides = $journal->lines->mapWithKeys(fn ($line): array => [
            $line->account->code => ['debit' => (float) $line->debit, 'credit' => (float) $line->credit],
        ])->all();

        $this->assertSame(300000.0, $sides['5700']['debit']);
        $this->assertSame(300000.0, $sides['1300']['credit']);
    }

    public function test_write_off_sebagian_tetap_terbaca_di_ar(): void
    {
        $invoice = $this->issueInvoice('WO003', 'PKT-WO3');
        $this->approveAndPost($this->requestWriteOff($invoice, 200000));

        $this->actingAsRole('finance-officer');
        $row = collect($this->getJson('/api/v1/finance/receivables?all=1')->assertOk()->json('data'))
            ->firstWhere('id', $invoice->id);

        $this->assertNotNull($row);
        $this->assertSame(200000.0, (float) $row['written_off_amount']);
        $this->assertSame(650000.0, (float) $row['remaining_amount']);
        $this->assertNotSame('written_off', $row['receivable_status']);
    }

    public function test_piutang_yang_habis_tidak_lagi_jadi_ar(): void
    {
        $invoice = $this->issueInvoice('WO004', 'PKT-WO4');
        $this->approveAndPost($this->requestWriteOff($invoice, 850000));

        $invoice->refresh();
        $this->assertSame(0.0, round((float) $invoice->remaining_amount, 2));
        $this->assertSame(FinanceInvoice::STATUS_PAID, $invoice->status);
        $this->assertSame('written_off', $invoice->receivableStatus());

        $this->actingAsRole('finance-officer');
        $ids = collect($this->getJson('/api/v1/finance/receivables?all=1')->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertNotContains($invoice->id, $ids);
    }

    public function test_nominal_melebihi_sisa_tagihan_ditolak(): void
    {
        $invoice = $this->issueInvoice('WO005', 'PKT-WO5');

        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/invoices/{$invoice->id}/write-off", [
            'amount' => 900000,
            'reason' => 'Melebihi sisa tagihan',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_alasan_pendek_ditolak(): void
    {
        $invoice = $this->issueInvoice('WO006', 'PKT-WO6');

        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/invoices/{$invoice->id}/write-off", [
            'amount' => 100000,
            'reason' => 'ok',
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_kasir_tidak_boleh_mengajukan_write_off(): void
    {
        $invoice = $this->issueInvoice('WO007', 'PKT-WO7');

        $this->actingAsRole('kasir');
        $this->postJson("/api/v1/finance/invoices/{$invoice->id}/write-off", [
            'amount' => 100000,
            'reason' => 'Bukan wewenang kasir',
        ])->assertForbidden();
    }

    public function test_jalur_umum_write_off_tetap_menuntut_izin_write_off(): void
    {
        $invoice = $this->issueInvoice('WO008', 'PKT-WO8');

        // Hanya hak adjustment umum: middleware rute lolos, pemeriksaan `kind`
        // di controller yang harus menahan.
        $user = User::factory()->create(['email' => 'hanya-adjustment@schoolerp.test', 'is_active' => true]);
        $user->syncPermissions(['finance.view', 'finance.adjustment.create']);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/finance/adjustments', [
            'kind' => FinanceAdjustment::KIND_WRITE_OFF,
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'reason' => 'Menyalahgunakan jalur adjustment',
        ])->assertForbidden();

        $this->assertSame(0, FinanceAdjustment::query()->where('kind', FinanceAdjustment::KIND_WRITE_OFF)->count());
    }

    public function test_peminta_tidak_boleh_menyetujui_write_off_sendiri(): void
    {
        $invoice = $this->issueInvoice('WO009', 'PKT-WO9');

        // Satu user dengan hak request + approve: gerbang permission lolos,
        // yang menahan adalah service (pemisahan pencatat vs penyetuju §3).
        $user = User::factory()->create(['email' => 'officer-manager@schoolerp.test', 'is_active' => true]);
        $user->syncPermissions([
            'finance.view',
            'finance.adjustment.create',
            'finance.adjustment.approve',
            'finance.write_off.request',
            'finance.write_off.approve',
        ]);
        Sanctum::actingAs($user, ['*']);

        $id = (int) $this->postJson("/api/v1/finance/invoices/{$invoice->id}/write-off", [
            'amount' => 150000,
            'reason' => 'Piutang macet lama',
            'adjustment_date' => '2026-09-20',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/adjustments/{$id}/approve")->assertStatus(422);
        $this->assertSame(FinanceAdjustment::STATUS_SUBMITTED, FinanceAdjustment::find($id)->status);
    }

    public function test_write_off_tidak_menambah_jurnal_pendapatan(): void
    {
        $invoice = $this->issueInvoice('WO010', 'PKT-WO10');

        $revenueBefore = (float) $this->invoiceRevenueCredits($invoice);

        $this->assertSame(850000.0, $revenueBefore);

        $this->approveAndPost($this->requestWriteOff($invoice, 400000));

        $journal = $this->journalFor((int) FinanceAdjustment::query()
            ->where('invoice_id', $invoice->id)
            ->value('id'));

        // Jurnal write-off tidak menyentuh akun pendapatan (§7: revenue tidak
        // dicatat dua kali; beban write-off-lah yang menyerap piutang).
        $revenueAccounts = ['4100', '4200', '4300', '4400', '4500'];
        $touched = $journal->lines->pluck('account.code')->intersect($revenueAccounts)->all();
        $this->assertSame([], array_values($touched));

        $this->assertSame($revenueBefore, $this->invoiceRevenueCredits($invoice));
    }

    /** Total kredit pada jurnal akrual invoice — angka pendapatan yang sudah tercatat. */
    private function invoiceRevenueCredits(FinanceInvoice $invoice): float
    {
        return (float) FinancialTransaction::query()
            ->with('lines')
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->where('type', FinancialTransaction::TYPE_INVOICE)
            ->get()
            ->flatMap(fn (FinancialTransaction $t): iterable => $t->lines)
            ->sum('credit');
    }

    public function test_write_off_tidak_mengubah_jurnal_asli_invoice(): void
    {
        $invoice = $this->issueInvoice('WO011', 'PKT-WO11');

        $accrual = FinancialTransaction::query()
            ->with('lines')
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->first();
        $linesBefore = $accrual->lines->count();
        $updatedAtBefore = $accrual->updated_at->toDateTimeString();

        $this->approveAndPost($this->requestWriteOff($invoice, 250000));

        $accrual->refresh()->load('lines');
        $this->assertSame($linesBefore, $accrual->lines->count());
        $this->assertSame($updatedAtBefore, $accrual->updated_at->toDateTimeString());
        $this->assertSame(FinancialTransaction::STATUS_POSTED, $accrual->status);
    }

    public function test_membayar_sisa_setelah_write_off_tidak_menghidupkan_kembali_piutang(): void
    {
        $invoice = $this->issueInvoice('WO012', 'PKT-WO12');
        $this->approveAndPost($this->requestWriteOff($invoice, 300000));

        // §33: sisa tinggal 550.000 dan `paid_amount` masih nol.
        $this->assertSame(550000.0, round((float) $invoice->refresh()->remaining_amount, 2));

        // Wali membayar sisanya lewat jalur pembayaran biasa.
        $this->pay($invoice, 550000);

        $invoice->refresh();

        // Invariant §31: outstanding = total − alokasi payment − write-off.
        // Membayar sisa yang tersisa harus menyelesaikan tagihannya. Kalau sisa
        // dihitung ulang sebagai total − paid saja, 300.000 yang sudah dihapus
        // ke beban write-off kembali menjadi utang — dan sekolah bisa menagih
        // uang yang sudah dia hapus sendiri.
        $this->assertSame(550000.0, round((float) $invoice->paid_amount, 2));
        $this->assertSame(300000.0, round((float) $invoice->written_off_amount, 2));
        $this->assertSame(0.0, round((float) $invoice->remaining_amount, 2));
        $this->assertSame(FinanceInvoice::STATUS_PAID, $invoice->status);

        // Billing induk ikut selesai: write-off di invoice juga tercatat di sana,
        // bukan sekadar pengurangan angka yang lalu ditimpa pembayaran.
        $billing = FinanceBilling::query()->find($invoice->billing_id);
        $this->assertSame(550000.0, round((float) $billing->paid_amount, 2));
        $this->assertSame(0.0, round((float) $billing->remaining_amount, 2));
        $this->assertSame(FinanceBilling::STATUS_PAID, $billing->status);

        // Tidak ada piutang tersisa di AR (§21): daftar tidak boleh menunjukkan
        // tagihan yang sudah selesai hanya karena pernah di write-off.
        $this->actingAsRole('finance-officer');
        $ids = collect($this->getJson('/api/v1/finance/receivables?all=1')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($invoice->id, $ids);
    }
}
