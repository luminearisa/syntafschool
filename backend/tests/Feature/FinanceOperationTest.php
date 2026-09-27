<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\FinanceBankAccount;
use App\Models\FinanceBilling;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinanceOperation;
use App\Models\FinancePayment;
use App\Models\FinancePeriod;
use App\Models\FinancialTransaction;
use App\Models\Student;
use App\Models\User;
use App\Notifications\FinanceActivityNotification;
use App\Services\Finance\FinanceGatewayService;
use App\Services\Finance\FinanceNotificationService;
use App\Services\Finance\FinanceOperationService;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Otomasi & observability (§28, §29 Phase 5).
 *
 * Empat hal yang dijaga di sini:
 *
 * 1. **Pekerjaan berat punya status** (Queued → Processing → Completed/Failed) dan
 *    punya kemajuan, bukan hanya "request-nya sudah pulang". Register-nya juga
 *    menyimpan hasil dan kesalahan, jadi "apa yang terjadi tadi malam" terbaca
 *    tanpa membuka log server.
 * 2. **Menekan ulang tidak melahirkan transaksi kedua** (§29). Pekerjaan yang
 *    gagal diulang dari baris yang sama (`attempts` 2) dan berakhir dengan satu
 *    invoice + satu jurnal; memanggil pekerjaan yang sudah selesai sekali lagi
 *    tetap menagih santri yang sama hanya satu kali.
 * 3. **Kegagalan dicatat pada dokumen yang nyata** (§29: apa, kapan, yang mana,
 *    kenapa, boleh diulang?) — charge gateway yang mati dan kanal notifikasi yang
 *    mati sama-sama meninggalkan jejak, dan yang terakhir tidak boleh membatalkan
 *    tagihan yang sudah sah.
 * 4. **Hak membaca dan hak menjalankan adalah dua hak berbeda** (§3, §66).
 *
 * Driver antrian pada berkas test ini `sync` (phpunit.xml), jadi pekerjaan yang
 * diantrekan benar-benar selesai sebelum baris berikutnya dibaca — status akhir
 * yang diuji, bukan status "mengantre" sementara.
 */
class FinanceOperationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);
    }

    // =================================================================
    // §28 — pekerjaan berat lewat antrian
    // =================================================================

    public function test_pekerjaan_massal_melaporkan_kemajuannya_sampai_tuntas(): void
    {
        $actor = $this->actingAsRole('finance-officer');
        $package = $this->package('PKT-OP-1');
        $students = [$this->student('OP001'), $this->student('OP002'), $this->student('OP003')];

        $response = $this->postJson('/api/v1/finance/operations', [
            'job' => FinanceOperation::JOB_BILLING_GENERATE,
            'payload' => [
                'fee_package_id' => $package->id,
                'period_key' => 'OP-2026-09',
                'student_ids' => array_map(fn (Student $s): int => (int) $s->id, $students),
            ],
        ])->assertCreated();

        $response
            ->assertJsonPath('data.kind', FinanceOperation::KIND_JOB)
            ->assertJsonPath('data.status', FinanceOperation::STATUS_COMPLETED)
            ->assertJsonPath('data.status_label', 'Selesai')
            ->assertJsonPath('data.name_label', 'Generate billing massal')
            ->assertJsonPath('data.total_rows', 3)
            ->assertJsonPath('data.processed_rows', 3)
            ->assertJsonPath('data.progress_percent', 100)
            ->assertJsonPath('data.result.generated', 3)
            ->assertJsonPath('data.attempts', 1)
            ->assertJsonPath('data.error', null)
            // Selesai = tidak ada yang perlu diulang. `retryable` adalah tombol
            // "perlu ditangani", bukan pernyataan tentang masa lalu.
            ->assertJsonPath('data.retryable', false)
            ->assertJsonPath('data.created_by', $actor->id);

        $this->assertSame(3, FinanceBilling::query()->count());
        // Argumannya tersimpan di barisnya, bukan hanya lewat di request: itu
        // yang dipakai untuk menjalankan ulang nanti.
        $this->assertSame((int) $actor->id, (int) $response->json('data.payload.actor_id'));

        $list = $this->getJson('/api/v1/finance/operations')->assertOk();
        $list->assertJsonPath('data.0.id', $response->json('data.id'));
        $this->assertContains(FinanceOperation::JOB_BILLING_GENERATE, $list->json('meta.jobs'));
        $this->assertSame(
            'Selesai',
            $list->json('meta.status_labels.'.FinanceOperation::STATUS_COMPLETED),
        );

        // "Kapan" (§29) bisa disaring. Tanggal acuannya `queued_at` — menit
        // pekerjaan itu tercatat mulai, bukan menit ia selesai: yang dicari
        // bendahara adalah "run yang mana", dan sebuah run punya satu tanggal mulai.
        $this->getJson('/api/v1/finance/operations?date_from='.Carbon::today()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/finance/operations?date_from='.Carbon::tomorrow()->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Baris yang sudah selesai tidak punya tombol ulang — yang ada adalah
        // panggilan baru, dan penolakannya terjadi sebelum apa pun dijalankan.
        $this->postJson("/api/v1/finance/operations/{$response->json('data.id')}/retry")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['operation']);
    }

    public function test_sebab_diperbaiki_lalu_pekerjaan_diulang_menerbitkan_satu_invoice(): void
    {
        $actor = $this->actingAsRole('finance-admin');
        $billingId = (int) $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $this->package('PKT-OP-2')->id,
            'period_key' => 'OP-2026-09',
            'student_ids' => [$this->student('OP011')->id],
            'due_date' => '2026-09-30',
        ])->assertOk()->json('data.billings.0');

        // Periode dikunci → penerbitan invoice ditolak ledger. Billing-nya tetap
        // sah ada; yang gagal hanya posting-nya.
        $period = FinancePeriod::query()
            ->whereDate('start_date', '<=', '2026-09-15')
            ->whereDate('end_date', '>=', '2026-09-15')
            ->firstOrFail();
        $this->postJson("/api/v1/finance/periods/{$period->id}/close")->assertOk();

        $operations = app(FinanceOperationService::class);

        try {
            $operations->dispatch(FinanceOperation::JOB_INVOICE_ISSUE, ['billing_ids' => [$billingId]], $actor);
            $this->fail('Penerbitan invoice dalam periode tertutup harus berakhir gagal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Invoice gagal terbit 1 dari 1 baris', $e->getMessage());
        }

        $failed = FinanceOperation::query()->jobs()->failed()->firstOrFail();
        $this->assertSame(FinanceOperation::STATUS_FAILED, $failed->status);
        $this->assertTrue((bool) $failed->retryable);
        $this->assertSame(0, FinanceInvoice::query()->count());
        $this->assertSame(0, FinancialTransaction::query()->count());

        $this->postJson("/api/v1/finance/periods/{$period->id}/reopen")->assertOk();

        // Retry memakai argumen yang tersimpan di baris yang sama — tidak ada
        // baris pekerjaan baru, `attempts` yang bertambah.
        $this->postJson("/api/v1/finance/operations/{$failed->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', FinanceOperation::STATUS_COMPLETED)
            ->assertJsonPath('data.attempts', 2)
            ->assertJsonPath('data.result.created', 1);

        $this->assertSame(1, FinanceInvoice::query()->count());
        // Satu pengakuan pendapatan, bukan dua: retry tidak pernah menggandakan
        // jurnal (§16, §29).
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(1, FinanceOperation::query()->jobs()->count());

        // Memanggil pekerjaan yang sama sekali lagi (tombol ditekan dua kali)
        // tetap menghasilkan satu invoice: yang sudah ada dilaporkan sebagai
        // `already`, bukan diterbitkan ulang.
        $operations->dispatch(FinanceOperation::JOB_INVOICE_ISSUE, ['billing_ids' => [$billingId]], $actor);

        $this->assertSame(1, FinanceInvoice::query()->count());
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(2, FinanceOperation::query()->jobs()->count());
        $this->assertSame(
            0,
            (int) FinanceOperation::query()->jobs()->latest('id')->first()->result['created'],
        );
    }

    public function test_pekerjaan_yang_tidak_dikenal_ditolak_sebelum_barisnya_dibuat(): void
    {
        $this->actingAsRole('finance-officer');

        // Nama bebas tidak pernah dipetakan ke kelas mana pun: tanpa whitelist,
        // endpoint ini menjadi mesin pembuat objek dari string apa pun.
        $this->postJson('/api/v1/finance/operations', ['job' => User::class])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job']);

        $this->postJson('/api/v1/finance/operations', ['job' => 'payments.wipe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job']);

        $this->assertSame(0, FinanceOperation::query()->count());
    }

    public function test_perintah_harian_boleh_dijalankan_lewat_antrian_dan_keluarannya_tersimpan(): void
    {
        $actor = $this->actingAsRole('finance-admin');

        $operation = app(FinanceOperationService::class)
            ->dispatch(FinanceOperation::JOB_DAILY_MAINTENANCE, [], $actor);

        $this->assertSame(FinanceOperation::STATUS_COMPLETED, $operation->status);
        $this->assertSame(1, (int) $operation->total_rows);
        $this->assertSame(1, (int) $operation->processed_rows);
        $this->assertSame(0, (int) $operation->result['exit_code']);
        // Keluaran perintah adalah ringkasan hari itu; ia disimpan di register,
        // bukan mengalir di terminal lalu hilang.
        $this->assertStringContainsString('Billing kedaluwarsa:', (string) $operation->result['output']);
    }

    /**
     * Tugas terjadwal Keuangan tidak dijalankan inline oleh scheduler — ia
     * mengantrekan pekerjaannya lewat register, sehingga "tadi malam jam 06:00 jadi
     * tidak?" terjawab di layar dan bukan di log server (§28). Yang diuji di sini
     * isi jadwalnya sendiri: closure terdaftar di `routes/console.php` dipanggil
     * apa adanya, tanpa menyalin permukaannya ke test.
     */
    public function test_tugas_terjadwal_mengantrekan_pekerjaannya_lewat_register(): void
    {
        $events = collect(app(Schedule::class)->events());

        $jobs = [
            'finance:generate-spp' => FinanceOperation::JOB_SPP_GENERATE,
            'finance:daily-maintenance' => FinanceOperation::JOB_DAILY_MAINTENANCE,
        ];

        foreach ($jobs as $label => $job) {
            $event = $events->first(
                fn (object $e): bool => str_starts_with((string) ($e->description ?? ''), $label),
            );

            $this->assertNotNull($event, "Tugas terjadwal \"{$label}\" tidak terdaftar di scheduler.");
            // Dijalankan lewat `run()`, pintu yang sama dengan `schedule:run` —
            // bukan dengan mengintip properti callback-nya.
            $this->assertInstanceOf(CallbackEvent::class, $event);

            $event->run($this->app);

            $row = FinanceOperation::query()->jobs()->latest('id')->firstOrFail();
            $this->assertSame($job, $row->name);
            // Driver `sync` menyelesaikan pekerjaannya di dalam pemanggilan ini,
            // jadi yang dibaca sudah status akhir — bukan "Mengantre" yang basi.
            $this->assertSame(FinanceOperation::STATUS_COMPLETED, $row->status);
        }

        // Jalur antrian tidak punya hak istimewa: Settings yang belum mengaktifkan
        // SPP otomatis tetap menghentikan penagihan, dan alasannya ikut tersimpan
        // sebagai hasil pekerjaan — bukan sebagai baris kosong.
        $spp = FinanceOperation::query()->jobs()
            ->where('name', FinanceOperation::JOB_SPP_GENERATE)
            ->firstOrFail();

        $this->assertFalse((bool) ($spp->result['enabled'] ?? true));
        $this->assertStringContainsString('tidak aktif', (string) $spp->result['reason']);
        $this->assertSame(0, FinanceBilling::query()->count());
    }

    // =================================================================
    // §28/§29 — kegagalan: dicatat, dan boleh (atau tidak boleh) diulang
    // =================================================================

    public function test_kegagalan_pekerjaan_menyimpan_pesan_dan_tanda_boleh_diulang(): void
    {
        try {
            app(FinanceOperationService::class)->dispatch(
                FinanceOperation::JOB_INVOICE_ISSUE,
                ['billing_ids' => []],
                $this->userOfRole('finance-admin'),
            );
            $this->fail('Pekerjaan tanpa billing seharusnya berakhir gagal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Daftar billing kosong', $e->getMessage());
        }

        $operation = FinanceOperation::query()->latest('id')->firstOrFail();

        $this->assertSame(FinanceOperation::KIND_JOB, $operation->kind);
        $this->assertSame(FinanceOperation::STATUS_FAILED, $operation->status);
        $this->assertStringContainsString('Daftar billing kosong', (string) $operation->error);
        $this->assertSame(RuntimeException::class, $operation->error_kind);
        $this->assertSame(1, (int) $operation->attempts);
        $this->assertNotNull($operation->finished_at);
        $this->assertNotNull($operation->duration_ms);
        $this->assertTrue((bool) $operation->retryable);
        $this->assertSame(0, FinanceInvoice::query()->count());
    }

    public function test_peristiwa_gagal_dan_pekerjaan_yang_belum_boleh_diulang_ditolak_retry(): void
    {
        $operations = app(FinanceOperationService::class);
        $actor = $this->userOfRole('finance-admin');

        $incident = $operations->recordIncident(
            FinanceOperation::EVENT_SCHEDULED_JOB,
            'Perintah harian mati di tengah jalan.',
        );
        $this->assertNotNull($incident);
        $this->assertSame(FinanceOperation::KIND_INCIDENT, $incident->kind);
        $this->assertSame('Perintah harian mati di tengah jalan.', $incident->error);

        try {
            $operations->retry($incident, $actor);
            $this->fail('Peristiwa gagal tidak boleh di-dispatch ulang.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('operation', $e->errors());
        }

        // `queue()` saja (tanpa dispatch) menghasilkan baris yang belum ditandai
        // boleh diulang — gate-nya nyata, bukan hiasan.
        $raw = $operations->queue(FinanceOperation::JOB_PAYMENTS_EXPIRE, [], $actor);
        $this->assertFalse((bool) $raw->retryable);

        try {
            $operations->retry($raw->fresh(), $actor);
            $this->fail('Pekerjaan tanpa tanda retryable tidak boleh diulang.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tidak ditandai boleh diulang', $e->getMessage());
        }
    }

    public function test_charge_gateway_yang_gagal_dicatat_tanpa_menggerakkan_angka(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('OP021'), 'PKT-OP-3');
        $payment = FinancePayment::find($this->gatewayPayment($invoice));

        $this->app->instance(PaymentGateway::class, new BrokenOperationGateway);

        try {
            app(FinanceGatewayService::class)->charge($payment);
            $this->fail('Charge gateway yang mati harus tetap melempar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('kanal pembayaran tidak tersedia', $e->getMessage());
        }

        $incident = FinanceOperation::query()
            ->where('name', FinanceOperation::EVENT_GATEWAY_CHARGE)
            ->firstOrFail();

        $this->assertSame(FinanceOperation::KIND_INCIDENT, $incident->kind);
        $this->assertSame(FinanceOperation::STATUS_FAILED, $incident->status);
        // "Dokumen mana" dijawab oleh tautan, bukan oleh teks error.
        $this->assertSame(FinancePayment::class, $incident->reference_type);
        $this->assertSame((int) $payment->id, (int) $incident->reference_id);
        $this->assertSame((string) $payment->payment_number, (string) $incident->reference_number);
        $this->assertTrue((bool) $incident->retryable);

        // Dan angka tetap di tempatnya: payment masih menunggu, belum ada jurnal.
        $payment->refresh();
        $this->assertSame(FinancePayment::STATUS_PENDING, $payment->status);
        $this->assertNull($payment->gateway_transaction_id);
        $this->assertSame(0.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame(0, FinancialTransaction::query()->where('reference_type', FinancePayment::class)->count());
    }

    public function test_kanal_notifikasi_yang_mati_tidak_membatalkan_tagihan(): void
    {
        $this->app->instance(Dispatcher::class, new DeadChannelDispatcher);
        $this->actingAsRole('finance-admin');

        // Tagihan tetap terbit walau suratnya tidak terkirim: notifikasi adalah
        // efek samping dari sebuah aksi keuangan, bukan bagian dari angkanya.
        $invoice = $this->issuedInvoice($this->student('OP031'), 'PKT-OP-4');
        $this->assertSame(FinanceInvoice::STATUS_ISSUED, $invoice->status);
        $this->assertSame(0, DatabaseNotification::query()->count());

        // Di luar transaksi, kegagalannya meninggalkan baris di register (§29).
        $this->assertSame(0, app(FinanceNotificationService::class)->invoiceIssued($invoice));

        $incident = FinanceOperation::query()
            ->where('name', FinanceOperation::EVENT_NOTIFICATION_SEND)
            ->first();
        $this->assertNotNull($incident, 'Kegagalan notifikasi harus tercatat (§29).');
        $this->assertSame(FinanceOperation::KIND_INCIDENT, $incident->kind);
        $this->assertStringContainsString('kanal notifikasi mati', (string) $incident->error);
        $this->assertSame(
            FinanceActivityNotification::TYPE_INVOICE_ISSUED,
            data_get($incident->context, 'type'),
        );
        // Tidak ada jalur ulang dari sini: yang diulang adalah aksi aslinya.
        $this->assertFalse((bool) $incident->retryable);
    }

    public function test_tugas_terjadwal_yang_gagal_masuk_register_keuangan_saja(): void
    {
        $schedule = app(Schedule::class);

        event(new ScheduledTaskFailed(
            $schedule->command('finance:daily-maintenance'),
            new RuntimeException('perintah harian mati'),
        ));

        $incident = FinanceOperation::query()
            ->where('name', FinanceOperation::EVENT_SCHEDULED_JOB)
            ->first();
        $this->assertNotNull($incident, 'Scheduler yang mati harus dilaporkan (§29).');
        $this->assertStringContainsString('finance:daily-maintenance', (string) data_get($incident->context, 'command'));
        $this->assertStringContainsString('perintah harian mati', (string) $incident->error);
        // Perintah Keuangan idempoten → menjalankannya ulang setelah penyebabnya
        // diperbaiki selalu aman.
        $this->assertTrue((bool) $incident->retryable);

        // Versi berantrian dijadwalkan sebagai closure bernama, bukan perintah:
        // penanda "finance:" hanya tinggal di namanya, jadi itu yang harus dibaca
        // listener — kalau tidak, jalur antrian justru lebih buram daripada jalur
        // perintah langsung yang ia gantikan.
        event(new ScheduledTaskFailed(
            $schedule->call(function (): void {})->name('finance:generate-spp (antrian)'),
            new RuntimeException('antrian SPP gagal'),
        ));

        $closure = FinanceOperation::query()
            ->where('name', FinanceOperation::EVENT_SCHEDULED_JOB)
            ->latest('id')
            ->firstOrFail();
        $this->assertStringContainsString('finance:generate-spp', (string) data_get($closure->context, 'command'));

        $before = FinanceOperation::query()->count();

        event(new ScheduledTaskFailed(
            $schedule->command('library:daily-maintenance'),
            new RuntimeException('tugas modul lain'),
        ));

        $this->assertSame($before, FinanceOperation::query()->count());
    }

    // =================================================================
    // §3, §66 — hak baca dan hak jalan adalah dua hal berbeda
    // =================================================================

    public function test_membaca_register_dan_menjalankan_pekerjaan_punya_hak_masing_masing(): void
    {
        $queue = app(FinanceOperationService::class)
            ->queue(FinanceOperation::JOB_REMINDERS_SEND, [], $this->userOfRole('finance-admin'));

        // Auditor/pengawas: baca-saja, tanpa hak menjalankan (§66).
        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/operations')->assertOk();
        $this->getJson("/api/v1/finance/operations/{$queue->id}")->assertOk();
        $this->postJson("/api/v1/finance/operations/{$queue->id}/retry")->assertForbidden();
        $this->postJson('/api/v1/finance/operations', [
            'job' => FinanceOperation::JOB_PAYMENTS_EXPIRE,
        ])->assertForbidden();

        // Kasir tidak punya salah satunya pun: register bukan bagian dari loket.
        $this->actingAsRole('kasir');
        $this->getJson('/api/v1/finance/operations')->assertForbidden();

        // Petugas boleh menjalankan pekerjaan pencatatannya.
        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/operations', [
            'job' => FinanceOperation::JOB_PAYMENTS_EXPIRE,
        ])->assertCreated()->assertJsonPath('data.status', FinanceOperation::STATUS_COMPLETED);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function actingAsRole(string $role): User
    {
        $user = $this->userOfRole($role);
        Sanctum::actingAs($user);

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

    private function package(string $code): FinanceFeePackage
    {
        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => $code],
            ['name' => 'Paket '.$code, 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $package->items()->create([
                'fee_type_id' => FinanceFeeType::query()->where('code', 'SPP')->value('id'),
                'amount' => 350000,
                'frequency' => 'monthly',
                'is_active' => true,
            ]);
        }

        return $package;
    }

    /** Tagihan terbit lewat jalur layar, bukan pintas test. */
    private function issuedInvoice(Student $student, string $pkgCode): FinanceInvoice
    {
        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $this->package($pkgCode)->id,
            'period_key' => 'OP-2026-09',
            'student_ids' => [$student->id],
            'due_date' => '2026-09-30',
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::find(
            $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    private function gatewayPayment(FinanceInvoice $invoice): int
    {
        return (int) $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => (float) $invoice->total,
            'method' => 'payment_gateway',
            'bank_account_id' => FinanceBankAccount::query()->where('code', 'BANK-OPS')->value('id'),
            'gateway' => 'fake',
        ])->assertCreated()->json('data.id');
    }
}

/**
 * Gateway yang selalu gagal saat membuka kanal pembayaran. Namanya unik karena
 * PHPUnit memuat seluruh berkas tes dalam satu proses.
 */
class BrokenOperationGateway implements PaymentGateway
{
    public function createPayment(array $intent): array
    {
        throw new RuntimeException('kanal pembayaran tidak tersedia');
    }

    public function checkStatus(string $reference): array
    {
        return ['reference' => $reference, 'status' => 'pending', 'amount' => 0.0, 'provider_reference' => null, 'raw' => []];
    }

    public function handleWebhook(Request $request): array
    {
        return ['reference' => '', 'status' => '', 'amount' => 0.0, 'provider_reference' => null, 'raw' => []];
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

/**
 * Dispatcher notifikasi yang selalu gagal — meniru kanal yang mati (queue rusak,
 * driver tidak terisi) untuk membuktikan angka tetap bergerak dan kegagalannya
 * tetap dilaporkan.
 */
class DeadChannelDispatcher implements Dispatcher
{
    public function send($notifiables, $notification)
    {
        throw new RuntimeException('kanal notifikasi mati');
    }

    public function sendNow($notifiables, $notification, ?array $channels = null)
    {
        throw new RuntimeException('kanal notifikasi mati');
    }
}
