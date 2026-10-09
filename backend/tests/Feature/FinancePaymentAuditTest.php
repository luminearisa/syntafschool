<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinancePaymentGatewayTransaction;
use App\Models\FinancePaymentVerification;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Jejak audit pembayaran Phase 2 (§22, §40, §66): tabel `finance_payment_verifications`
 * menyimpan riwayat tindakan admin (verifikasi/penolakan/pembatalan), dan
 * `finance_payment_gateway_transactions` menyimpan jejak charge + webhook secara
 * idempoten (notifikasi ganda tidak menduplikasi log).
 */
class FinancePaymentAuditTest extends TestCase
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

    private function student(string $nis): Student
    {
        return Student::query()->firstOrCreate(['nis' => $nis], ['full_name' => 'Santri '.$nis, 'is_active' => true]);
    }

    private function feeType(string $code): FinanceFeeType
    {
        return FinanceFeeType::query()->where('code', $code)->firstOrFail();
    }

    private function cash(): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', 'KAS-SEKOLAH')->firstOrFail();
    }

    private function bank(): FinanceBankAccount
    {
        return FinanceBankAccount::query()->where('code', 'BANK-OPS')->firstOrFail();
    }

    private function package(string $code): FinanceFeePackage
    {
        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => $code],
            ['name' => 'Paket '.$code, 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $package->items()->createMany([
                ['fee_type_id' => $this->feeType('SPP')->id, 'amount' => 350000, 'frequency' => 'monthly', 'is_active' => true],
                ['fee_type_id' => $this->feeType('UANG_ASRAMA')->id, 'amount' => 500000, 'frequency' => 'monthly', 'is_active' => true],
            ]);
        }

        return $package;
    }

    private function issuedInvoice(Student $student, string $period, string $pkg): FinanceInvoice
    {
        $package = $this->package($pkg);

        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => $period,
            'student_ids' => [$student->id],
            'due_date' => '2026-09-30',
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::find(
            $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    // =================================================================
    // Riwayat verifikasi (§22, §40)
    // =================================================================

    public function test_verifikasi_mencatat_riwayat(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('AU001'), '2026-09', 'PKT-AU1');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'bank_transfer',
            'bank_account_id' => $this->bank()->id,
        ])->assertCreated()->json('data.id');

        $manager = $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/payments/{$paymentId}/verify")->assertOk();

        $log = FinancePaymentVerification::where('payment_id', $paymentId)->first();
        $this->assertNotNull($log);
        $this->assertSame(FinancePaymentVerification::ACTION_VERIFIED, $log->action);
        $this->assertSame($manager->id, (int) $log->user_id);
    }

    public function test_penolakan_mencatat_riwayat_dengan_alasan(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('AU002'), '2026-09', 'PKT-AU2');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'manual_transfer',
            'bank_account_id' => $this->bank()->id,
            'proof_path' => 'bukti/x.jpg',
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/payments/{$paymentId}/reject", [
            'reason' => 'Bukti transfer tidak jelas',
        ])->assertOk();

        $log = FinancePaymentVerification::where('payment_id', $paymentId)->first();
        $this->assertSame(FinancePaymentVerification::ACTION_REJECTED, $log->action);
        $this->assertSame('Bukti transfer tidak jelas', $log->reason);
    }

    public function test_pembatalan_mencatat_riwayat(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('AU003'), '2026-09', 'PKT-AU3');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/payments/{$paymentId}/cancel", [
            'reason' => 'Salah input',
        ])->assertOk();

        $log = FinancePaymentVerification::where('payment_id', $paymentId)
            ->where('action', FinancePaymentVerification::ACTION_CANCELLED)->first();
        $this->assertNotNull($log);
        $this->assertSame('Salah input', $log->reason);
    }

    // =================================================================
    // Jejak gateway (§20, §66)
    // =================================================================

    public function test_charge_mencatat_transaksi_gateway(): void
    {
        $this->app->instance(PaymentGateway::class, new FakeAuditGateway);

        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('AU010'), '2026-09', 'PKT-AU4');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'bank_transfer',
            'bank_account_id' => $this->bank()->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/payments/{$paymentId}/charge", ['provider' => 'fake'])->assertOk();

        $log = FinancePaymentGatewayTransaction::where('payment_id', $paymentId)
            ->where('event_type', FinancePaymentGatewayTransaction::EVENT_CHARGE)->first();
        $this->assertNotNull($log);
        $this->assertSame('fake', $log->gateway);
        $this->assertTrue((bool) $log->handled);
    }

    public function test_webhook_dicatat_idempoten(): void
    {
        $this->app->instance(PaymentGateway::class, new FakeAuditGateway);

        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('AU011'), '2026-09', 'PKT-AU5');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'payment_gateway',
            'bank_account_id' => $this->bank()->id,
            'gateway' => 'fake',
        ])->assertCreated()->json('data.id');

        $payload = ['reference' => FinancePayment::find($paymentId)->payment_number, 'status' => 'paid'];

        $this->postJson('/api/v1/finance/webhooks/payment/fake', $payload)->assertOk();
        $this->postJson('/api/v1/finance/webhooks/payment/fake', $payload)->assertOk();

        // Payment lunas sekali, dan log webhook tidak ganda (§66).
        $this->assertSame(FinancePayment::STATUS_PAID, FinancePayment::find($paymentId)->status);
        $this->assertSame(
            1,
            FinancePaymentGatewayTransaction::where('payment_id', $paymentId)
                ->where('event_type', FinancePaymentGatewayTransaction::EVENT_WEBHOOK)
                ->count()
        );
    }
}

/**
 * Gateway palsu untuk tes audit: tanda tangan selalu sah, menormalkan webhook
 * apa adanya. Nama unik agar tidak bentrok dengan fake di test lain saat satu
 * proses memuat banyak berkas tes.
 */
class FakeAuditGateway implements PaymentGateway
{
    public function createPayment(array $intent): array
    {
        return [
            'reference' => $intent['reference'],
            'amount' => (float) $intent['amount'],
            'token' => 'fake-token',
            'redirect_url' => 'https://gateway.test/pay/fake-token',
            'provider_reference' => 'GTW-'.strtoupper($intent['reference']),
            'expires_at' => null,
            'raw' => [],
        ];
    }

    public function checkStatus(string $reference): array
    {
        return ['reference' => $reference, 'status' => 'paid', 'amount' => 0.0, 'provider_reference' => null, 'raw' => []];
    }

    public function handleWebhook(Request $request): array
    {
        return [
            'reference' => (string) $request->input('reference', ''),
            'status' => (string) $request->input('status', ''),
            'amount' => (float) $request->input('amount', 0),
            'provider_reference' => $request->input('provider_reference'),
            'raw' => $request->all(),
        ];
    }

    public function verifySignature(Request $request): bool
    {
        return true;
    }

    public function supports(string $provider): bool
    {
        return $provider === 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
