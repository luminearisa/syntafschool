<?php

namespace Tests\Feature;

use App\Models\FinanceCashAccount;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinancePaymentAllocation;
use App\Models\FinancePaymentPlan;
use App\Models\FinancialTransaction;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\FinanceSettingsService;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Payment plan / cicilan (§7).
 *
 * Dua kalimat §7 yang menjadi sumbu test ini:
 *
 * 1. "Payment plan tidak membuat revenue baru." Sebuah plan dibuat HANYA
 *    menambah baris jadwal: jurnal, alokasi pembayaran, `paid_amount`,
 *    `remaining_amount`, dan status invoice tidak boleh bergerak sesen pun
 *    hanya karena seseorang menekan "cicil".
 * 2. "Payment tetap dialokasikan ke invoice asli." Progress angsuran karena itu
 *    dibaca dari pembayaran invoice itu sendiri — test membayar lewat endpoint
 *    payment biasa, lalu memeriksa cicilan yang menyesuaikan dirinya, berurutan
 *    dari angsuran 1.
 *
 * Daftar uji §25 (membuat plan, membayar angsuran, pembayaran sebagian, angsuran
 * lewat tempo, pembatalan) plus gerbang konfigurasi (§7 "jika diizinkan
 * konfigurasi") dan pemisahan hak (§3).
 */
class FinancePaymentPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function actingAsRole(string $role): User
    {
        $email = str_replace('-', '.', $role).'@schoolerp.test';

        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function settings(): FinanceSettingsService
    {
        return app(FinanceSettingsService::class);
    }

    /** §7 mengizinkan fitur ini ditutup; hampir semua test membutuhkannya aktif. */
    private function enablePlans(array $values = []): void
    {
        $this->settings()->update(array_merge(['payment_plan_enabled' => true], $values));
    }

    private function student(string $nis): Student
    {
        return Student::query()->firstOrCreate(
            ['nis' => $nis],
            ['full_name' => 'Santri '.$nis, 'is_active' => true],
        );
    }

    /** Paket dengan nominal yang bisa diatur per jenis tagihan. */
    private function package(string $code, float $amount): FinanceFeePackage
    {
        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => $code],
            ['name' => 'Paket '.$code, 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $package->items()->create([
                'fee_type_id' => FinanceFeeType::query()->where('code', 'SPP')->value('id'),
                'amount' => $amount,
                'frequency' => 'monthly',
                'is_active' => true,
            ]);
        }

        return $package;
    }

    /**
     * Terbitkan invoice bernominal $amount. Penerbitan selalu dilakukan sebagai
     * admin agar hak membuat plan tidak ikut teruji di helper ini.
     */
    private function issue(string $nis, float $amount, string $dueDate): FinanceInvoice
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student($nis);
        $package = $this->package('PKT-PLN-'.$nis, $amount);

        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => Carbon::today()->format('Y-m'),
            'student_ids' => [$student->id],
            'due_date' => $dueDate,
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::findOrFail(
            (int) $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    /** Bayar lewat endpoint payment biasa (§7: uang masuk lewat satu pintu). */
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

    /**
     * Buat plan lewat HTTP dan kembalikan respons + isi `data`-nya.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: TestResponse, 1: array<string, mixed>}
     */
    private function createPlan(array $payload, string $role = 'finance-officer'): array
    {
        $this->actingAsRole($role);

        $response = $this->postJson('/api/v1/finance/payment-plans', $payload)->assertCreated();

        return [$response, $response->json('data')];
    }

    /**
     * Baca plan lewat API — jalur inilah yang menyinkronkan proyeksi cicilan.
     *
     * @return array<string, mixed>
     */
    private function planJson(FinancePaymentPlan|int $plan): array
    {
        $id = $plan instanceof FinancePaymentPlan ? $plan->id : $plan;
        $this->actingAsRole('finance-admin');

        return $this->getJson("/api/v1/finance/payment-plans/{$id}")->assertOk()->json('data');
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceSnapshot(FinanceInvoice $invoice): array
    {
        $invoice->refresh();

        return [
            'total' => round((float) $invoice->total, 2),
            'paid' => round((float) $invoice->paid_amount, 2),
            'written_off' => round((float) $invoice->written_off_amount, 2),
            'remaining' => round((float) $invoice->remaining_amount, 2),
            'status' => $invoice->status,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $installments
     * @return list<string>
     */
    private function statuses(array $installments): array
    {
        return array_column($installments, 'status');
    }

    /**
     * @param  array<int, array<string, mixed>>  $installments
     * @return list<float>
     */
    private function amounts(array $installments, string $key = 'amount'): array
    {
        return array_map(fn (array $i): float => round((float) $i[$key], 2), $installments);
    }

    // =================================================================
    // Gerbang konfigurasi (§7: "jika diizinkan konfigurasi")
    // =================================================================

    public function test_fitur_cicilan_mati_sebelum_diaktifkan_lewat_settings(): void
    {
        $invoice = $this->issue('PLN-OFF', 6000000, Carbon::today()->addDays(14)->toDateString());

        // Default §7: mencicil belum boleh diajukan sebelum bendahara
        // mengaktifkannya.
        $this->assertFalse($this->settings()->get('payment_plan_enabled'));

        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/payment-plans', [
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addWeek()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('payment_plan_enabled');

        // Ditolak sebelum apa pun tertulis: tidak ada plan, invoice masih utuh.
        $this->assertSame(0, FinancePaymentPlan::query()->count());
        $this->assertSame(6000000.0, $this->invoiceSnapshot($invoice)['remaining']);

        // Setelah diizinkan, rencana yang sama bisa dibuat.
        $this->enablePlans();
        $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addWeek()->toDateString(),
        ]);

        $this->assertSame(1, FinancePaymentPlan::query()->count());
    }

    public function test_jumlah_angsuran_minimal_dan_maksimal_dibaca_dari_konfigurasi(): void
    {
        $this->enablePlans(['payment_plan_max_installments' => 4]);
        $invoice = $this->issue('PLN-CNT', 6000000, Carbon::today()->addDays(14)->toDateString());

        $this->actingAsRole('finance-officer');

        // Satu angsuran bukan rencana pembayaran: itu hanya jatuh tempo biasa.
        $this->postJson('/api/v1/finance/payment-plans', [
            'invoice_id' => $invoice->id,
            'installment_count' => 1,
            'start_date' => Carbon::today()->addWeek()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('installment_count');

        // Di atas ambang konfigurasi.
        $this->postJson('/api/v1/finance/payment-plans', [
            'invoice_id' => $invoice->id,
            'installment_count' => 6,
            'start_date' => Carbon::today()->addWeek()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('installment_count');

        $this->assertSame(0, FinancePaymentPlan::query()->count());

        // Batas yang sama ikut dikirim ke UI, supaya frontend tidak menebak.
        $this->getJson('/api/v1/finance/payment-plans')->assertOk()
            ->assertJsonPath('meta.config.max_installments', 4)
            ->assertJsonPath('meta.config.min_installments', 2)
            ->assertJsonPath('meta.config.enabled', true);
    }

    public function test_tagihan_di_bawah_ambang_minimum_ditolak_dan_ambang_berubah_lewat_settings(): void
    {
        $this->enablePlans(['payment_plan_min_amount' => 5000000]);
        $invoice = $this->issue('PLN-MIN', 850000, Carbon::today()->addDays(14)->toDateString());

        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/payment-plans', [
            'invoice_id' => $invoice->id,
            'installment_count' => 2,
            'start_date' => Carbon::today()->addWeek()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('total_amount');

        // Bendahara menurunkan ambangnya → tagihan yang sama boleh dicicil.
        $this->enablePlans(['payment_plan_min_amount' => 500000]);
        [, $data] = $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 2,
            'start_date' => Carbon::today()->addWeek()->toDateString(),
        ]);

        // Nominal plan mengikuti sisa tagihan, bukan angka bulat hasil tebakan UI.
        $this->assertSame(850000.0, round((float) $data['total_amount'], 2));
    }

    // =================================================================
    // §25 — membuat payment plan
    // =================================================================

    public function test_membuat_rencana_tiga_angsuran_tanpa_menciptakan_revenue_baru(): void
    {
        $this->enablePlans();
        $invoice = $this->issue('PLN-NEW', 6000000, Carbon::today()->addDays(20)->toDateString());

        $beforeInvoice = $this->invoiceSnapshot($invoice);
        $beforeTransactions = FinancialTransaction::query()->count();
        $beforeAllocations = FinancePaymentAllocation::query()->count();

        [, $data] = $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
            'interval' => 'month',
            'notes' => 'Wali meminta dicicil tiga kali',
        ]);

        // Contoh §7: Rp6.000.000 → 3 × Rp2.000.000.
        $this->assertSame(6000000.0, round((float) $data['total_amount'], 2));
        $this->assertSame(3, $data['installment_count']);
        $this->assertSame('planned', $data['status']);
        $this->assertSame('Terencana', $data['status_label']);
        $this->assertStringStartsWith('PLAN-', (string) $data['plan_number']);
        $this->assertSame('Wali meminta dicicil tiga kali', $data['notes']);
        $this->assertSame($invoice->invoice_number, $data['invoice_number']);
        $this->assertCount(3, $data['installments']);
        $this->assertSame([2000000.0, 2000000.0, 2000000.0], $this->amounts($data['installments']));
        $this->assertSame([1, 2, 3], array_column($data['installments'], 'sequence'));
        $this->assertSame([
            Carbon::today()->addDays(10)->toDateString(),
            Carbon::today()->addDays(10)->addMonthNoOverflow()->toDateString(),
            Carbon::today()->addDays(10)->addMonthsNoOverflow(2)->toDateString(),
        ], array_column($data['installments'], 'due_date'));
        $this->assertSame(0.0, round((float) $data['paid_amount'], 2));
        $this->assertSame(6000000.0, round((float) $data['outstanding_amount'], 2));
        $this->assertSame(1, $data['next_installment_sequence']);
        $this->assertSame(Carbon::today()->addDays(10)->toDateString(), $data['next_due_date']);

        // §7 inti: plan hanya membagi jadwal. Tidak ada revenue, tidak ada
        // pembayaran, tidak ada jurnal — invoice tidak bergerak sama sekali.
        $this->assertSame($beforeInvoice, $this->invoiceSnapshot($invoice));
        $this->assertSame($beforeTransactions, FinancialTransaction::query()->count());
        $this->assertSame($beforeAllocations, FinancePaymentAllocation::query()->count());

        // Nomor plan diterbitkan modul (§73), bukan dikirim klien.
        $this->assertSame(1, FinancePaymentPlan::query()->where('plan_number', $data['plan_number'])->count());
        $this->assertSame($invoice->student_id, $data['student_id']);
    }

    public function test_pembagian_angsuran_selalu_pas_dengan_total_terlebih_yang_tidak_habis(): void
    {
        $this->enablePlans();
        // 850.000 tidak habis dibagi 3 — total plan harus tetap 850.000.
        $invoice = $this->issue('PLN-RND', 850000, Carbon::today()->addDays(20)->toDateString());

        [, $data] = $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addWeek()->toDateString(),
        ]);

        $amounts = $this->amounts($data['installments']);

        $this->assertSame([283333.33, 283333.33, 283333.34], $amounts);
        $this->assertEquals(850000.0, round(array_sum($amounts), 2));
        $this->assertEquals(round((float) $data['total_amount'], 2), round(array_sum($amounts), 2));
        // Sisa pembulatan tidak menciptakan tagihan: tetap sebesar sisa invoice.
        $this->assertSame(850000.0, $this->invoiceSnapshot($invoice)['remaining']);
    }

    public function test_nilai_cicilan_hanya_boleh_sebesar_sisa_tagihan_invoice(): void
    {
        $this->enablePlans();
        $invoice = $this->issue('PLN-CAP', 6000000, Carbon::today()->addDays(20)->toDateString());

        $this->actingAsRole('finance-officer');

        // §7: plan membagi tagihan yang ada, bukan menerbitkan tagihan baru.
        $this->postJson('/api/v1/finance/payment-plans', [
            'invoice_id' => $invoice->id,
            'total_amount' => 7000000,
            'installment_count' => 4,
            'start_date' => Carbon::today()->addWeek()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('total_amount');

        $this->assertSame(0, FinancePaymentPlan::query()->count());
        $this->assertSame(6000000.0, $this->invoiceSnapshot($invoice)['remaining']);

        // Invoice lunas tidak punya sisa untuk dicicil.
        $this->pay($invoice, 6000000);
        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/payment-plans', [
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addWeek()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_id');
    }

    public function test_satu_invoice_hanya_punya_satu_rencana_aktif(): void
    {
        $this->enablePlans();
        $invoice = $this->issue('PLN-DUP', 6000000, Carbon::today()->addDays(20)->toDateString());

        [, $firstPlan] = $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addWeek()->toDateString(),
        ]);

        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/payment-plans', [
            'invoice_id' => $invoice->id,
            'installment_count' => 2,
            'start_date' => Carbon::today()->addWeek()->toDateString(),
        ])->assertStatus(422)
            ->assertJsonPath('errors.invoice_id.0', 'Invoice '.$invoice->invoice_number.' sudah punya rencana pembayaran aktif ('.$firstPlan['plan_number'].'). Batalkan dulu bila jadwalnya mau disusun ulang.');

        // Setelah yang lama batal, menyusun jadwal baru boleh — sejarah plan
        // sebelumnya tetap ada.
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/payment-plans/{$firstPlan['id']}/cancel", [
            'reason' => 'Wali meminta jadwal yang lebih pendek',
        ])->assertOk();

        $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 2,
            'start_date' => Carbon::today()->addWeek()->toDateString(),
        ]);

        $this->assertSame(2, FinancePaymentPlan::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, FinancePaymentPlan::query()->where('invoice_id', $invoice->id)->active()->count());
        $this->assertSame(6000000.0, $this->invoiceSnapshot($invoice)['remaining']);
    }

    // =================================================================
    // §25 — membayar angsuran & pembayaran sebagian
    // =================================================================

    public function test_pembayaran_invoice_mengisi_cicilan_berurutan_sampai_lunas(): void
    {
        $this->enablePlans();
        $invoice = $this->issue('PLN-PAY', 6000000, Carbon::today()->addDays(90)->toDateString());

        [, $data] = $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
            'interval' => 'month',
        ]);

        // Angsuran 1 dibayar lewat endpoint payment biasa — plan tidak punya
        // jalur pembayaran sendiri, dan §7 menuntut pembayaran ke invoice asli.
        $this->pay($invoice, 2000000);

        $afterFirst = $this->planJson((int) $data['id']);
        $this->assertSame([2000000.0, 0.0, 0.0], $this->amounts($afterFirst['installments'], 'paid_amount'));
        $this->assertSame([0.0, 2000000.0, 2000000.0], $this->amounts($afterFirst['installments'], 'outstanding'));
        $this->assertSame(['paid', 'planned', 'planned'], $this->statuses($afterFirst['installments']));
        $this->assertSame('partial', $afterFirst['status']);
        $this->assertSame(2, $afterFirst['next_installment_sequence']);
        $this->assertEquals(33.33, round((float) $afterFirst['progress'], 2));

        // Uang yang sama tidak tercatat dua kali: pelunasan tetap satu, yaitu
        // milik invoice; cicilan hanya membaginya.
        $this->assertSame(2000000.0, round((float) $afterFirst['paid_amount'], 2));
        $this->assertSame(2000000.0, $this->invoiceSnapshot($invoice)['paid']);
        $this->assertSame(1, FinancePaymentAllocation::query()->where('invoice_id', $invoice->id)->count());

        $this->pay($invoice, 4000000);

        $final = $this->planJson((int) $data['id']);
        $this->assertSame('paid', $final['status']);
        $this->assertSame('Lunas', $final['status_label']);
        $this->assertEquals(100.0, round((float) $final['progress'], 2));
        $this->assertNull($final['next_due_date']);
        $this->assertNull($final['next_installment_sequence']);
        $this->assertSame(0.0, round((float) $final['outstanding_amount'], 2));
        $this->assertSame(['paid', 'paid', 'paid'], $this->statuses($final['installments']));
        $this->assertSame(0.0, $this->invoiceSnapshot($invoice)['remaining']);
        $this->assertSame('paid', $this->invoiceSnapshot($invoice)['status']);
    }

    public function test_pembayaran_sebagian_angsuran_berstatus_partial(): void
    {
        $this->enablePlans();
        $invoice = $this->issue('PLN-PRC', 6000000, Carbon::today()->addDays(90)->toDateString());

        [, $data] = $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
        ]);

        $this->pay($invoice, 750000);

        $json = $this->planJson((int) $data['id']);
        $this->assertSame(750000.0, round((float) $json['installments'][0]['paid_amount'], 2));
        $this->assertSame(1250000.0, round((float) $json['installments'][0]['outstanding'], 2));
        $this->assertSame('partial', $json['installments'][0]['status']);
        $this->assertSame('Dibayar Sebagian', $json['installments'][0]['status_label']);
        // Angsuran berikutnya belum tersentuh sama sekali.
        $this->assertSame(0.0, round((float) $json['installments'][1]['paid_amount'], 2));
        $this->assertSame(2000000.0, round((float) $json['installments'][1]['outstanding'], 2));
        $this->assertSame('partial', $json['status']);
        $this->assertSame(1, $json['next_installment_sequence']);
        $this->assertEquals(12.5, round((float) $json['progress'], 2));
    }

    public function test_pembayaran_yang_lebih_dulu_dari_rencana_bukan_bagian_cicilan(): void
    {
        $this->enablePlans();
        $invoice = $this->issue('PLN-BSE', 6000000, Carbon::today()->addDays(90)->toDateString());

        // DP 1.500.000 masuk lebih dulu, baru sisanya dipecah tiga.
        $this->pay($invoice, 1500000);

        [, $data] = $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
        ]);

        // Tanpa titik awal ini, uang 1.500.000 yang sudah lama masuk akan terbaca
        // sebagai angsuran ke-1 yang terbayar.
        $json = $this->planJson((int) $data['id']);
        $this->assertSame(1500000.0, round((float) $json['baseline_paid_amount'], 2));
        $this->assertSame(4500000.0, round((float) $json['total_amount'], 2));
        $this->assertSame(0.0, round((float) $json['paid_amount'], 2));
        $this->assertSame('planned', $json['status']);
        $this->assertSame(['planned', 'planned', 'planned'], $this->statuses($json['installments']));
        $this->assertSame([1500000.0, 1500000.0, 1500000.0], $this->amounts($json['installments']));

        // Setelah ini yang dihitung hanya pembayaran sesudah plan dibuat.
        $this->pay($invoice, 1500000);

        $after = $this->planJson((int) $data['id']);
        $this->assertSame(1500000.0, round((float) $after['paid_amount'], 2));
        $this->assertSame('paid', $after['installments'][0]['status']);
        $this->assertSame('partial', $after['status']);
    }

    // =================================================================
    // §25 — angsuran lewat tempo
    // =================================================================

    public function test_angsuran_yang_lewat_tempo_berstatus_overdue_dan_plan_ikut_terlambat(): void
    {
        $this->enablePlans();
        $invoice = $this->issue('PLN-OVD', 6000000, Carbon::today()->addDays(90)->toDateString());

        // Jadwal yang sudah berjalan 40 hari: dua angsuran pertama lewat tempo.
        [, $data] = $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->subDays(40)->toDateString(),
            'interval' => 'month',
        ]);

        $json = $this->planJson((int) $data['id']);
        $this->assertSame(['overdue', 'overdue', 'planned'], $this->statuses($json['installments']));
        $this->assertSame('Terlambat', $json['installments'][0]['status_label']);
        $this->assertSame('overdue', $json['status']);
        $this->assertSame(Carbon::today()->subDays(40)->toDateString(), $json['next_due_date']);

        // Angsuran yang TERLAMBAT dan baru dibayar sebagian tetap overdue: yang
        // dibaca penagihan (§6) adalah keterlambatannya, bukan sebagian nominalnya.
        $this->pay($invoice, 500000);

        $partial = $this->planJson((int) $data['id']);
        $this->assertSame('overdue', $partial['installments'][0]['status']);
        $this->assertSame(500000.0, round((float) $partial['installments'][0]['paid_amount'], 2));
        $this->assertSame(1500000.0, round((float) $partial['installments'][0]['outstanding'], 2));
        $this->assertSame('overdue', $partial['status']);

        // Lunas mengalahkan terlambat: angsuran yang selesai tidak lagi terlambat.
        $this->pay($invoice, 1500000);

        $settled = $this->planJson((int) $data['id']);
        $this->assertSame('paid', $settled['installments'][0]['status']);
        $this->assertSame(['paid', 'overdue', 'planned'], $this->statuses($settled['installments']));
        $this->assertSame('overdue', $settled['status']);
        $this->assertSame(2, $settled['next_installment_sequence']);
    }

    public function test_angsuran_jatuh_tempo_hari_ini_langsung_berstatus_due(): void
    {
        $this->enablePlans();
        $invoice = $this->issue('PLN-DUE', 6000000, Carbon::today()->addDays(90)->toDateString());

        [, $data] = $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->toDateString(),
        ]);

        $json = $this->planJson((int) $data['id']);
        $this->assertSame('due', $json['installments'][0]['status']);
        $this->assertSame('planned', $json['installments'][1]['status']);
        $this->assertSame('due', $json['status']);
        $this->assertSame('Jatuh Tempo', $json['status_label']);
        $this->assertSame(Carbon::today()->toDateString(), $json['installments'][0]['due_date']);
    }

    public function test_interval_angsuran_bisa_harian_dan_mingguan(): void
    {
        $this->enablePlans();
        $invoice = $this->issue('PLN-ITV', 6000000, Carbon::today()->addDays(90)->toDateString());

        [, $data] = $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 4,
            'start_date' => Carbon::today()->addDays(7)->toDateString(),
            'interval' => 'week',
            'interval_every' => 2,
        ]);

        $dueDates = array_column($data['installments'], 'due_date');

        $this->assertSame([
            Carbon::today()->addDays(7)->toDateString(),
            Carbon::today()->addDays(21)->toDateString(),
            Carbon::today()->addDays(35)->toDateString(),
            Carbon::today()->addDays(49)->toDateString(),
        ], $dueDates);
        $this->assertSame([1500000.0, 1500000.0, 1500000.0, 1500000.0], $this->amounts($data['installments']));
    }

    // =================================================================
    // §25 — pembatalan
    // =================================================================

    public function test_membatalkan_rencana_membekukan_jadwal_tanpa_membalik_uang(): void
    {
        $this->enablePlans();
        $invoice = $this->issue('PLN-CNL', 6000000, Carbon::today()->addDays(90)->toDateString());

        [, $data] = $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
        ]);

        $this->pay($invoice, 2000000);
        $afterPayment = $this->invoiceSnapshot($invoice);
        $transactions = FinancialTransaction::query()->count();

        $this->actingAsRole('finance-manager');
        $cancelled = $this->postJson("/api/v1/finance/payment-plans/{$data['id']}/cancel", [
            'reason' => 'Wali menarik permohonan cicilan dan membayar tunai',
        ])->assertOk()->json('data');

        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame('Dibatalkan', $cancelled['status_label']);
        $this->assertSame('Wali menarik permohonan cicilan dan membayar tunai', $cancelled['cancellation_reason']);
        $this->assertNotEmpty($cancelled['cancelled_at']);
        // Siapa yang membatalkan tercatat, bukan anonim.
        $this->assertNotEmpty($cancelled['cancelled_by_name']);

        // Yang batal hanya jadwal sisanya: angsuran yang uangnya sudah masuk tetap
        // lunas, dan pembatalan tidak membuat jurnal maupun menarik uang.
        $this->assertSame(['paid', 'cancelled', 'cancelled'], $this->statuses($cancelled['installments']));
        $this->assertSame(2000000.0, round((float) $cancelled['paid_amount'], 2));
        $this->assertSame($afterPayment, $this->invoiceSnapshot($invoice));
        $this->assertSame($transactions, FinancialTransaction::query()->count(), 'Pembatalan plan tidak boleh membuat jurnal.');

        // Plan batal tidak dihitung ulang: pembayaran berikutnya tidak membangkitkan
        // jadwal yang sudah dibubarkan.
        $this->pay($invoice, 1000000);

        $after = $this->planJson((int) $data['id']);
        $this->assertSame('cancelled', $after['status']);
        $this->assertSame(['paid', 'cancelled', 'cancelled'], $this->statuses($after['installments']));
        $this->assertSame(2000000.0, round((float) $after['paid_amount'], 2));
        $this->assertSame(3000000.0, $this->invoiceSnapshot($invoice)['paid']);
        $this->assertSame(3000000.0, $this->invoiceSnapshot($invoice)['remaining']);
        $this->assertSame($transactions + 1, FinancialTransaction::query()->count());
    }

    public function test_pembatalan_hanya_untuk_rencana_yang_masih_berjalan(): void
    {
        $this->enablePlans();
        $invoice = $this->issue('PLN-CNC', 6000000, Carbon::today()->addDays(90)->toDateString());

        [, $data] = $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 2,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
        ]);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/payment-plans/{$data['id']}/cancel", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        // Lunas → tidak ada jadwal tersisa yang bisa dibatalkan.
        $this->pay($invoice, 6000000);
        $this->assertSame('paid', $this->planJson((int) $data['id'])['status']);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/payment-plans/{$data['id']}/cancel", [
            'reason' => 'Mencoba membatalkan cicilan yang sudah selesai',
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('paid', $this->planJson((int) $data['id'])['status']);
    }

    // =================================================================
    // Hak akses (§3) + daftar & filter
    // =================================================================

    public function test_pemisahan_hak_menyusun_membatalkan_dan_membaca_cicilan(): void
    {
        $this->enablePlans();
        $invoice = $this->issue('PLN-RET', 6000000, Carbon::today()->addDays(90)->toDateString());

        // Kasir: melihat jadwal untuk menagih di loket, tidak menyusunnya.
        $this->actingAsRole('kasir');
        $this->getJson('/api/v1/finance/payment-plans')->assertOk();
        $this->postJson('/api/v1/finance/payment-plans', [
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
        ])->assertStatus(403);

        // Petugas keuangan: boleh menyusun, tidak boleh membubarkan.
        $this->actingAsRole('finance-officer');
        $planId = (int) $this->postJson('/api/v1/finance/payment-plans', [
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/payment-plans/{$planId}/cancel", [
            'reason' => 'Petugas mencoba membatalkan jadwalnya sendiri',
        ])->assertStatus(403);

        // Manajer: hak penuh termasuk pembatalan.
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/payment-plans/{$planId}/cancel", [
            'reason' => 'Jadwal disusun ulang oleh manajer',
        ])->assertOk();

        // Auditor: baca saja, tanpa aksi (§56).
        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/payment-plans')->assertOk();
        $this->getJson("/api/v1/finance/payment-plans/{$planId}")->assertOk();
        $this->postJson('/api/v1/finance/payment-plans', [
            'invoice_id' => $invoice->id,
            'installment_count' => 2,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
        ])->assertStatus(403);
        $this->postJson("/api/v1/finance/payment-plans/{$planId}/cancel", [
            'reason' => 'Auditor tidak punya hak membatalkan',
        ])->assertStatus(403);

        $this->assertSame(1, FinancePaymentPlan::query()->count());
        $this->assertSame(0, FinancePaymentPlan::query()->active()->count());
    }

    public function test_daftar_cicilan_bisa_disaring_dan_proyeksinya_ikut_mutakhir(): void
    {
        $this->enablePlans();
        $first = $this->issue('PLN-LST', 6000000, Carbon::today()->addDays(90)->toDateString());
        $second = $this->issue('PLN-LTZ', 3000000, Carbon::today()->addDays(90)->toDateString());

        [, $firstPlan] = $this->createPlan([
            'invoice_id' => $first->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addDays(10)->toDateString(),
        ]);
        [, $secondPlan] = $this->createPlan([
            'invoice_id' => $second->id,
            'installment_count' => 2,
            'start_date' => Carbon::today()->addDays(15)->toDateString(),
        ]);

        $this->pay($first, 2000000);

        $this->actingAsRole('finance-admin');

        // Daftar tanpa filter menyinkronkan proyeksi baris yang tampil, jadi
        // saringan status membaca angka yang sama dengan yang di layar.
        $all = $this->getJson('/api/v1/finance/payment-plans')->assertOk();
        $this->assertCount(2, $all->json('data'));
        $this->assertTrue($all->json('meta.config.enabled'));
        $this->assertSame('partial', collect($all->json('data'))->firstWhere('id', $firstPlan['id'])['status']);
        $this->assertSame(2000000.0, round((float) collect($all->json('data'))->firstWhere('id', $firstPlan['id'])['paid_amount'], 2));

        // Filter invoice = daftar cicilan satu tagihan.
        $onlyFirst = $this->getJson('/api/v1/finance/payment-plans?invoice_id='.$first->id)->assertOk()->json('data');
        $this->assertCount(1, $onlyFirst);
        $this->assertSame($firstPlan['plan_number'], $onlyFirst[0]['plan_number']);
        $this->assertSame($first->invoice_number, $onlyFirst[0]['invoice_number']);
        $this->assertCount(3, $onlyFirst[0]['installments']);

        $byStudent = $this->getJson('/api/v1/finance/payment-plans?student_id='.$second->student_id)->assertOk()->json('data');
        $this->assertCount(1, $byStudent);
        $this->assertSame($secondPlan['plan_number'], $byStudent[0]['plan_number']);

        $this->assertCount(1, $this->getJson('/api/v1/finance/payment-plans?status=partial')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/finance/payment-plans?status=planned')->assertOk()->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/finance/payment-plans?active_only=1')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/finance/payment-plans?search='.$secondPlan['plan_number'])->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/finance/payment-plans?search='.urlencode('Santri PLN-LTZ'))->assertOk()->json('data'));
    }

    public function test_cicilan_terhubung_ke_sejarah_penagihan_invoice(): void
    {
        $this->enablePlans();
        $invoice = $this->issue('PLN-CLN', 6000000, Carbon::today()->subDays(10)->toDateString());

        [, $data] = $this->createPlan([
            'invoice_id' => $invoice->id,
            'installment_count' => 3,
            'start_date' => Carbon::today()->addDays(5)->toDateString(),
        ]);

        // Aksi penagihan "payment plan" (§6) wajib menautkan dokumen §7 yang
        // dilahirkannya, dan tautannya bisa dibaca kembali dari sejarah.
        $this->actingAsRole('finance-manager');

        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", [
            'action_type' => 'payment_plan',
            'notes' => 'Disepakati tiga angsuran mulai minggu depan',
        ])->assertStatus(422)->assertJsonValidationErrors('reference_id');

        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", [
            'action_type' => 'payment_plan',
            'reference_id' => $data['id'],
            'notes' => 'Disepakati tiga angsuran mulai minggu depan',
        ])->assertCreated()
            // Stage baris sejarah dibekukan dari posisi SEBELUM aksi (§6), jadi
            // tercatat "rencana disusun saat tagihan sudah ditunggak".
            ->assertJsonPath('data.stage', 'overdue')
            ->assertJsonPath('data.reference_type', FinancePaymentPlan::class)
            ->assertJsonPath('data.reference_id', $data['id'])
            ->assertJsonPath('data.reference.number', $data['plan_number'])
            ->assertJsonPath('data.action_label', 'Rencana Pembayaran');

        // Antreanlah yang bergerak: setelah aksi ini tagihan naik ke anak tangga
        // tindakan penagihan, karena itu sejarah tidak boleh melompat kembali.
        $queueRow = collect($this->getJson('/api/v1/finance/collections')->assertOk()->json('data.rows'))
            ->firstWhere('invoice_id', $invoice->id);
        $this->assertIsArray($queueRow);
        $this->assertSame('collection_action', $queueRow['stage']);

        $history = $this->getJson("/api/v1/finance/collections/{$invoice->id}/history")->assertOk()->json('data');
        $this->assertCount(1, $history);
        $this->assertSame($data['plan_number'], $history[0]['reference']['number']);

        // Plan milik invoice lain tetap ditolak: sejarah penagihan tidak boleh
        // menunjuk jadwal orang lain.
        $other = $this->issue('PLN-CLX', 3000000, Carbon::today()->addDays(20)->toDateString());
        [, $otherPlan] = $this->createPlan([
            'invoice_id' => $other->id,
            'installment_count' => 2,
            'start_date' => Carbon::today()->addDays(5)->toDateString(),
        ]);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", [
            'action_type' => 'payment_plan',
            'reference_id' => $otherPlan['id'],
        ])->assertStatus(422)->assertJsonValidationErrors('reference_id');

        // Menautkan dokumen penagihan tidak menggerakkan satu rupiah pun.
        $this->assertSame(6000000.0, $this->invoiceSnapshot($invoice)['remaining']);
    }
}
