<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\FinanceBankAccount;
use App\Models\FinanceBilling;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinancePaymentGatewayTransaction;
use App\Models\FinancePeriod;
use App\Models\FinancialTransaction;
use App\Models\Student;
use App\Models\User;
use App\Notifications\FinanceActivityNotification;
use App\Services\Finance\FinanceSettingsService;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Otomasi Keuangan (§7 Phase 5): apa yang jalan sendiri, dan apa yang terjadi
 * kalau ia berjalan dua kali.
 *
 * Tiga hal yang dijaga di sini:
 *
 * 1. **Batas waktu kanal pembayaran benar-benar hidup.** §7 menuntut
 *    `WAITING_PAYMENT → EXPIRED`; tanpa jam yang tersimpan di dokumen, status itu
 *    tidak pernah tercapai dan pembayaran gateway menggantung selamanya. Scheduler
 *    hanya menyentuh baris ber-`expires_at`, karena transfer manual menunggu
 *    keputusan petugas, bukan jam.
 * 2. **Dana yang masuk setelah kanal mati tidak pernah masuk buku besar diam-diam**
 *    (§21, §29). Jalurnya ditolak, tapi anomalinya dilaporkan ke pemegang hak
 *    verifikasi — uang tetap ada di dunia nyata walau dokumennya sudah ditutup.
 * 3. **Penerbitan tagihan terjadwal idempoten.** SPP yang sama tidak boleh lahir
 *    dua kali dan pendapatan tidak boleh diakui dua kali, walau perintahnya
 *    dijalankan setiap hari.
 *
 * Semua lewat jalur service yang sama dengan aksi manual, jadi test ini ikut
 * membuktikan tidak ada mesin kedua.
 */
class FinanceAutomationTest extends TestCase
{
    use RefreshDatabase;

    private const TAGIHAN = 850000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // =================================================================
    // Batas waktu pembayaran kanal (§7)
    // =================================================================

    public function test_charge_gateway_menyimpan_batas_waktu_dari_konfigurasi(): void
    {
        $this->gateway(null);
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('AU001'), 'PKT-AU-A');

        // Provider diam → TTL konfigurasi yang jadi pegangan.
        app(FinanceSettingsService::class)->update(['gateway_payment_ttl_hours' => 6]);

        $paymentId = $this->gatewayPayment($invoice);

        $this->postJson("/api/v1/finance/payments/{$paymentId}/charge")
            ->assertOk()
            ->assertJsonPath('data.expires_at', null);

        $payment = FinancePayment::find($paymentId);
        $this->assertNotNull($payment->expires_at);
        $this->assertEqualsWithDelta(
            Carbon::now()->addHours(6)->timestamp,
            Carbon::parse($payment->expires_at)->timestamp,
            60,
        );
    }

    public function test_batas_waktu_provider_dipakai_dan_yang_terbaca_sembarangan_jatuh_ke_ttl(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('AU002'), 'PKT-AU-B');

        // Provider mengirim batas waktunya → itu yang disimpan, bukan TTL.
        app(FinanceSettingsService::class)->update(['gateway_payment_ttl_hours' => 24]);
        $this->gateway('2026-12-31T10:20:00+00:00');

        $paymentId = $this->gatewayPayment($invoice);
        $this->postJson("/api/v1/finance/payments/{$paymentId}/charge")->assertOk();

        $this->assertSame(
            Carbon::parse('2026-12-31T10:20:00+00:00')->timestamp,
            Carbon::parse(FinancePayment::find($paymentId)->expires_at)->timestamp,
        );

        // Provider yang mengirim tanggal tak terbaca tidak boleh menyeret charge
        // gagal: yang dipakai TTL konfigurasi.
        $this->gateway('bukan-tanggal');
        $paymentLiar = $this->gatewayPayment($invoice, 300000);
        $this->postJson("/api/v1/finance/payments/{$paymentLiar}/charge")->assertOk();

        $this->assertEqualsWithDelta(
            Carbon::now()->addHours(24)->timestamp,
            Carbon::parse(FinancePayment::find($paymentLiar)->expires_at)->timestamp,
            60,
        );
    }

    public function test_perintah_kedaluwarsakan_hanya_menyentuh_kanal_berbatas_waktu(): void
    {
        $this->gateway(null);
        $this->actingAsRole('finance-admin');
        $student = $this->student('AU003');
        $invoice = $this->issuedInvoice($student, 'PKT-AU-C');

        $gatewayId = $this->gatewayPayment($invoice);
        $this->postJson("/api/v1/finance/payments/{$gatewayId}/charge")->assertOk();
        FinancePayment::find($gatewayId)->update(['expires_at' => Carbon::now()->subHour()]);

        // Transfer manual: menunggu verifikator, tidak punya jam.
        $manualId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 200000,
            'method' => 'manual_transfer',
            'bank_account_id' => $this->bank('BANK-OPS')->id,
        ])->assertCreated()->json('data.id');

        $this->artisan('finance:expire-payments')
            ->expectsOutputToContain('Pembayaran diperiksa: 1')
            ->expectsOutputToContain('Pembayaran kedaluwarsa: 1')
            ->assertSuccessful();

        $this->assertSame(FinancePayment::STATUS_EXPIRED, FinancePayment::find($gatewayId)->status);
        $this->assertSame(FinancePayment::STATUS_PENDING, FinancePayment::find($manualId)->status);
        $this->assertSame(1, $this->notificationsTo($student->user, 'payment_expired'));

        // Kanal yang mati tidak pernah menyentuh sisa tagihan.
        $this->assertEquals(self::TAGIHAN, (float) $invoice->fresh()->remaining_amount);

        // Dijalankan lagi: baris yang sudah kedaluwarsa tidak diperiksa ulang.
        $this->artisan('finance:expire-payments')
            ->expectsOutputToContain('Pembayaran diperiksa: 0')
            ->expectsOutputToContain('Pembayaran kedaluwarsa: 0')
            ->assertSuccessful();
        $this->assertSame(1, $this->notificationsTo($student->user, 'payment_expired'));
    }

    public function test_dana_gateway_yang_masuk_setelah_batas_dilaporkan_tanpa_masuk_buku_besar(): void
    {
        $this->gateway(null);
        $this->actingAsRole('finance-admin');
        $student = $this->student('AU004');
        $invoice = $this->issuedInvoice($student, 'PKT-AU-D');

        // Pemegang hak verifikasi harus sudah berperan sebelum peristiwa terjadi.
        $verifier = $this->userOfRole('finance-manager');

        $paymentId = $this->gatewayPayment($invoice);
        $this->postJson("/api/v1/finance/payments/{$paymentId}/charge")->assertOk();
        $payment = FinancePayment::find($paymentId);
        $payment->update(['expires_at' => Carbon::now()->subHour()]);

        $this->artisan('finance:expire-payments')->assertSuccessful();
        $this->assertSame(FinancePayment::STATUS_EXPIRED, $payment->fresh()->status);

        // Jumlah pesan "menunggu verifikasi" yang sudah ada sebelum webhook telat
        // (payment ini lahir sebagai pending, jadi verifikatornya sudah dikabari).
        $sebelum = $this->notificationsTo($verifier, 'payment_pending_verification');

        // Provider telat mengabari: dananya masuk setelah kanal ditutup.
        $webhook = [
            'reference' => $payment->payment_number,
            'status' => 'paid',
            'amount' => self::TAGIHAN,
            'provider_reference' => 'GTW-LATE-1',
        ];

        $this->postJson('/api/v1/finance/webhooks/payment/fake', $webhook)->assertOk();

        // Dokumen tetap tertutup dan buku besar tidak bergerak (§21): tidak ada
        // jurnal payment, invoice tetap penuh tunggakanunya.
        $this->assertSame(FinancePayment::STATUS_EXPIRED, $payment->fresh()->status);
        $this->assertEquals(0.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame(0, FinancialTransaction::query()
            ->where('reference_type', FinancePayment::class)
            ->where('reference_id', $paymentId)
            ->count());
        $this->assertSame(0, $this->notificationsTo($student->user, 'payment_received'));

        // Anomalinya dilaporkan, bukan hilang: tepat satu pesan tambahan, dan ia
        // punya referensi sendiri sehingga tidak menimpa undangan verifikasi awal.
        $this->assertSame($sebelum + 1, $this->notificationsTo($verifier, 'payment_pending_verification'));
        $this->assertSame(1, $this->notificationsByReference(
            $verifier,
            $payment->payment_number.':late-capture',
        ));

        // Notifikasi provider yang sama datang dua kali → tetap satu pesan & satu
        // jejak webhook (retry aman terhadap transaksi ganda, §29).
        $this->postJson('/api/v1/finance/webhooks/payment/fake', $webhook)->assertOk();
        $this->assertSame(1, $this->notificationsByReference(
            $verifier,
            $payment->payment_number.':late-capture',
        ));
        $this->assertSame(1, FinancePaymentGatewayTransaction::query()
            ->where('payment_id', $paymentId)
            ->where('event_type', FinancePaymentGatewayTransaction::EVENT_WEBHOOK)
            ->count());

        // Petugas yang memeriksa kejadian ini tidak otomatis dianggap membayarnya.
        $this->assertSame(FinancePayment::STATUS_EXPIRED, $payment->fresh()->status);
    }

    // =================================================================
    // SPP terjadwal (§7)
    // =================================================================

    public function test_generate_spp_terjadwal_diam_saat_fitur_belum_dinyalakan(): void
    {
        $this->actingAsRole('finance-admin');
        $this->student('AU005');

        $this->artisan('finance:generate-spp')
            ->expectsOutputToContain('Billing terbit: 0')
            ->expectsOutputToContain('spp_auto_generate_enabled')
            ->assertSuccessful();

        $this->assertSame(0, FinanceBilling::query()->count());
        $this->assertSame(0, FinanceInvoice::query()->count());
    }

    public function test_generate_spp_terjadwal_mengisi_periode_berjalan_lalu_idempoten(): void
    {
        $this->actingAsRole('finance-admin');

        $package = $this->package('PKT-AU-SPP');
        $this->student('AU006');
        $this->student('AU007');
        // Santri nonaktif tidak ikut ditagih.
        Student::query()->create(['nis' => 'AU008', 'full_name' => 'Cuti AU008', 'is_active' => false]);

        $this->enableScheduledSpp($package);

        $this->artisan('finance:generate-spp')
            ->expectsOutputToContain('Billing terbit: 2')
            ->expectsOutputToContain('Invoice terbit: 2')
            ->assertSuccessful();

        $periodKey = 'SPP-'.Carbon::today()->format('Y-m');
        // Jendela periode tidak punya kolom sendiri: ia hidup di `reference`,
        // yang memang kunci idempotensi (santri, paket, periode).
        $this->assertSame(2, FinanceBilling::query()->where('reference', 'like', '%|per:'.$periodKey)->count());
        $this->assertSame(2, FinanceInvoice::query()->count());

        foreach (FinanceInvoice::query()->get() as $invoice) {
            $this->assertEquals(self::TAGIHAN, (float) $invoice->total);
            $this->assertSame(FinanceInvoice::STATUS_ISSUED, $invoice->status);
            // Jatuh tempo ikut konfigurasi: awal jendela + spp_due_after_days.
            $this->assertSame(
                Carbon::today()->startOfMonth()->addDays(10)->toDateString(),
                Carbon::parse($invoice->due_date)->toDateString(),
            );
            $this->assertSame(1, $this->notificationsTo($invoice->student->user, 'invoice_issued'));
        }

        // Pengakuan pendapatan akrual sekali per invoice (§16).
        $this->assertSame(2, FinancialTransaction::query()->where('type', FinancialTransaction::TYPE_INVOICE)->count());
        $journals = FinancialTransaction::query()->count();

        // Hari berikutnya, perintah yang sama: tidak ada tagihan atau jurnal baru.
        $this->artisan('finance:generate-spp')
            ->expectsOutputToContain('Billing terbit: 0')
            ->expectsOutputToContain('Billing sudah ada: 2')
            ->expectsOutputToContain('Invoice terbit: 0')
            ->expectsOutputToContain('Invoice sudah ada: 2')
            ->assertSuccessful();

        $this->assertSame(2, FinanceBilling::query()->count());
        $this->assertSame(2, FinanceInvoice::query()->count());
        $this->assertSame($journals, FinancialTransaction::query()->count());

        // Bulan berikutnya → jendela baru, tagihan baru (siklus, bukan pengulangan).
        Carbon::setTestNow(Carbon::today()->startOfMonth()->addMonth()->addDays(2));
        $this->artisan('finance:generate-spp')
            ->expectsOutputToContain('Billing terbit: 2')
            ->assertSuccessful();
        $this->assertSame(4, FinanceBilling::query()->count());
    }

    public function test_generate_spp_melaporkan_invoice_yang_tertolak_periode_tertutup(): void
    {
        $this->actingAsRole('finance-manager');
        $package = $this->package('PKT-AU-PEN');
        $this->student('AU009');

        $period = FinancePeriod::query()
            ->whereDate('start_date', '<=', Carbon::today())
            ->whereDate('end_date', '>=', Carbon::today())
            ->firstOrFail();

        $this->postJson("/api/v1/finance/periods/{$period->id}/close")->assertOk();
        $this->enableScheduledSpp($package);

        // Billing tetap lahir (ia bukan posting); invoice-nya yang ditolak ledger.
        $this->artisan('finance:generate-spp')
            ->expectsOutputToContain('Invoice gagal terbit: 1')
            ->assertSuccessful();

        $this->assertSame(1, FinanceBilling::query()->count());
        $this->assertSame(0, FinanceInvoice::query()->count());
        $this->assertSame(0, FinancialTransaction::query()->count());

        $this->postJson("/api/v1/finance/periods/{$period->id}/reopen")->assertOk();

        // Periode dibuka lagi → tagihan yang tertunda terbit pada jalannya sendiri.
        $this->artisan('finance:generate-spp')
            ->expectsOutputToContain('Billing sudah ada: 1')
            ->expectsOutputToContain('Invoice terbit: 1')
            ->assertSuccessful();

        $this->assertSame(1, FinanceInvoice::query()->count());
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** Kanal palsu: `expiresAt` meniru provider yang mengirim (atau tidak) batas waktu. */
    private function gateway(?string $expiresAt): void
    {
        $this->app->instance(PaymentGateway::class, new FakeAutomationGateway($expiresAt));
    }

    private function enableScheduledSpp(FinanceFeePackage $package): void
    {
        app(FinanceSettingsService::class)->update([
            'spp_auto_generate_enabled' => true,
            'spp_auto_generate_fee_package_id' => $package->id,
            'spp_auto_generate_cycle' => 'monthly',
            'spp_due_after_days' => 10,
            'spp_auto_issue_invoice' => true,
        ]);
    }

    private function actingAsRole(string $role): User
    {
        $user = $this->userOfRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function userOfRole(string $role): User
    {
        $email = str_replace('-', '.', $role).'@schoolerp.test';

        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncRoles([$role]);

        return $user;
    }

    /** Santri + akun login, supaya notifikasi punya penerima yang nyata. */
    private function student(string $nis): Student
    {
        $student = Student::query()->firstOrCreate(
            ['nis' => $nis],
            ['full_name' => 'Santri '.$nis, 'is_active' => true],
        );

        if ($student->user === null) {
            User::factory()->create([
                'name' => 'Akun '.$nis,
                'email' => strtolower($nis).'@schoolerp.test',
                'is_active' => true,
                'userable_type' => Student::class,
                'userable_id' => $student->id,
            ]);
        }

        return $student->refresh();
    }

    /** Paket SPP 350.000 + Uang Asrama 500.000 = 850.000. */
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

    private function feeType(string $code): FinanceFeeType
    {
        return FinanceFeeType::query()->where('code', $code)->firstOrFail();
    }

    private function bank(string $code): FinanceBankAccount
    {
        return FinanceBankAccount::query()->where('code', $code)->firstOrFail();
    }

    private function issuedInvoice(Student $student, string $pkgCode): FinanceInvoice
    {
        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $this->package($pkgCode)->id,
            'period_key' => Carbon::today()->format('Y-m'),
            'student_ids' => [$student->id],
            'due_date' => Carbon::today()->addDays(5)->toDateString(),
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::find(
            $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    /** Pembayaran lewat kanal gateway: masih `pending`, belum ada charge. */
    private function gatewayPayment(FinanceInvoice $invoice, ?float $amount = null): int
    {
        return (int) $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => $amount ?? self::TAGIHAN,
            'method' => 'payment_gateway',
            'bank_account_id' => $this->bank('BANK-OPS')->id,
            'gateway' => 'fake',
        ])->assertCreated()->json('data.id');
    }

    private function notificationsTo(?User $user, string $type): int
    {
        if ($user === null) {
            return 0;
        }

        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', FinanceActivityNotification::class)
            ->where('data->type', $type)
            ->count();
    }

    private function notificationsByReference(User $user, string $reference): int
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', FinanceActivityNotification::class)
            ->where('data->reference', $reference)
            ->count();
    }
}

/**
 * Gateway palsu untuk uji otomasi. Namanya unik: PHPUnit memuat seluruh berkas
 * tes dalam satu proses, jadi nama yang sama akan menabrak fake di tes lain.
 */
class FakeAutomationGateway implements PaymentGateway
{
    public function __construct(private readonly ?string $expiresAt = null) {}

    public function createPayment(array $intent): array
    {
        return [
            'reference' => $intent['reference'],
            'amount' => (float) $intent['amount'],
            'token' => 'auto-token',
            'redirect_url' => 'https://gateway.test/pay/auto-token',
            'provider_reference' => 'GTW-'.strtoupper($intent['reference']),
            'expires_at' => $this->expiresAt,
            'raw' => [],
        ];
    }

    public function checkStatus(string $reference): array
    {
        return ['reference' => $reference, 'status' => 'pending', 'amount' => 0.0, 'provider_reference' => null, 'raw' => []];
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
