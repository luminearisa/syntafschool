<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBilling;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Tagihan lain" massal (§10, §12): pilih jenis tagihan → target santri →
 * preview (exclude existing) → generate idempoten → invoice snapshot akun
 * pendapatan per jenis. Memakai ulang mesin billing Phase 1, bukan engine baru.
 */
class FinanceOtherBillingTest extends TestCase
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
        return Student::query()->firstOrCreate(
            ['nis' => $nis],
            ['full_name' => 'Santri '.$nis, 'is_active' => true],
        );
    }

    private function feeType(string $code): FinanceFeeType
    {
        return FinanceFeeType::query()->where('code', $code)->firstOrFail();
    }

    private function accountCode(int $accountId): ?string
    {
        return FinanceAccount::query()->whereKey($accountId)->value('code');
    }

    // =================================================================
    // Preview (§10)
    // =================================================================

    public function test_preview_memisahkan_baru_dan_sudah_ditagih(): void
    {
        $this->actingAsRole('finance-admin');
        $seragam = $this->feeType('SERAGAM');
        $s1 = $this->student('OB001');
        $s2 = $this->student('OB002');

        // Belum ada: keduanya "new".
        $this->postJson('/api/v1/finance/billings/other/preview', [
            'fee_type_id' => $seragam->id,
            'period_key' => '2026-09',
            'amount' => 750000,
            'student_ids' => [$s1->id, $s2->id],
        ])->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.will_generate', 2)
            ->assertJsonPath('data.already_billed', 0);

        // Generate satu, lalu preview ulang: satu sudah ditagih (exclude existing).
        $this->postJson('/api/v1/finance/billings/other/generate', [
            'fee_type_id' => $seragam->id,
            'period_key' => '2026-09',
            'amount' => 750000,
            'student_ids' => [$s1->id],
        ])->assertOk();

        $this->postJson('/api/v1/finance/billings/other/preview', [
            'fee_type_id' => $seragam->id,
            'period_key' => '2026-09',
            'amount' => 750000,
            'student_ids' => [$s1->id, $s2->id],
        ])->assertOk()
            ->assertJsonPath('data.will_generate', 1)
            ->assertJsonPath('data.already_billed', 1);
    }

    // =================================================================
    // Generate idempoten (§10, §14)
    // =================================================================

    public function test_generate_membuat_billing_other_dan_idempoten(): void
    {
        $this->actingAsRole('finance-admin');
        $kegiatan = $this->feeType('KEGIATAN');
        $s1 = $this->student('OB010');
        $s2 = $this->student('OB011');

        $first = $this->postJson('/api/v1/finance/billings/other/generate', [
            'fee_type_id' => $kegiatan->id,
            'period_key' => '2026-09',
            'amount' => 200000,
            'student_ids' => [$s1->id, $s2->id],
            'due_date' => '2026-09-30',
        ])->assertOk()
            ->assertJsonPath('data.generated', 2)
            ->assertJsonPath('data.skipped', 0);

        $ids = $first->json('data.billings');
        $this->assertCount(2, $ids);

        $billing = FinanceBilling::find($ids[0]);
        $this->assertSame('other', $billing->source);
        $this->assertNotNull($billing->reference);
        $this->assertEquals(200000.00, (float) $billing->total);
        $this->assertSame($kegiatan->id, (int) $billing->items->first()->fee_type_id);

        // Ulangi: tidak dobel, semua dilewati.
        $this->postJson('/api/v1/finance/billings/other/generate', [
            'fee_type_id' => $kegiatan->id,
            'period_key' => '2026-09',
            'amount' => 200000,
            'student_ids' => [$s1->id, $s2->id],
        ])->assertOk()
            ->assertJsonPath('data.generated', 0)
            ->assertJsonPath('data.skipped', 2);

        $this->assertSame(2, FinanceBilling::where('source', 'other')->count());
    }

    public function test_periode_berbeda_membuat_billing_terpisah(): void
    {
        $this->actingAsRole('finance-admin');
        $ujian = $this->feeType('UJIAN');
        $s1 = $this->student('OB020');

        $this->postJson('/api/v1/finance/billings/other/generate', [
            'fee_type_id' => $ujian->id, 'period_key' => '2026-09', 'amount' => 150000,
            'student_ids' => [$s1->id],
        ])->assertOk()->assertJsonPath('data.generated', 1);

        $this->postJson('/api/v1/finance/billings/other/generate', [
            'fee_type_id' => $ujian->id, 'period_key' => '2026-10', 'amount' => 150000,
            'student_ids' => [$s1->id],
        ])->assertOk()->assertJsonPath('data.generated', 1);

        $this->assertSame(2, FinanceBilling::where('source', 'other')->where('student_id', $s1->id)->count());
    }

    public function test_nominal_default_memakai_default_amount_jenis_tagihan(): void
    {
        $this->actingAsRole('finance-admin');
        $perpustakaan = $this->feeType('PERPUSTAKAAN');
        $perpustakaan->update(['default_amount' => 125000]);
        $s1 = $this->student('OB030');

        $resp = $this->postJson('/api/v1/finance/billings/other/generate', [
            'fee_type_id' => $perpustakaan->id,
            'period_key' => '2026-09',
            'student_ids' => [$s1->id],
        ])->assertOk()->assertJsonPath('data.generated', 1);

        $billing = FinanceBilling::find($resp->json('data.billings.0'));
        $this->assertEquals(125000.00, (float) $billing->total);
    }

    public function test_quantity_mengalikan_total(): void
    {
        $this->actingAsRole('finance-admin');
        $perlengkapan = $this->feeType('PERLENGKAPAN');
        $s1 = $this->student('OB040');

        $resp = $this->postJson('/api/v1/finance/billings/other/generate', [
            'fee_type_id' => $perlengkapan->id,
            'period_key' => '2026-09',
            'amount' => 50000,
            'quantity' => 3,
            'student_ids' => [$s1->id],
        ])->assertOk();

        $billing = FinanceBilling::find($resp->json('data.billings.0'));
        $this->assertEquals(150000.00, (float) $billing->total);
        $this->assertSame(3, (int) $billing->items->first()->quantity);
    }

    // =================================================================
    // Invoice snapshot akun pendapatan per jenis (§12, §16)
    // =================================================================

    public function test_invoice_tagihan_lain_snapshot_akun_pendapatan_jenis(): void
    {
        $this->actingAsRole('finance-admin');
        $seragam = $this->feeType('SERAGAM'); // akun 4500
        $s1 = $this->student('OB050');

        $billingId = $this->postJson('/api/v1/finance/billings/other/generate', [
            'fee_type_id' => $seragam->id,
            'period_key' => '2026-09',
            'amount' => 750000,
            'student_ids' => [$s1->id],
        ])->assertOk()->json('data.billings.0');

        $invoice = FinanceInvoice::find(
            $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );

        $this->assertEquals(750000.00, (float) $invoice->total);
        $item = $invoice->items->first();
        $this->assertSame($seragam->id, (int) $item->fee_type_id);
        $this->assertSame('4500', $this->accountCode((int) $item->account_id));
    }

    // =================================================================
    // Guard permission (§44)
    // =================================================================

    public function test_siswa_tidak_boleh_generate_tagihan_lain(): void
    {
        $this->actingAsRole('siswa');
        $seragam = $this->feeType('SERAGAM');

        $this->postJson('/api/v1/finance/billings/other/generate', [
            'fee_type_id' => $seragam->id,
            'period_key' => '2026-09',
            'amount' => 750000,
            'student_ids' => [$this->student('OB060')->id],
        ])->assertStatus(403);
    }

    public function test_generate_tanpa_target_ditolak(): void
    {
        $this->actingAsRole('finance-admin');
        $seragam = $this->feeType('SERAGAM');

        $this->postJson('/api/v1/finance/billings/other/generate', [
            'fee_type_id' => $seragam->id,
            'period_key' => '2026-09',
            'amount' => 750000,
        ])->assertStatus(422);
    }
}
