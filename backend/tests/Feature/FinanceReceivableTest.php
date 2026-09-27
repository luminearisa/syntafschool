<?php

namespace Tests\Feature;

use App\Models\FinanceCashAccount;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Piutang / AR + aging (§26–§28) lewat HTTP.
 *
 * Yang diverifikasi: daftar hanya memuat invoice outstanding dengan sisa > 0;
 * aging mengelompokkan sisa per ember umur berdasarkan due_date terhadap tanggal
 * acuan; ringkasan memisahkan total piutang vs yang sudah jatuh tempo; invoice
 * lunas hilang dari AR; guard permission (butuh finance.report.view).
 */
class FinanceReceivableTest extends TestCase
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

    private function student(string $nis): Student
    {
        return Student::query()->firstOrCreate(
            ['nis' => $nis],
            ['full_name' => 'Santri '.$nis, 'is_active' => true],
        );
    }

    private function feeType(string $code): FinanceFeeType
    {
        return FinanceFeeType::query()->where('code', $code)->firstOrFail();
    }

    /** Paket 850.000 (SPP 350k + Asrama 500k). */
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

    /** Billing tergenerate + invoice terbit dengan due_date tertentu. Panggil sebagai finance-admin. */
    private function issue(Student $student, string $dueDate, string $pkg): FinanceInvoice
    {
        $package = $this->package($pkg);

        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => '2026-09',
            'student_ids' => [$student->id],
            'due_date' => $dueDate,
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::find(
            $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    // =================================================================

    public function test_daftar_piutang_memuat_invoice_outstanding(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issue($this->student('AR001'), Carbon::today()->addDays(10)->toDateString(), 'PKT-AR1');

        $this->actingAsRole('finance-officer'); // punya finance.report.view
        $resp = $this->getJson('/api/v1/finance/receivables?all=1')->assertOk();

        $ids = collect($resp->json('data'))->pluck('id')->all();
        $this->assertContains($invoice->id, $ids);

        $row = collect($resp->json('data'))->firstWhere('id', $invoice->id);
        $this->assertEquals(850000.00, (float) $row['remaining_amount']);
        $this->assertSame(FinanceInvoice::STATUS_ISSUED, $row['status']);
    }

    public function test_invoice_lunas_hilang_dari_piutang(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issue($this->student('AR002'), Carbon::today()->addDays(10)->toDateString(), 'PKT-AR2');

        // Lunasi lewat pembayaran tunai.
        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => FinanceCashAccount::query()->where('code', 'KAS-SEKOLAH')->value('id'),
        ])->assertCreated();

        $summary = $this->getJson('/api/v1/finance/receivables/summary')->assertOk()->json('data');
        $this->assertEquals(0.00, (float) $summary['total_receivable']);
        $this->assertSame(0, (int) $summary['invoice_count']);
    }

    public function test_aging_mengelompokkan_sisa_per_ember_umur(): void
    {
        $this->actingAsRole('finance-admin');
        $today = Carbon::today();

        $this->issue($this->student('AR010'), $today->copy()->addDays(10)->toDateString(), 'PKT-AR-CUR');   // current
        $this->issue($this->student('AR011'), $today->copy()->subDays(10)->toDateString(), 'PKT-AR-130');   // 1-30
        $this->issue($this->student('AR012'), $today->copy()->subDays(40)->toDateString(), 'PKT-AR-3160');  // 31-60

        $aging = $this->getJson('/api/v1/finance/receivables/aging')->assertOk()->json('data');

        $this->assertEquals(2550000.00, (float) $aging['grand_total']);

        $buckets = collect($aging['buckets'])->keyBy('bucket');
        $this->assertEquals(850000.00, (float) $buckets['current']['total']);
        $this->assertEquals(850000.00, (float) $buckets['1_30']['total']);
        $this->assertEquals(850000.00, (float) $buckets['31_60']['total']);
        $this->assertEquals(0.00, (float) $buckets['61_90']['total']);
        $this->assertEquals(0.00, (float) $buckets['90_plus']['total']);

        $this->assertCount(3, $aging['by_customer']);
    }

    public function test_ringkasan_memisahkan_piutang_dan_jatuh_tempo(): void
    {
        $this->actingAsRole('finance-admin');
        $today = Carbon::today();

        $this->issue($this->student('AR020'), $today->copy()->addDays(5)->toDateString(), 'PKT-AR-SUM1');   // belum jatuh tempo
        $this->issue($this->student('AR021'), $today->copy()->subDays(15)->toDateString(), 'PKT-AR-SUM2');  // jatuh tempo

        $summary = $this->getJson('/api/v1/finance/receivables/summary')->assertOk()->json('data');

        $this->assertEquals(1700000.00, (float) $summary['total_receivable']);
        $this->assertEquals(850000.00, (float) $summary['total_overdue']);
        $this->assertSame(2, (int) $summary['invoice_count']);
        $this->assertSame(1, (int) $summary['overdue_count']);
    }

    public function test_butuh_permission_untuk_melihat_piutang(): void
    {
        // kasir punya finance.report.view; siswa tidak punya apa pun di finance.
        $this->actingAsRole('siswa');
        $this->getJson('/api/v1/finance/receivables')->assertStatus(403);
        $this->getJson('/api/v1/finance/receivables/aging')->assertStatus(403);
    }
}
