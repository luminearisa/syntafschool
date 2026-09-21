<?php

namespace Tests\Feature\Ppdb;

use App\Models\Gender;
use App\Models\PaymentMethod;
use App\Models\PpdbInvoice;
use App\Models\PpdbPeriod;
use App\Models\PpdbProgram;
use App\Models\PpdbRegistration;
use App\Models\PpdbRegistrationFee;
use App\Models\PpdbWave;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Siklus hidup tagihan PPDB: kedaluwarsa, dibatalkan, di-refund.
 *
 * Ketiganya dulu state mati — `PpdbInvoiceService::expire()/cancel()/refund()`
 * tidak punya satu pun pemanggil, sehingga tagihan lewat tenggat terbaca
 * `unpaid` selamanya. Aturan main test ini (audit §41): status hanya berpindah
 * lewat endpoint atau perintah artisan, tidak ada baris yang ditulis manual.
 * Yang dibuktikan:
 * - `ppdb:expire-invoices` menendang tagihan lewat tenggat, statusnya terbaca
 *   pada endpoint baca admin & portal, dan dijalankan ulang tidak membuat apa
 *   pun menjadi dua;
 * - uang yang sudah diterima tidak boleh "hilang" oleh kedaluwarsa/pembatalan —
 *   satu-satunya jalur keluar adalah refund;
 * - pembatalan & refund wajib alasan, butuh hak `ppdb.payment.verify`, dan
 *   membalas transisi ilegal dengan 422 (bukan 500);
 * - tiap perubahan menulis riwayat pendaftaran dan kabar orang tua lewat
 *   layanan notifikasi yang sudah ada.
 */
class PpdbInvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const FEE_AMOUNT = 150000;

    private PpdbPeriod $period;

    private PpdbWave $wave;

    /** Program dengan biaya bertenggat kemarin → tagihannya bisa kedaluwarsa. */
    private PpdbProgram $late;

    /** Program tanpa tenggat sendiri → jatuh temponya ikut periode (masih depan). */
    private PpdbProgram $upToDate;

    private PaymentMethod $transfer;

    private User $panitia;

    private User $bendahara;

    private User $kepala;

    /** Tanggal kemarin, dipakai sebagai jatuh tempo tagihan yang tertunda. */
    private string $yesterday;

    /** Pengisi NIK/NISN supaya tiap calon tetap unik. */
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RolePermissionSeeder::class);

        $this->yesterday = now()->subDay()->toDateString();

        Gender::query()->create(['code' => 'M', 'name' => 'Laki-laki']);
        Gender::query()->create(['code' => 'F', 'name' => 'Perempuan']);
        $this->transfer = PaymentMethod::query()->create(['code' => 'TRF', 'name' => 'Transfer Bank']);

        $this->period = $this->openPeriod('PPDB-LC', 'PPDB Siklus Tagihan', 'ppdb-siklus-tagihan');
        $this->wave = $this->openWave($this->period, 'GW-LC', 'Gelombang Siklus');

        $this->late = PpdbProgram::query()->create([
            'period_id' => $this->period->id, 'code' => 'TELAT', 'name' => 'Program Telat',
            'quota' => 20, 'sort_order' => 1,
        ]);

        $this->upToDate = PpdbProgram::query()->create([
            'period_id' => $this->period->id, 'code' => 'BARU', 'name' => 'Program Baru',
            'quota' => 20, 'sort_order' => 2,
        ]);

        // Biaya program `TELAT` jatuh temponya kemarin, program `BARU` tidak punya
        // tenggat sendiri sehingga ikut akhir pendaftaran (bulan depan). Dengan
        // begitu satu perintah hanya boleh menendang tagihan yang memang lewat
        // tenggat — tanpa ada angka, nama sekolah, atau periode yang ditebak test.
        $this->fee($this->period, $this->late, 'Biaya Pendaftaran Telat', $this->yesterday);
        $this->fee($this->period, $this->upToDate, 'Biaya Pendaftaran Baru', null);

        $this->panitia = $this->staff('panitia-ppdb');
        $this->bendahara = $this->staff('bendahara');
        $this->kepala = $this->staff('kepala-sekolah');
    }

    // =================================================================
    // KEDALUWARSA (jalur terjadwal)
    // =================================================================

    public function test_perintah_kedaluwarsakan_tagihan_lewat_tenggat_dan_statusnya_terbaca(): void
    {
        $overdue = $this->invoiceOf($this->registration('hidu@ppdb-siklus.test', 'Calan Hidu', $this->late));
        $current = $this->invoiceOf($this->registration('baru@ppdb-siklus.test', 'Calan Baru', $this->upToDate));

        $this->assertSame(PpdbInvoice::STATUS_UNPAID, $overdue['status']);
        $this->assertSame($this->yesterday, $overdue['due_date']);
        $this->assertSame(PpdbInvoice::STATUS_UNPAID, $current['status']);
        $this->assertNotSame($this->yesterday, $current['due_date']);

        Artisan::call('ppdb:expire-invoices');

        $this->assertStringContainsString('Tagihan PPDB dikedaluwarsakan: 1', Artisan::output());

        // Endpoint baca admin mencerminkan status barunya ...
        $this->as($this->panitia)
            ->getJson('/api/v1/ppdb/invoices/'.$overdue['id'])
            ->assertOk()
            ->assertJsonPath('data.status', PpdbInvoice::STATUS_EXPIRED)
            ->assertJsonPath('data.status_label', 'Kedaluwarsa');

        // ... bisa disaring ...
        $this->getJson('/api/v1/ppdb/invoices?status=expired&period_id='.$this->period->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $overdue['id']);

        // ... dan tagihan yang belum jatuh tempo tidak ikut tersentuh.
        $this->getJson('/api/v1/ppdb/invoices/'.$current['id'])
            ->assertOk()
            ->assertJsonPath('data.status', PpdbInvoice::STATUS_UNPAID);

        $registration = (int) $overdue['registration_id'];

        // Portal orang tua juga membacanya kedaluwarsa (bukan lunas, bukan hilang).
        $this->as($this->parentOf($registration))
            ->getJson('/api/v1/ppdb/portal/registrations/'.$registration.'/payment')
            ->assertOk()
            ->assertJsonPath('data.invoices.0.status', PpdbInvoice::STATUS_EXPIRED)
            ->assertJsonPath('data.invoices.0.status_label', 'Kedaluwarsa');

        // Status pembayaran pendaftaran ikut tersinkron ke `expired`.
        $this->as($this->panitia)
            ->getJson('/api/v1/ppdb/registrations/'.$registration)
            ->assertOk()
            ->assertJsonPath('data.payment_status', PpdbRegistration::PAYMENT_EXPIRED);

        // Jejak audit: riwayat pendaftaran mencatat peristiwa + alasannya.
        $entry = $this->historyEntry($registration, 'payment.expired');

        $this->assertIsArray($entry);
        $this->assertStringContainsString('Jatuh tempo '.$this->yesterday, (string) $entry['note']);
        $this->assertNull($entry['changed_by'], 'Aksi terjadwal tidak mengklaim ada petugas yang menekannya.');

        // Idempoten: dijalankan kedua kali tidak menambah riwayat kedua.
        Artisan::call('ppdb:expire-invoices');

        $this->assertStringContainsString('Tagihan PPDB dikedaluwarsakan: 0', Artisan::output());
        $this->assertSame(1, $this->historyCount($registration, 'payment.expired'));

        $this->getJson('/api/v1/ppdb/invoices/'.$overdue['id'])
            ->assertOk()
            ->assertJsonPath('data.status', PpdbInvoice::STATUS_EXPIRED);
    }

    public function test_perintah_bisa_dibatasi_persatu_periode_tanpa_menendang_periode_lain(): void
    {
        $other = $this->openPeriod('PPDB-LEN', 'PPDB Periode Lain', 'ppdb-periode-lain');
        $otherWave = $this->openWave($other, 'GW-LEN', 'Gelombang Lain');

        $otherProgram = PpdbProgram::query()->create([
            'period_id' => $other->id, 'code' => 'LAIN', 'name' => 'Program Lain',
            'quota' => 10, 'sort_order' => 1,
        ]);

        // Periode lain masih membuka pendaftaran, tetapi tenggat biayanya kemarin:
        // tagihannya juga lewat tenggat dan tidak boleh tersentuh lewat --period.
        $this->fee($other, null, 'Biaya Periode Lain', $this->yesterday);

        $here = $this->invoiceOf($this->registration('sini@ppdb-siklus.test', 'Calan Sini', $this->late));
        $there = $this->invoiceOf($this->registration(
            'sana@ppdb-siklus.test',
            'Calan Sana',
            $otherProgram,
            $other,
            $otherWave,
        ));

        Artisan::call('ppdb:expire-invoices', ['--period' => $this->period->id]);

        $this->as($this->panitia)->getJson('/api/v1/ppdb/invoices/'.$here['id'])
            ->assertOk()->assertJsonPath('data.status', PpdbInvoice::STATUS_EXPIRED);

        $this->getJson('/api/v1/ppdb/invoices/'.$there['id'])
            ->assertOk()->assertJsonPath('data.status', PpdbInvoice::STATUS_UNPAID);

        // Tanpa pembatasan, periode lain ikut diproses.
        Artisan::call('ppdb:expire-invoices');

        $this->getJson('/api/v1/ppdb/invoices/'.$there['id'])
            ->assertOk()->assertJsonPath('data.status', PpdbInvoice::STATUS_EXPIRED);
    }

    public function test_tagihan_yang_sudah_ada_pembayaran_sah_tidak_dikedaluwarsakan(): void
    {
        $registration = $this->registration('sebagian@ppdb-siklus.test', 'Calan Sebagian', $this->late);
        $invoice = $this->invoiceOf($registration);

        // Setengah dibayar dan sudah disahkan bendahara: uangnya nyata ada.
        $this->pay($registration, (int) $invoice['id'], self::FEE_AMOUNT / 2);

        $pending = $this->as($this->panitia)
            ->getJson('/api/v1/ppdb/invoices/'.$invoice['id'])->assertOk()->json('data');

        $this->assertSame(PpdbInvoice::STATUS_PENDING, $pending['status']);
        $this->assertSame((float) self::FEE_AMOUNT / 2, $this->num($pending['paid_amount']));

        Artisan::call('ppdb:expire-invoices');
        $output = Artisan::output();

        $this->assertStringContainsString('Tagihan PPDB dikedaluwarsakan: 0', $output);
        $this->assertStringContainsString('Dilewati', $output);

        $this->as($this->panitia)->getJson('/api/v1/ppdb/invoices/'.$invoice['id'])
            ->assertOk()
            ->assertJsonPath('data.status', PpdbInvoice::STATUS_PENDING);

        // Pembatalan juga ditolak pada keadaan ini — dan diarahkan ke refund.
        $refused = $this->as($this->bendahara)
            ->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/cancel', [
                'reason' => 'Orang tua meminta tagihan dibatalkan.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertStringContainsString('refund', (string) $refused->json('errors.status.0'));

        // Jalur keluar yang benar: dana dikembalikan, piutang tidak dihapus diam-diam.
        $refunded = $this->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/refund', [
            'reason' => 'Calon mengundurkan diri, pembayaran sebagian dikembalikan.',
        ])->assertOk();

        $refunded->assertJsonPath('data.status', PpdbInvoice::STATUS_REFUNDED);

        // Refund mengembalikan uang yang benar-benar masuk: separuh tagihan yang
        // tidak pernah dibayar tetap terbaca sebagai kekurangan, bukan dilenyapkan
        // bersama statusnya.
        $this->assertSame((float) self::FEE_AMOUNT / 2, $this->num($refunded->json('data.outstanding_amount')));

        // Uang yang pernah masuk tetap terbaca sebagai riwayat, tidak dihapus.
        $this->assertSame((float) self::FEE_AMOUNT / 2, $this->num($refunded->json('data.paid_amount')));
        $this->assertIsArray($this->historyEntry($registration, 'payment.refunded'));
    }

    // =================================================================
    // PEMBATALAN
    // =================================================================

    public function test_pembatalan_tagihan_butuh_alasan_dan_hak_verifikasi_pembayaran(): void
    {
        $registration = $this->registration('batal@ppdb-siklus.test', 'Calan Batal', $this->upToDate);
        $invoice = $this->invoiceOf($registration);

        // Kepala sekolah hanya berhak melihat pembayaran — tidak boleh membatalkan.
        $this->as($this->kepala)
            ->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/cancel', [
                'reason' => 'Dibatalkan karena calon tidak jadi masuk.',
            ])
            ->assertForbidden();

        // Tanpa alasan → 422 bervalidasi, bukan 500.
        $this->as($this->panitia)
            ->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/cancel')
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/cancel', ['reason' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(PpdbInvoice::STATUS_UNPAID, $this->invoiceRow((int) $invoice['id'])['status']);

        $reason = 'Calon siswa mengundurkan diri sebelum membayar.';

        $cancelled = $this->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/cancel', ['reason' => $reason])
            ->assertOk()
            ->assertJsonPath('data.status', PpdbInvoice::STATUS_CANCELLED)
            ->assertJsonPath('data.status_label', 'Dibatalkan');

        // Alasan tersimpan pada tagihan (khusus petugas) dan pada riwayat pendaftaran.
        $this->assertSame($reason, $cancelled->json('data.notes'));

        $entry = $this->historyEntry($registration, 'payment.cancelled');

        $this->assertSame($reason, (string) $entry['note']);
        $this->assertSame((int) $this->panitia->id, (int) $entry['changed_by']);

        // Status pembayaran pendaftaran ikut tersinkron ke `cancelled`.
        $this->as($this->panitia)
            ->getJson('/api/v1/ppdb/registrations/'.$registration)
            ->assertOk()
            ->assertJsonPath('data.payment_status', PpdbRegistration::PAYMENT_CANCELLED);

        // Tagihan yang ditutup tidak bisa ditutup dua kali.
        $this->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/cancel', ['reason' => 'Membatalkan tagihan untuk kedua kalinya.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // ... tetapi piutangnya boleh ditagih ulang sebagai tagihan baru —
        // aturan `liveInvoiceFor()`/`billedFeeIds()` yang lama, kini terpakai.
        $replacement = $this->postJson('/api/v1/ppdb/invoices', ['registration_id' => $registration])
            ->assertCreated()
            ->json('data');

        $this->assertNotSame($invoice['number'], $replacement['number']);
        $this->assertSame(PpdbInvoice::STATUS_UNPAID, $replacement['status']);
        $this->assertSame((float) self::FEE_AMOUNT, $this->num($replacement['total_amount']));

        // Portal: tagihan lama tetap terbaca dibatalkan (indeks 0 = tertua) dan
        // catatan petugas tidak ikut keluar; yang ditagih hanya tagihan baru.
        $portal = $this->as($this->parentOf($registration))
            ->getJson('/api/v1/ppdb/portal/registrations/'.$registration.'/payment')
            ->assertOk()
            ->assertJsonPath('data.invoices.0.status', PpdbInvoice::STATUS_CANCELLED)
            ->assertJsonMissingPath('data.invoices.0.notes')
            ->assertJsonPath('data.invoices.1.status', PpdbInvoice::STATUS_UNPAID)
            ->json();

        $this->assertSame((float) self::FEE_AMOUNT, $this->num($portal['data']['outstanding_amount']));
    }

    public function test_tagihan_yang_sudah_lunas_tidak_boleh_dibatalkan(): void
    {
        $registration = $this->registration('lunas@ppdb-siklus.test', 'Calan Lunas', $this->upToDate);
        $invoice = $this->invoiceOf($registration);

        $this->pay($registration, (int) $invoice['id'], (float) self::FEE_AMOUNT);

        $this->assertSame(PpdbInvoice::STATUS_PAID, $this->invoiceRow((int) $invoice['id'])['status']);

        $refused = $this->as($this->bendahara)
            ->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/cancel', [
                'reason' => 'Mencoba membatalkan tagihan yang sudah dibayar penuh.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertStringContainsString('sudah lunas', (string) $refused->json('errors.status.0'));

        $after = $this->invoiceRow((int) $invoice['id']);

        $this->assertSame(PpdbInvoice::STATUS_PAID, $after['status']);
        $this->assertSame((float) self::FEE_AMOUNT, $this->num($after['paid_amount']));
        $this->assertNull($this->historyEntry($registration, 'payment.cancelled'));
    }

    public function test_tagihan_kedaluwarsa_masih_boleh_dibatalkan_panitia(): void
    {
        $registration = $this->registration('kedlu@ppdb-siklus.test', 'Calan Kedaluwarsa', $this->late);
        $invoice = $this->invoiceOf($registration);

        Artisan::call('ppdb:expire-invoices');

        $this->assertSame(PpdbInvoice::STATUS_EXPIRED, $this->invoiceRow((int) $invoice['id'])['status']);

        // `expired` masih piutang hidup; panitia yang melepasnya harus punya jalan.
        $this->as($this->panitia)
            ->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/cancel', [
                'reason' => 'Tenggat lewat dan calon tidak membayar, tagihan dilepas.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PpdbInvoice::STATUS_CANCELLED);
    }

    // =================================================================
    // REFUND
    // =================================================================

    public function test_refund_hanya_untuk_tagihan_terbayar_dan_mencatat_riwayat(): void
    {
        $registration = $this->registration('refund@ppdb-siklus.test', 'Calan Refund', $this->upToDate);
        $invoice = $this->invoiceOf($registration);

        // Belum ada uang masuk → refund tidak masuk akal.
        $this->as($this->bendahara)
            ->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/refund', [
                'reason' => 'Mengembalikan dana yang belum pernah diterima sekolah.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->pay($registration, (int) $invoice['id'], (float) self::FEE_AMOUNT);

        // Hak: verifikasi pembayaran adalah gerbang yang sama dengan refund.
        $this->as($this->kepala)
            ->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/refund', [
                'reason' => 'Kepala sekolah mencatat pengembalian dana.',
            ])
            ->assertForbidden();

        $this->as($this->bendahara)
            ->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/refund')
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $reason = 'Calon mengundurkan diri setelah lunas, dana dikembalikan lewat transfer.';

        $refunded = $this->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/refund', ['reason' => $reason])
            ->assertOk()
            ->assertJsonPath('data.status', PpdbInvoice::STATUS_REFUNDED)
            ->assertJsonPath('data.status_label', 'Dikembalikan')
            ->assertJsonPath('data.notes', $reason)
            ->assertJsonPath('data.registration.payment_status', PpdbRegistration::PAYMENT_REFUNDED);

        $this->assertSame((float) self::FEE_AMOUNT, $this->num($refunded->json('data.paid_amount')));

        $entry = $this->historyEntry($registration, 'payment.refunded');

        $this->assertSame($reason, (string) $entry['note']);
        $this->assertSame((int) $this->bendahara->id, (int) $entry['changed_by']);

        // Kabar orang tua memakai layanan notifikasi PPDB yang sudah ada.
        $number = (string) $invoice['number'];

        $this->assertNotEmpty(array_filter(
            (array) $this->as($this->parentOf($registration))
                ->getJson('/api/v1/ppdb/portal/notifications?per_page=100')
                ->assertOk()
                ->json('data'),
            static fn (array $row): bool => str_contains((string) $row['body'], $number),
        ), 'Orang tua dikabari lewat kanal notifikasi PPDB yang sudah ada.');

        // Refund kedua ditolak, dan tagihan refunded tidak bisa dibatalkan lagi.
        $this->as($this->bendahara)
            ->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/refund', ['reason' => 'Mengembalikan dana untuk kedua kalinya.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->postJson('/api/v1/ppdb/invoices/'.$invoice['id'].'/cancel', ['reason' => 'Membatalkan tagihan yang sudah di-refund.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // =================================================================
    // FIXTURE
    // =================================================================

    private function openPeriod(string $code, string $name, string $slug): PpdbPeriod
    {
        return PpdbPeriod::query()->create([
            'code' => $code, 'name' => $name, 'slug' => $slug,
            'status' => PpdbPeriod::STATUS_OPEN,
            'registration_start' => now()->subMonth()->toDateString(),
            'registration_end' => now()->addMonth()->toDateString(),
            'selection_announcement_date' => now()->addDays(10)->toDateString(),
            're_registration_start' => now()->addDays(11)->toDateString(),
            're_registration_end' => now()->addDays(20)->toDateString(),
        ]);
    }

    private function openWave(PpdbPeriod $period, string $code, string $name): PpdbWave
    {
        return PpdbWave::query()->create([
            'period_id' => $period->id, 'code' => $code, 'name' => $name,
            'quota' => 30, 'priority' => 1,
            'registration_start' => now()->subMonth()->toDateString(),
            'registration_end' => now()->addMonth()->toDateString(),
        ]);
    }

    private function fee(PpdbPeriod $period, ?PpdbProgram $program, string $name, ?string $deadline): PpdbRegistrationFee
    {
        return PpdbRegistrationFee::query()->create([
            'period_id' => $period->id,
            'program_id' => $program?->id,
            'type' => PpdbRegistrationFee::TYPE_REGISTRATION,
            'name' => $name,
            'amount' => self::FEE_AMOUNT,
            'required' => true,
            'active' => true,
            'payment_deadline' => $deadline,
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$role]);

        return $user;
    }

    private function as(User $actor): static
    {
        Sanctum::actingAs($actor);

        return $this;
    }

    /**
     * Akun orang tua + calon + pendaftaran, semuanya lewat HTTP.
     *
     * @return int id pendaftaran
     */
    private function registration(
        string $email,
        string $name,
        PpdbProgram $program,
        ?PpdbPeriod $period = null,
        ?PpdbWave $wave = null,
    ): int {
        $this->postJson('/api/v1/ppdb/auth/register', [
            'name' => 'Wali '.substr(md5($email), 0, 6),
            'email' => $email,
            'phone' => '0811'.str_pad((string) (++$this->sequence), 8, '0', STR_PAD_LEFT),
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'address' => 'Jl. Siklus Tagihan No. 1, Bandung',
        ])->assertCreated();

        $parent = User::query()->firstWhere('email', $email);
        $period ??= $this->period;
        $wave ??= $this->wave;

        $this->as($parent);

        $applicant = (int) $this->postJson('/api/v1/ppdb/portal/applicants', [
            'full_name' => $name,
            'nickname' => substr($name, 0, 6),
            'gender_id' => Gender::query()->firstWhere('code', 'M')->id,
            'birth_place' => 'Bandung',
            'birth_date' => '2010-04-01',
            'nik' => sprintf('330101%010d', $this->sequence),
            'nisn' => sprintf('0123%08d', $this->sequence),
            'previous_school' => 'SMP Siklus',
            'address' => 'Jl. Siklus Tagihan No. 1',
            'target_program_id' => $program->id,
        ])->assertCreated()->json('data.id');

        return (int) $this->postJson('/api/v1/ppdb/portal/registrations', [
            'applicant_id' => $applicant,
            'period_id' => $period->id,
            'program_id' => $program->id,
            'wave_id' => $wave->id,
        ])->assertCreated()->json('data.id');
    }

    /**
     * Tagihan sebuah pendaftaran, dibuat panitia lewat endpoint admin.
     *
     * @return array<string, mixed>
     */
    private function invoiceOf(int $registrationId): array
    {
        $this->as($this->panitia);

        return (array) $this->postJson('/api/v1/ppdb/invoices', ['registration_id' => $registrationId])
            ->assertCreated()
            ->json('data');
    }

    /** Bayar dari portal lalu disahkan bendahara — jalur pembayaran yang sah. */
    private function pay(int $registrationId, int $invoiceId, float $amount): void
    {
        $payment = (int) $this->as($this->parentOf($registrationId))
            ->postJson('/api/v1/ppdb/portal/invoices/'.$invoiceId.'/pay', [
                'amount' => $amount,
                'method' => 'manual',
                'payment_method_id' => $this->transfer->id,
                'reference' => 'LC-'.uniqid(),
                'proof' => UploadedFile::fake()->create('bukti.jpg', 90, 'image/jpeg'),
            ])
            ->assertSuccessful()
            ->json('data.payment.id');

        $this->as($this->bendahara)
            ->postJson('/api/v1/ppdb/payments/'.$payment.'/verify', ['note' => 'Uang sudah masuk rekening.'])
            ->assertOk();
    }

    private function parentOf(int $registrationId): User
    {
        $parent = PpdbRegistration::query()->findOrFail($registrationId)->parentAccount?->user;

        $this->assertInstanceOf(User::class, $parent, 'Pendaftaran dibuat dari akun orang tua yang punya pemilik.');

        return $parent;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRow(int $invoiceId): array
    {
        $this->as($this->panitia);

        return (array) $this->getJson('/api/v1/ppdb/invoices/'.$invoiceId)->assertOk()->json('data');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function historyEntry(int $registrationId, string $action): ?array
    {
        return collect($this->historyRows($registrationId))
            ->first(fn (array $row): bool => $row['action'] === $action);
    }

    private function historyCount(int $registrationId, string $action): int
    {
        return count(array_filter(
            $this->historyRows($registrationId),
            static fn (array $row): bool => $row['action'] === $action,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyRows(int $registrationId): array
    {
        return (array) $this->as($this->panitia)
            ->getJson('/api/v1/ppdb/registrations/'.$registrationId.'/history')
            ->assertOk()
            ->json('data');
    }

    private function num(mixed $value): float
    {
        return (float) $value;
    }
}
