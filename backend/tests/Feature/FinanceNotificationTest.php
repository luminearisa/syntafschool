<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinanceOperation;
use App\Models\FinancePayment;
use App\Models\FinanceRefund;
use App\Models\FinanceReRegistration;
use App\Models\Guardian;
use App\Models\PpdbParentAccount;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Notifications\FinanceActivityNotification;
use App\Services\Finance\FinanceNotificationService;
use App\Services\Finance\FinanceSettingsService;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/**
 * Notifikasi Keuangan (§30–§31 Phase 2) lewat alur nyata + kotak masuk HTTP.
 *
 * Yang diverifikasi: seorang tagihan mengabari santri dan walinya (kanal
 * `database` bawaan, tanpa engine baru); wali hanya menerima anaknya sendiri
 * (§24, §30); daftar ulang mengabari SATU kali (tidak dobel dengan notifikasi
 * invoice terbit); pembayaran pending hanya mengabari petugas verifikasinya;
 * kotak masuk terisolasi per penerima sehingga akun tanpa permission finance
 * (santri/wali) tetap bisa membaca tagihannya sendiri; dan perintah harian
 * idempoten — dokumen yang sama tidak dinotifikasi dua kali.
 *
 * §8 Phase 5 menutup surat-surat yang masih belum ada: angsuran rencana
 * pembayaran memakai tangga pengingat yang sama dengan tagihannya, refund
 * mengabari sekali per tahap keputusan, dan kegagalan kanal bayar dilaporkan
 * sebagai "gagal" — bukan sebagai penolakan petugas, karena yang menolak bukan
 * manusia.
 */
class FinanceNotificationTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $current;

    private AcademicYear $next;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);

        $this->current = AcademicYear::query()->firstOrCreate(
            ['name' => '2025/2026'],
            ['start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => true],
        );
        $this->next = AcademicYear::query()->firstOrCreate(
            ['name' => '2026/2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => false],
        );
    }

    protected function tearDown(): void
    {
        // Tangga pengingat membaca "hari ini". PHPUnit menjalankan seluruh tes
        // dalam satu proses, jadi jam palsu wajib dikembalikan sebelum tes
        // berikutnya — kalau tidak, tanggal uji ikut terbawa.
        Carbon::setTestNow();

        parent::tearDown();
    }

    // =================================================================
    // Penerima notifikasi
    // =================================================================

    public function test_invoice_terbit_mengabari_santri_dan_walinya(): void
    {
        $this->actingAsRole('finance-admin');
        $family = $this->family('NT001');

        $this->issuedInvoice($family['student'], '2026-09', 'PKT-NT-A');

        $this->assertSame(1, $this->notificationCount($family['student_user'], 'invoice_issued'));
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'invoice_issued'));

        // Orang lain yang tidak bertaut dengan santri ini tidak ikut kebagian.
        $this->assertSame(0, $this->notificationCount($this->unrelatedUser(), 'invoice_issued'));
    }

    public function test_wali_hanya_menerima_notifikasi_anaknya_sendiri(): void
    {
        $this->actingAsRole('finance-admin');
        $anakA = $this->family('NT002');
        $anakB = $this->family('NT003');

        // Satu tagihan untuk anak A saja.
        $this->issuedInvoice($anakA['student'], '2026-09', 'PKT-NT-B');

        $this->assertSame(1, $this->notificationCount($anakA['parent_user'], 'invoice_issued'));
        $this->assertSame(0, $this->notificationCount($anakB['parent_user'], 'invoice_issued'));
        $this->assertSame(0, $this->notificationCount($anakB['student_user'], 'invoice_issued'));
    }

    public function test_pembayaran_tunai_mengabari_santri_beserta_nomor_kuitansi(): void
    {
        $this->actingAsRole('finance-admin');
        $family = $this->family('NT004');
        $invoice = $this->issuedInvoice($family['student'], '2026-09', 'PKT-NT-C');

        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash('KAS-SEKOLAH')->id,
        ])->assertCreated();

        $this->assertSame(1, $this->notificationCount($family['student_user'], 'payment_received'));
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'payment_received'));

        $body = (array) DatabaseNotification::query()
            ->where('notifiable_id', $family['student_user']->id)
            ->where('data->type', 'payment_received')
            ->value('data');

        $this->assertStringContainsString('kuitansi RCPT-', $body['body']);
    }

    public function test_pembayaran_pending_hanya_mengabari_petugas_verifikasi(): void
    {
        $this->actingAsRole('finance-admin');
        $family = $this->family('NT005');
        $invoice = $this->issuedInvoice($family['student'], '2026-09', 'PKT-NT-D');

        // Penerima pesan petugas dipilih saat pembayaran lahir, jadi perannya
        // harus sudah melekat sebelumnya.
        $manager = $this->userOfRole('finance-manager');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'manual_transfer',
            'bank_account_id' => $this->bank('BANK-OPS')->id,
        ])->assertCreated()->json('data.id');

        // Belum lunas → santri/wali belum mengabari apa pun (§21).
        $this->assertSame(0, $this->notificationCount($family['student_user'], 'payment_received'));

        // Petugas pemegang finance.payment.verify dapat undangan verifikasi...
        $this->assertSame(1, $this->notificationCount($manager, 'payment_pending_verification'));

        // ...sedang pesan untuk petugas tidak dikirim ke santri/walinya.
        $this->assertSame(0, $this->notificationCount($family['student_user'], 'payment_pending_verification'));

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/payments/{$paymentId}/verify")->assertOk();

        $this->assertSame(1, $this->notificationCount($family['student_user'], 'payment_received'));
        // Notifikasi "menunggu verifikasi" tidak diulang saat sudah selesai.
        $this->assertSame(1, $this->notificationCount($manager, 'payment_pending_verification'));
    }

    public function test_penolakan_pembayaran_mengabari_santri_dengan_alasan(): void
    {
        $this->actingAsRole('finance-admin');
        $family = $this->family('NT006');
        $invoice = $this->issuedInvoice($family['student'], '2026-09', 'PKT-NT-E');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'manual_transfer',
            'bank_account_id' => $this->bank('BANK-OPS')->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/payments/{$paymentId}/reject", ['reason' => 'Nominal tidak cocok'])
            ->assertOk();

        $this->assertSame(1, $this->notificationCount($family['student_user'], 'payment_rejected'));

        $row = DatabaseNotification::query()
            ->where('notifiable_id', $family['student_user']->id)
            ->where('data->type', 'payment_rejected')
            ->first();

        $this->assertSame('Nominal tidak cocok', $row->data['reason'] ?? null);
    }

    // =================================================================
    // Daftar ulang (§2–§7): satu notifikasi, bukan dua
    // =================================================================

    public function test_daftar_ulang_mengabari_satu_kali_tanpa_notifikasi_invoice(): void
    {
        $this->actingAsRole('finance-admin');
        $family = $this->family('NT007', enrolled: true);

        $this->postJson('/api/v1/finance/re-registration/generate', $this->reRegistrationConfig([
            'student_id' => $family['student']->id,
        ]))->assertCreated();

        $this->assertSame(1, $this->notificationCount($family['student_user'], 're_registration_billed'));
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 're_registration_billed'));

        // Invoice tetap terbit (§16) tetapi tidak mengabari dua kali.
        $this->assertSame(0, $this->notificationCount($family['student_user'], 'invoice_issued'));
    }

    public function test_daftar_ulang_selesai_dan_kedaluwarsa_mengabari(): void
    {
        $this->actingAsRole('finance-admin');
        $selesai = $this->family('NT008', enrolled: true);
        $expired = $this->family('NT009', enrolled: true);

        $regId = $this->postJson('/api/v1/finance/re-registration/generate', $this->reRegistrationConfig([
            'student_id' => $selesai['student']->id,
        ]))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/re-registration/{$regId}/complete")->assertStatus(422);

        $reg = FinanceReRegistration::find($regId);
        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $reg->invoice_id,
            'amount' => 2000000,
            'method' => 'cash',
            'cash_account_id' => $this->cash('KAS-SEKOLAH')->id,
        ])->assertCreated();

        $this->postJson("/api/v1/finance/re-registration/{$regId}/complete", ['history_status' => 'naik'])
            ->assertOk();

        $this->assertSame(1, $this->notificationCount($selesai['student_user'], 're_registration_completed'));

        // Pendaftaran kedua dibuat dengan jatuh tempo kemarin → perintah harian
        // memindahkannya ke `expired` dan mengabari sekali.
        $staleId = $this->postJson('/api/v1/finance/re-registration/generate', $this->reRegistrationConfig([
            'student_id' => $expired['student']->id,
            'due_date' => Carbon::yesterday()->toDateString(),
        ]))->assertCreated()->json('data.id');

        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame('expired', (string) FinanceReRegistration::find($staleId)->status);
        $this->assertSame(1, $this->notificationCount($expired['student_user'], 're_registration_expired'));
    }

    // =================================================================
    // Pengingat jatuh tempo (perintah terjadwal, idempoten)
    // =================================================================

    public function test_pengingat_lewat_jatuh_tempo_hanya_sekali_walau_dijalankan_berulang(): void
    {
        $this->actingAsRole('finance-admin');
        $family = $this->family('NT010');
        $invoice = $this->issuedInvoice($family['student'], '2026-09', 'PKT-NT-F');

        FinanceInvoice::query()->whereKey($invoice->id)->update(['due_date' => Carbon::yesterday()]);

        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame('overdue', (string) $invoice->refresh()->status);
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'billing_overdue'));

        // Jalankan lagi di hari yang sama: status tidak berkedip, notifikasi tidak dobel.
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'billing_overdue'));
    }

    public function test_tangga_pengingat_terkirim_sesuai_anak_tangganya(): void
    {
        $this->actingAsRole('finance-admin');

        // Tangga dibacakan dari "hari ini", jadi jamnya diputar per adegan.
        Carbon::setTestNow('2026-09-23 21:00:00');
        $family = $this->family('NT014', true);
        $invoice = $this->issuedInvoice($family['student'], '2026-09', 'PKT-NT-J', '2026-09-30');
        $rung = fn (int $offset): string => $invoice->invoice_number.':reminder:'.sprintf('%+d', $offset);

        // H-7: tangga pertama.
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(1, $this->notificationCountByReference($family['parent_user'], $rung(-7)));

        // Hari yang sama, perintah yang sama: tidak ada pengingat kedua.
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(1, $this->notificationCountByReference($family['parent_user'], $rung(-7)));
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'collection_reminder'));

        // H-6 bukan anak tangga → hari berlalu tanpa pesan.
        Carbon::setTestNow('2026-09-24 21:00:00');
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'collection_reminder'));

        // H-3 dan H-1: masing-masing satu pesan, dan H-7 tidak mengulang dirinya.
        Carbon::setTestNow('2026-09-27 21:00:00');
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(1, $this->notificationCountByReference($family['parent_user'], $rung(-3)));

        Carbon::setTestNow('2026-09-29 21:00:00');
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(1, $this->notificationCountByReference($family['parent_user'], $rung(-1)));
        $this->assertSame(3, $this->notificationCount($family['parent_user'], 'collection_reminder'));

        // H+1: tagihan baru saja lewat jatuh tempo. `billing_overdue` tetap satu
        // (penanda perpindahan status dari markOverdue), tangganya sendiri
        // berjenis pengingat supaya dua hal berbeda tidak menumpang di tipe yang sama.
        Carbon::setTestNow('2026-10-01 21:00:00');
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame('overdue', (string) $invoice->refresh()->status);
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'billing_overdue'));
        $this->assertSame(1, $this->notificationCountByReference($family['parent_user'], $rung(1)));

        Carbon::setTestNow('2026-10-07 21:00:00');
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(1, $this->notificationCountByReference($family['parent_user'], $rung(7)));
        $this->assertSame(5, $this->notificationCount($family['parent_user'], 'collection_reminder'));
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'billing_overdue'));
    }

    public function test_anak_tangga_pengingat_datang_dari_konfigurasi(): void
    {
        $this->actingAsRole('finance-admin');

        // "Jangan hard-code": bilangannya milik Settings, bukan scheduler.
        app(FinanceSettingsService::class)->update(['invoice_reminder_offsets' => '-15,-2']);

        Carbon::setTestNow('2026-09-15 21:00:00');
        $family = $this->family('NT015', true);
        $invoice = $this->issuedInvoice($family['student'], '2026-09', 'PKT-NT-K', '2026-09-30');

        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(1, $this->notificationCountByReference(
            $family['parent_user'],
            $invoice->invoice_number.':reminder:-15',
        ));

        // H-7 default tidak lagi ada di tangga yang dikonfigurasi.
        Carbon::setTestNow('2026-09-23 21:00:00');
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'collection_reminder'));

        Carbon::setTestNow('2026-09-28 21:00:00');
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(2, $this->notificationCount($family['parent_user'], 'collection_reminder'));
    }

    public function test_tangga_pengingat_bisa_dimatikan_dan_tagihan_lunas_tidak_ditagih(): void
    {
        $this->actingAsRole('finance-admin');

        Carbon::setTestNow('2026-09-23 21:00:00');
        $lunas = $this->family('NT016', true);
        $invoiceLunas = $this->issuedInvoice($lunas['student'], '2026-09', 'PKT-NT-M', '2026-09-30');

        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoiceLunas->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash('KAS-SEKOLAH')->id,
        ])->assertCreated();

        $this->artisan('finance:daily-maintenance')->assertSuccessful();

        // Sisa nol → tidak ada satu pun pesan penagihan untuk keluarga ini.
        $this->assertSame(0, $this->notificationCount($lunas['parent_user'], 'collection_reminder'));
        $this->assertSame(1, $this->notificationCount($lunas['parent_user'], 'payment_received'));

        // Saklar tangga mati: H+1 tetap menandai overdue, tapi tidak mengirim pengingat.
        app(FinanceSettingsService::class)->update(['invoice_reminders_enabled' => false]);

        $berutang = $this->family('NT017', true);
        $this->issuedInvoice($berutang['student'], '2026-09', 'PKT-NT-N', '2026-09-30');

        Carbon::setTestNow('2026-10-01 21:00:00');
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(0, $this->notificationCount($berutang['parent_user'], 'collection_reminder'));
        $this->assertSame(1, $this->notificationCount($berutang['parent_user'], 'billing_overdue'));
    }

    public function test_pembayaran_gateway_kedaluwarsa_mengabari_santri(): void
    {
        $this->app->instance(PaymentGateway::class, new FakeNotificationGateway);

        $this->actingAsRole('finance-admin');
        $family = $this->family('NT013');
        $invoice = $this->issuedInvoice($family['student'], '2026-09', 'PKT-NT-I');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'payment_gateway',
            'bank_account_id' => $this->bank('BANK-OPS')->id,
            'gateway' => 'fake',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/finance/webhooks/payment/fake', [
            'reference' => FinancePayment::find($paymentId)->payment_number,
            'status' => 'expired',
        ])->assertOk();

        $this->assertSame(FinancePayment::STATUS_EXPIRED, FinancePayment::find($paymentId)->status);
        $this->assertSame(1, $this->notificationCount($family['student_user'], 'payment_expired'));

        // Tagihan tetap terbuka, jadi tidak ada pesan "pembayaran diterima".
        $this->assertSame(0, $this->notificationCount($family['student_user'], 'payment_received'));
        $this->assertEquals(0.00, (float) $invoice->refresh()->paid_amount);
    }

    // =================================================================
    // §8: angsuran, refund, dan kegagalan kanal bayar
    // =================================================================

    public function test_angsuran_diingatkan_lewat_tangga_yang_sama_dengan_tagihan(): void
    {
        $this->actingAsRole('finance-admin');
        app(FinanceSettingsService::class)->update(['payment_plan_enabled' => true]);

        Carbon::setTestNow('2026-09-23 21:00:00');
        $family = $this->family('NT018', true);
        // Jatuh tempo invoice sengaja jauh: yang bergerak hanya angsurannya,
        // supaya pesan cicilan tidak tertukar dengan pengingat tagihan.
        $invoice = $this->issuedInvoice($family['student'], '2026-09', 'PKT-NT-P', '2026-12-31');

        $plan = $this->postJson('/api/v1/finance/payment-plans', [
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => '2026-09-30',
        ])->assertCreated()->json('data');

        $rung = fn (int $offset): string => $plan['plan_number'].':1:'.sprintf('%+d', $offset);

        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(1, $this->notificationCountByReference($family['parent_user'], $rung(-7)));
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'payment_plan_due'));

        // Perintah yang sama, hari yang sama: satu angsuran satu surat.
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'payment_plan_due'));

        // Tangga berikut milik angsuran yang sama, bukan angsuran berikutnya.
        Carbon::setTestNow('2026-09-27 21:00:00');
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(1, $this->notificationCountByReference($family['parent_user'], $rung(-3)));

        Carbon::setTestNow('2026-09-29 21:00:00');
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(3, $this->notificationCount($family['parent_user'], 'payment_plan_due'));

        // H+1: tipe pesannya berganti (menagih yang tertunda, bukan mengingatkan
        // yang akan datang) dan angsuran 2–3 belum menyentuh tangganya.
        Carbon::setTestNow('2026-10-01 21:00:00');
        $this->artisan('finance:daily-maintenance')->assertSuccessful();
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'payment_plan_overdue'));
        $this->assertSame(1, $this->notificationCountByReference($family['parent_user'], $rung(1)));
        $this->assertSame(3, $this->notificationCount($family['parent_user'], 'payment_plan_due'));
        $this->assertSame(0, $this->notificationCount($family['parent_user'], 'collection_reminder'));

        // Yang ditagih adalah angsuran, bukan sisa invoice.
        $body = (array) DatabaseNotification::query()
            ->where('notifiable_id', $family['parent_user']->id)
            ->where('data->type', 'payment_plan_overdue')
            ->value('data');

        $this->assertStringContainsString('Angsuran 1', $body['body']);
        $this->assertStringContainsString((string) $plan['plan_number'], $body['body']);
        $this->assertSame(283333.33, (float) $body['amount']);
        $this->assertSame(1, $body['installment_sequence']);
        $this->assertSame(1, $body['reminder_offset']);
    }

    public function test_refund_mengabari_pendayar_satu_kali_per_tahap(): void
    {
        $this->actingAsRole('finance-admin');
        $family = $this->family('NT019');
        $invoice = $this->issuedInvoice($family['student'], '2026-09', 'PKT-NT-Q');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash('KAS-SEKOLAH')->id,
        ])->assertCreated()->json('data.id');

        $refundId = $this->postJson("/api/v1/finance/payments/{$paymentId}/refunds", [
            'amount' => 850000,
            'reason' => 'Santri salah bayar paket',
        ])->assertCreated()->json('data.id');

        // Diajukan saja belum layak jadi surat: yang diumumkan adalah keputusan.
        $this->assertSame(0, $this->notificationCount($family['parent_user'], 'refund_approved'));

        $refund = FinanceRefund::find($refundId);

        $this->postJson("/api/v1/finance/refunds/{$refundId}/approve")->assertOk();
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'refund_approved'));
        $this->assertSame(1, $this->notificationCount($family['student_user'], 'refund_approved'));

        $this->postJson("/api/v1/finance/refunds/{$refundId}/complete")->assertOk();
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'refund_completed'));

        // Dua tahap = dua surat berbeda, bukan satu surat yang dihitung dua kali.
        $this->assertSame(1, $this->notificationCountByReference($family['parent_user'], $refund->refund_number.':approved'));
        $this->assertSame(1, $this->notificationCountByReference($family['parent_user'], $refund->refund_number.':completed'));

        $row = (array) DatabaseNotification::query()
            ->where('notifiable_id', $family['parent_user']->id)
            ->where('data->type', 'refund_completed')
            ->value('data');

        $this->assertSame((int) $refundId, $row['refund_id']);
        $this->assertSame(850000.0, (float) $row['amount']);

        // Wali anak lain tidak ikut mengetahui refund ini.
        $this->assertSame(0, $this->notificationCount($this->unrelatedUser(), 'refund_completed'));
    }

    public function test_kegagalan_kanal_bayar_disebut_gagal_bukan_ditolak_petugas(): void
    {
        $this->app->instance(PaymentGateway::class, new FakeNotificationGateway);

        $this->actingAsRole('finance-admin');
        $family = $this->family('NT020');
        $invoice = $this->issuedInvoice($family['student'], '2026-09', 'PKT-NT-R');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'payment_gateway',
            'bank_account_id' => $this->bank('BANK-OPS')->id,
            'gateway' => 'fake',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/finance/webhooks/payment/fake', [
            'reference' => FinancePayment::find($paymentId)->payment_number,
            'status' => 'failed',
        ])->assertOk();

        $this->assertSame(FinancePayment::STATUS_FAILED, (string) FinancePayment::find($paymentId)->status);

        // §8 memisahkan "payment failed" dari "payment rejected": yang pertama
        // diberitahukan oleh providernya sendiri, yang kedua oleh petugas.
        $this->assertSame(1, $this->notificationCount($family['student_user'], 'payment_failed'));
        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'payment_failed'));
        $this->assertSame(0, $this->notificationCount($family['student_user'], 'payment_rejected'));

        $body = (array) DatabaseNotification::query()
            ->where('notifiable_id', $family['student_user']->id)
            ->where('data->type', 'payment_failed')
            ->value('data');

        $this->assertStringContainsString('gagal diproses kanal pembayaran', $body['body']);

        // Kata "ditolak" tidak pernah dibaca wali untuk kegagalan kanal.
        $this->assertStringNotContainsString('ditolak', $body['body']);

        // Tagihannya masih terbuka: tidak ada kuitansi, tidak ada pelunasan.
        $this->assertSame(0, $this->notificationCount($family['student_user'], 'payment_received'));
        $this->assertEquals(0.00, (float) $invoice->refresh()->paid_amount);
    }

    // =================================================================
    // §8: kanal & template
    // =================================================================

    public function test_kalimat_notifikasi_adalah_template_yang_bisa_ditimpa_sekolah(): void
    {
        $this->actingAsRole('finance-admin');

        app(FinanceSettingsService::class)->update([
            'notification_templates' => json_encode([
                'invoice_issued' => [
                    'title' => 'Tagihan {period_label}',
                    'body' => 'Yth. wali dari {student_name}. Invoice {invoice_number} sebesar {amount_text} jatuh tempo {due_date}.',
                ],
            ]),
        ]);

        $family = $this->family('NT021', true);
        $invoice = $this->issuedInvoice($family['student'], '2026-09', 'PKT-NT-S');

        $row = (array) DatabaseNotification::query()
            ->where('notifiable_id', $family['parent_user']->id)
            ->where('data->type', 'invoice_issued')
            ->value('data');

        $this->assertStringContainsString('Yth. wali dari Santri NT021.', $row['body']);
        $this->assertStringContainsString($invoice->invoice_number, $row['body']);
        $this->assertStringContainsString('Rp 850.000', $row['body']);
        $this->assertStringContainsString($invoice->due_date->toDateString(), $row['body']);

        // Token yang tidak dikenal dibiarkan terbaca, bukan dibuang: kalimat yang
        // kehilangan nomornya diam-diam lebih sulit dicari daripada yang masih
        // menampilkan `{period_label}`.
        $this->assertSame('Tagihan {period_label}', $row['title']);

        // JSON yang salah ketik tidak menghentikan surat: yang tersisa hanyalah
        // kalimat bawaan.
        app(FinanceSettingsService::class)->update(['notification_templates' => '{"invoice_issued": {"title": "abc"']);

        $lain = $this->family('NT023', true);
        $this->issuedInvoice($lain['student'], '2026-09', 'PKT-NT-V');

        $pulih = (array) DatabaseNotification::query()
            ->where('notifiable_id', $lain['parent_user']->id)
            ->where('data->type', 'invoice_issued')
            ->value('data');

        $this->assertSame('Tagihan baru diterbitkan', $pulih['title']);
        $this->assertStringContainsString('terbit. Jatuh tempo', $pulih['body']);
    }

    public function test_kanal_email_untuk_surat_keluarga_saat_dibuka(): void
    {
        $family = $this->family('NT022', true);

        // Saklar belum dibuka: hanya kotak masuk, tidak ada surat yang berangkat.
        $this->assertSame(['database'], $this->deliveredChannels(FinanceActivityNotification::TYPE_INVOICE_ISSUED, $family['parent_user']));

        app(FinanceSettingsService::class)->update(['notification_email_enabled' => true]);
        $this->assertSame(
            ['database', 'mail'],
            $this->deliveredChannels(FinanceActivityNotification::TYPE_INVOICE_ISSUED, $family['parent_user']),
        );

        // Surat petugas tetap di layar: mengirim antrean verifikasi ke email
        // berarti mengirim satu surat per pembayaran ke tiap pemegang haknya.
        $this->assertSame(
            ['database'],
            $this->deliveredChannels(FinanceActivityNotification::TYPE_PAYMENT_PENDING_VERIFICATION, $family['parent_user']),
        );

        // Akun tanpa alamat email tidak membuat suratnya gagal — hanya tidak
        // ada salinan untuknya.
        $this->assertSame(
            ['database'],
            $this->deliveredChannels(FinanceActivityNotification::TYPE_INVOICE_ISSUED, new \stdClass),
        );

        // Isi email = isi kotak masuk, dengan nomor dokumen sebagai referensi.
        $mail = (new FinanceActivityNotification(
            FinanceActivityNotification::TYPE_INVOICE_ISSUED,
            'Tagihan baru diterbitkan',
            'Invoice INV-1 sebesar Rp 850.000 terbit.',
            ['reference' => 'INV-1'],
            ['database', 'mail'],
        ))->toMail($family['parent_user']);

        $this->assertSame('Tagihan baru diterbitkan', $mail->subject);
        $this->assertStringContainsString('Invoice INV-1', implode("\n", $mail->introLines));
        $this->assertStringContainsString('Referensi: INV-1', implode("\n", $mail->introLines));

        // Dan kanal itu benar-benar dipakai saat peristiwa terjadi: dua penerima,
        // dua surat, tanpa satu pun kegagalan kanal tercatat (§29).
        $this->actingAsRole('finance-admin');
        $this->issuedInvoice($family['student'], '2026-09', 'PKT-NT-W');

        $sent = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(2, $sent);

        $subjects = $sent
            ->map(fn (SentMessage $m): string => (string) $m->getOriginalMessage()->getSubject())
            ->unique()
            ->values()
            ->all();
        $this->assertSame(['Tagihan baru diterbitkan'], $subjects);

        $recipients = $sent
            ->flatMap(fn (SentMessage $m): array => array_map(
                static fn (object $address): string => (string) $address->getAddress(),
                $m->getOriginalMessage()->getTo(),
            ))
            ->values()
            ->all();
        $expected = [$family['student_user']->email, $family['parent_user']->email];
        sort($expected);
        sort($recipients);
        $this->assertSame($expected, $recipients);

        $this->assertSame(1, $this->notificationCount($family['parent_user'], 'invoice_issued'));
        $this->assertSame(0, FinanceOperation::query()->where('name', FinanceOperation::EVENT_NOTIFICATION_SEND)->count());
    }

    // =================================================================
    // Kotak masuk
    // =================================================================

    public function test_kotak_masuk_hanya_memuat_notifikasi_milik_login(): void
    {
        $this->actingAsRole('finance-admin');
        $anakA = $this->family('NT011');
        $anakB = $this->family('NT012');
        $this->issuedInvoice($anakA['student'], '2026-09', 'PKT-NT-G');
        $this->issuedInvoice($anakB['student'], '2026-09', 'PKT-NT-H');

        // Wali anak A: akun tanpa permission finance apa pun tetap bisa membaca
        // tagihannya sendiri — kotak masuk terisolasi per penerima.
        $this->actingAs($anakA['parent_user']);

        $list = $this->getJson('/api/v1/finance/notifications?per_page=50')->assertOk();
        $list->assertJsonPath('meta.unread_count', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'invoice_issued')
            ->assertJsonPath('data.0.type_label', 'Tagihan terbit')
            ->assertJsonPath('data.0.amount', 850000);

        $this->getJson('/api/v1/finance/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 1)
            ->assertJsonPath('data.total', 1);

        $ownId = (string) $list->json('data.0.id');
        $foreignId = (string) DatabaseNotification::query()
            ->where('notifiable_id', $anakB['parent_user']->id)
            ->value('id');

        // Milik orang lain tidak bisa ditandai terbaca.
        $this->postJson("/api/v1/finance/notifications/{$foreignId}/read")->assertNotFound();

        $this->postJson("/api/v1/finance/notifications/{$ownId}/read")->assertOk();
        $this->getJson('/api/v1/finance/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 0);

        $this->getJson('/api/v1/finance/notifications?unread_only=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Notifikasi milik anak B tidak pernah muncul di inbox wali anak A.
        $this->postJson('/api/v1/finance/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_as_read', 0);

        // Milik sendiri: beri tahu wali anak A tagihan kedua, lalu read-all.
        $this->actingAsRole('finance-admin');
        $this->issuedInvoice($anakA['student'], '2026-10', 'PKT-NT-G');
        $this->actingAs($anakA['parent_user']);
        $this->getJson('/api/v1/finance/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 1);
        $this->postJson('/api/v1/finance/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_as_read', 1)
            ->assertJsonPath('data.unread', 0);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** @return array{student: Student, student_user: User, guardian: Guardian, parent_user: User} */
    private function family(string $nis, bool $enrolled = false): array
    {
        $student = Student::query()->firstOrCreate(
            ['nis' => $nis],
            ['full_name' => 'Santri '.$nis, 'is_active' => true],
        );

        $studentUser = User::factory()->create([
            'name' => 'Akun '.$nis,
            'email' => strtolower($nis).'@schoolerp.test',
            'is_active' => true,
            'userable_type' => Student::class,
            'userable_id' => $student->id,
        ]);

        $guardian = Guardian::query()->create([
            'full_name' => 'Wali '.$nis,
            'email' => strtolower($nis).'-wali@schoolerp.test',
        ]);

        DB::table('student_guardians')->insert([
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'is_primary' => true,
        ]);

        $parentUser = User::factory()->create([
            'name' => 'Wali '.$nis,
            'email' => strtolower($nis).'-parent@schoolerp.test',
            'is_active' => true,
        ]);

        PpdbParentAccount::query()->create([
            'user_id' => $parentUser->id,
            'guardian_id' => $guardian->id,
            'account_status' => PpdbParentAccount::STATUS_ACTIVE,
            'verified_at' => Carbon::now(),
        ]);

        if ($enrolled) {
            $class = SchoolClass::query()->firstOrCreate(
                ['code' => 'RMP-'.$nis],
                ['academic_year_id' => $this->current->id, 'name' => 'Rombel '.$nis, 'capacity' => 30, 'is_active' => true],
            );

            ClassStudent::query()->firstOrCreate(
                ['student_id' => $student->id, 'academic_year_id' => $this->current->id],
                ['school_class_id' => $class->id, 'status' => 'aktif'],
            );
        }

        return ['student' => $student, 'student_user' => $studentUser, 'guardian' => $guardian, 'parent_user' => $parentUser];
    }

    private function notificationCount(User $user, string $type): int
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', FinanceActivityNotification::class)
            ->where('data->type', $type)
            ->count();
    }

    /**
     * Kanal yang benar-benar dipakai untuk akun ini: kebijakan service ∩ `via()`.
     *
     * Dua lapis karena `via()` masih menahan surat ke akun tanpa alamat email —
     * memeriksa `channelsFor()` saja akan menyatakan "email jalan" untuk wali
     * yang datanya tidak lengkap.
     *
     * @return list<string>
     */
    private function deliveredChannels(string $type, object $notifiable): array
    {
        $channels = app(FinanceNotificationService::class)->channelsFor($type);

        return (new FinanceActivityNotification($type, '', '', [], $channels))->via($notifiable);
    }

    /**
     * Penghitung per referensi. Tipe saja tidak cukup untuk menguji tangga
     * pengingat: H-7 dan H-3 berbagi tipe yang sama, dan kunci idempotensinya
     * justru ada di referensi.
     */
    private function notificationCountByReference(User $user, string $reference): int
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', FinanceActivityNotification::class)
            ->where('data->reference', $reference)
            ->count();
    }

    private function unrelatedUser(): User
    {
        return User::factory()->create(['email' => 'orang-lain@schoolerp.test', 'is_active' => true]);
    }

    /**
     * Pengguna dengan sebuah peran. RolePermissionSeeder tidak menyiapkan
     * penggunanya, jadi peran disematkan di sini — dan harus sebelum peristiwa
     * pemicu notifikasi, karena `notifyStaff()` memilih penerima berdasar peran
     * saat itu.
     */
    private function userOfRole(string $role): User
    {
        $email = str_replace('-', '.', $role).'@schoolerp.test';

        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncRoles([$role]);

        return $user;
    }

    private function actingAsRole(string $role): User
    {
        $user = $this->userOfRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function feeType(string $code): FinanceFeeType
    {
        return FinanceFeeType::query()->where('code', $code)->firstOrFail();
    }

    private function cash(string $code): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', $code)->firstOrFail();
    }

    private function bank(string $code): FinanceBankAccount
    {
        return FinanceBankAccount::query()->where('code', $code)->firstOrFail();
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

    /** Billing tergenerate + invoice terbit. */
    private function issuedInvoice(Student $student, string $period, string $pkg, string $due = '2026-09-30'): FinanceInvoice
    {
        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $this->package($pkg)->id,
            'period_key' => $period,
            'student_ids' => [$student->id],
            'due_date' => $due,
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::find(
            $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    private function reRegistrationConfig(array $overrides = []): array
    {
        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => 'PKT-NT-REG'],
            ['name' => 'Daftar Ulang Notifikasi', 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $package->items()->createMany([
                ['fee_type_id' => $this->feeType('DAFTAR_ULANG')->id, 'amount' => 2000000, 'frequency' => 'once', 'is_active' => true],
            ]);
        }

        return array_merge([
            'current_academic_year_id' => $this->current->id,
            'next_academic_year_id' => $this->next->id,
            'fee_package_id' => $package->id,
            'due_date' => Carbon::today()->addDays(30)->toDateString(),
        ], $overrides);
    }
}

/**
 * Gateway palsu untuk uji notifikasi: tanda tangan selalu sah dan notifikasi
 * dinormalkan apa adanya. Namanya unik karena PHPUnit memuat seluruh berkas tes
 * dalam satu proses — nama yang sama akan menabrak fake di tes pembayaran.
 */
class FakeNotificationGateway implements PaymentGateway
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
