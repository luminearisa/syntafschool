<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceBankTransaction;
use App\Models\FinanceCashAccount;
use App\Models\FinanceTransfer;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Finance\FinanceSettingsService;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Transfer internal antar kas/bank (§42, §43) lewat HTTP.
 *
 * Yang diverifikasi: saldo baru bergerak saat complete (jurnal Dr tujuan / Cr
 * sumber, total aset tetap); kaki bank dicatat untuk rekonsiliasi; pemisahan
 * pembuat (transfer.create) vs penyetuju (transfer.approve §3) — pembuat tidak
 * boleh menyetujui sendiri; sumber=tujuan ditolak; complete hanya untuk yang
 * sudah disetujui; saldo sumber kurang → gagal atomik (status tetap approved).
 *
 * Phase 4 §3 dan §16 menambah dua kontrol: `idempotency_key` membuat permintaan
 * ulang mengembalikan transfer yang sama (uang tidak pernah berpindah dua kali
 * untuk satu slip), dan nominal di atas ambang konfigurasi menuntut penyetuju
 * kedua yang bukan pengaju maupun penyetuju pertama sebelum jurnal diposting.
 * Transfer yang belum dieksekusi bisa dibatalkan (§3 CANCELLED); yang sudah
 * bergerak hanya bisa dibalik lewat jurnal (§51).
 */
class FinanceTransferTest extends TestCase
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

    private function cash(string $code = 'KAS-SEKOLAH'): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', $code)->firstOrFail();
    }

    private function bank(string $code = 'BANK-OPS'): FinanceBankAccount
    {
        return FinanceBankAccount::query()->where('code', $code)->firstOrFail();
    }

    private function account(string $code): int
    {
        return (int) FinanceAccount::query()->where('code', $code)->value('id');
    }

    private function fundCash(float $amount, string $code = 'KAS-SEKOLAH'): void
    {
        $this->cash($code)->update(['current_balance' => $amount]);
    }

    private function fundBank(float $amount, string $code = 'BANK-OPS'): void
    {
        $this->bank($code)->update(['current_balance' => $amount]);
    }

    /** @return array<string, array{debit: float, credit: float}> */
    private function sides(FinancialTransaction $transaction): array
    {
        return $transaction->lines->mapWithKeys(fn ($line): array => [
            $line->account->code => ['debit' => (float) $line->debit, 'credit' => (float) $line->credit],
        ])->all();
    }

    /** Buat + ajukan transfer sebagai finance-officer, kembalikan id. */
    private function submitTransfer(float $amount, array $overrides = []): int
    {
        $this->actingAsRole('finance-officer');

        $payload = array_merge([
            'source_type' => FinanceTransfer::SIDE_CASH,
            'source_id' => $this->cash()->id,
            'destination_type' => FinanceTransfer::SIDE_BANK,
            'destination_id' => $this->bank()->id,
            'amount' => $amount,
            'transfer_date' => '2026-09-15',
            'reason' => 'Setor kas ke bank',
            'submit' => true,
        ], $overrides);

        return (int) $this->postJson('/api/v1/finance/transfers', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', FinanceTransfer::STATUS_SUBMITTED)
            ->json('data.id');
    }

    // =================================================================
    // Eksekusi transfer (§43)
    // =================================================================

    public function test_transfer_kas_ke_bank_memindahkan_saldo_dan_memposting_jurnal(): void
    {
        $this->fundCash(5000000);
        $this->fundBank(0);

        $id = $this->submitTransfer(2000000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/transfers/{$id}/approve")->assertOk()
            ->assertJsonPath('data.status', FinanceTransfer::STATUS_APPROVED);

        $this->postJson("/api/v1/finance/transfers/{$id}/complete")->assertOk()
            ->assertJsonPath('data.status', FinanceTransfer::STATUS_COMPLETED);

        // Saldo bergerak: kas -2jt, bank +2jt (total aset tetap).
        $this->assertEquals(3000000.0, (float) $this->cash()->fresh()->current_balance);
        $this->assertEquals(2000000.0, (float) $this->bank()->fresh()->current_balance);

        $transfer = FinanceTransfer::find($id);
        $this->assertNotNull($transfer->finance_transaction_id);
        $this->assertNotNull($transfer->completed_at);

        // Jurnal: Dr Bank (1200), Cr Kas (1100).
        $journal = FinancialTransaction::query()->with('lines.account')->find($transfer->finance_transaction_id);
        $this->assertSame(FinancialTransaction::TYPE_TRANSFER, $journal->type);
        $sides = $this->sides($journal);
        $this->assertEquals(2000000.0, $sides['1200']['debit']);
        $this->assertEquals(2000000.0, $sides['1100']['credit']);

        // Kaki bank dicatat untuk rekonsiliasi (§44).
        $leg = FinanceBankTransaction::query()->where('transfer_id', $id)->first();
        $this->assertNotNull($leg);
        $this->assertSame(FinanceBankTransaction::TYPE_TRANSFER, $leg->type);
        $this->assertEquals(2000000.0, (float) $leg->amount);
    }

    public function test_pembuat_transfer_tidak_boleh_menyetujui_sendiri(): void
    {
        $this->fundCash(5000000);
        $id = $this->submitTransfer(1000000);

        // officer = pembuat, tidak punya transfer.approve → 403 dulu.
        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/transfers/{$id}/approve")->assertStatus(403);
    }

    public function test_officer_tidak_bisa_menyelesaikan_transfer(): void
    {
        $this->fundCash(5000000);
        $id = $this->submitTransfer(1000000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/transfers/{$id}/approve")->assertOk();

        // officer tidak punya transfer.approve (gate complete) → 403.
        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/transfers/{$id}/complete")->assertStatus(403);
    }

    public function test_complete_hanya_untuk_transfer_yang_sudah_disetujui(): void
    {
        $this->fundCash(5000000);
        $id = $this->submitTransfer(1000000);

        // Langsung complete tanpa approve → 422 status.
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/transfers/{$id}/complete")
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame(FinanceTransfer::STATUS_SUBMITTED, FinanceTransfer::find($id)->status);
    }

    public function test_transfer_sumber_dan_tujuan_sama_ditolak(): void
    {
        $this->actingAsRole('finance-officer');

        $this->postJson('/api/v1/finance/transfers', [
            'source_type' => FinanceTransfer::SIDE_CASH,
            'source_id' => $this->cash()->id,
            'destination_type' => FinanceTransfer::SIDE_CASH,
            'destination_id' => $this->cash()->id,
            'amount' => 100000,
            'submit' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('destination_id');
    }

    public function test_complete_gagal_atomik_bila_saldo_sumber_kurang(): void
    {
        $this->fundCash(0);
        $id = $this->submitTransfer(1000000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/transfers/{$id}/approve")->assertOk();

        // Saldo kas 0 → engine menolak saldo negatif; transaksi rollback.
        $this->postJson("/api/v1/finance/transfers/{$id}/complete")
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        // Status tetap approved (tidak setengah jadi), saldo tidak berubah.
        $this->assertSame(FinanceTransfer::STATUS_APPROVED, FinanceTransfer::find($id)->status);
        $this->assertEquals(0.0, (float) $this->cash()->fresh()->current_balance);
        $this->assertEquals(0.0, (float) $this->bank()->fresh()->current_balance);
    }

    public function test_reject_membutuhkan_alasan(): void
    {
        $this->fundCash(5000000);
        $id = $this->submitTransfer(1000000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/transfers/{$id}/reject", [])->assertStatus(422)
            ->assertJsonValidationErrors('reject_reason');

        $this->postJson("/api/v1/finance/transfers/{$id}/reject", ['reject_reason' => 'Dokumen belum lengkap'])
            ->assertOk()->assertJsonPath('data.status', FinanceTransfer::STATUS_REJECTED);
    }

    // =================================================================
    // Guard permission (§3)
    // =================================================================

    public function test_auditor_baca_saja(): void
    {
        $this->fundCash(5000000);
        $id = $this->submitTransfer(1000000);

        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/transfers')->assertOk();
        $this->getJson("/api/v1/finance/transfers/{$id}")->assertOk();
        $this->postJson("/api/v1/finance/transfers/{$id}/approve")->assertStatus(403);
    }

    // =================================================================
    // Idempotensi transfer (§3): satu permintaan, satu transfer
    // =================================================================

    public function test_permintaan_ulang_dengan_kunci_idempotensi_tidak_menambah_transfer(): void
    {
        $this->fundCash(5000000);
        $this->actingAsRole('finance-officer');

        $payload = [
            'source_type' => FinanceTransfer::SIDE_CASH,
            'source_id' => $this->cash()->id,
            'destination_type' => FinanceTransfer::SIDE_BANK,
            'destination_id' => $this->bank()->id,
            'amount' => 1000000,
            'transfer_date' => '2026-09-15',
            'reason' => 'Setor kas ke bank',
            'submit' => true,
            'idempotency_key' => 'SLIP-BND-0091',
        ];

        $first = $this->postJson('/api/v1/finance/transfers', $payload)->assertCreated()->json('data.id');
        // Retry jaringan / klik ganda: kunci yang sama mengembalikan transfer yang sama.
        $second = $this->postJson('/api/v1/finance/transfers', $payload)->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, FinanceTransfer::query()->count());
    }

    public function test_kunci_idempotensi_yang_sama_tidak_digunakan_ulang_setelah_berjalan(): void
    {
        // Setelah complete, permintaan ulang tidak boleh menggerakkan uang sekali lagi.
        $this->fundCash(5000000);
        $this->fundBank(0);
        $this->actingAsRole('finance-officer');

        $payload = [
            'source_type' => FinanceTransfer::SIDE_CASH,
            'source_id' => $this->cash()->id,
            'destination_type' => FinanceTransfer::SIDE_BANK,
            'destination_id' => $this->bank()->id,
            'amount' => 2000000,
            'idempotency_key' => 'SLIP-BND-0092',
            'submit' => true,
        ];

        $id = $this->postJson('/api/v1/finance/transfers', $payload)->assertCreated()->json('data.id');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/transfers/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/finance/transfers/{$id}/complete")->assertOk();

        // Dicatat sebagai replay oleh pembuat aslinya: kunci idempotensi unik per
        // pembuat, jadi permintaan yang sama mengembalikan transfer yang sama.
        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/transfers', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', FinanceTransfer::STATUS_COMPLETED);

        $this->assertSame(1, FinanceTransfer::query()->count());
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertEquals(3000000.0, (float) $this->cash()->fresh()->current_balance);
        $this->assertEquals(2000000.0, (float) $this->bank()->fresh()->current_balance);
    }

    public function test_transfer_yang_sama_tidak_dapat_diselesaikan_dua_kali(): void
    {
        $this->fundCash(5000000);
        $id = $this->submitTransfer(2000000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/transfers/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/finance/transfers/{$id}/complete")->assertOk();

        // Permintaan complete kedua (mis. tombol diklik dua kali) ditolak: status
        // sudah completed, dan jurnal hanya ada satu.
        $this->postJson("/api/v1/finance/transfers/{$id}/complete")
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertEquals(3000000.0, (float) $this->cash()->fresh()->current_balance);
    }

    // =================================================================
    // Matriks approval menurut nominal (§16)
    // =================================================================

    public function test_transfer_di_atas_ambang_butuh_persetujuan_kedua_sebelum_berjalan(): void
    {
        // Ambang default konfigurasi: Rp 10.000.000.
        $this->fundCash(20000000);
        $this->fundBank(0);

        $id = $this->submitTransfer(15000000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/transfers/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.requires_secondary_approval', true)
            ->assertJsonPath('data.awaits_secondary_approval', true);

        // Uang belum boleh berpindah sebelum penyetuju kedua mencatatkan diri.
        $this->postJson("/api/v1/finance/transfers/{$id}/complete")
            ->assertStatus(422)->assertJsonValidationErrors('secondary_approved_by');
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertEquals(20000000.0, (float) $this->cash()->fresh()->current_balance);

        // Manajer tidak memegang hak persetujuan kedua: orang ketiga sungguhan.
        $this->postJson("/api/v1/finance/transfers/{$id}/secondary-approve")->assertStatus(403);

        $this->actingAsRole('bendahara');
        $this->postJson("/api/v1/finance/transfers/{$id}/secondary-approve")
            ->assertOk()
            ->assertJsonPath('data.awaits_secondary_approval', false);

        $this->postJson("/api/v1/finance/transfers/{$id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', FinanceTransfer::STATUS_COMPLETED);

        $this->assertEquals(5000000.0, (float) $this->cash()->fresh()->current_balance);
        $this->assertEquals(15000000.0, (float) $this->bank()->fresh()->current_balance);

        $transfer = FinanceTransfer::find($id);
        $this->assertNotNull($transfer->secondary_approved_by);
        $this->assertNotSame((int) $transfer->approved_by, (int) $transfer->secondary_approved_by);
    }

    public function test_penyetuju_kedua_bukan_pengaju_atau_penyetuju_pertama(): void
    {
        $this->fundCash(20000000);
        $id = $this->submitTransfer(12000000);

        // finance-admin punya kedua hak, tetapi satu orang tidak boleh mengisi
        // kedua kursi persetujuan (§15).
        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/transfers/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/finance/transfers/{$id}/secondary-approve")
            ->assertStatus(422)->assertJsonValidationErrors('secondary_approved_by');

        $this->postJson("/api/v1/finance/transfers/{$id}/complete")
            ->assertStatus(422)->assertJsonValidationErrors('secondary_approved_by');

        // Kursi kedua diisi orang lain: transfer di bawah ambang tidak menerima
        // persetujuan kedua sama sekali, jadi gunakan nominal besar.
        $this->actingAsRole('bendahara');
        $this->postJson("/api/v1/finance/transfers/{$id}/secondary-approve")->assertOk();
        $this->postJson("/api/v1/finance/transfers/{$id}/complete")->assertOk();
    }

    public function test_transfer_di_bawah_ambang_tidak_meminta_persetujuan_kedua(): void
    {
        $this->fundCash(5000000);
        $id = $this->submitTransfer(1000000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/transfers/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.requires_secondary_approval', false)
            ->assertJsonPath('data.awaits_secondary_approval', false);

        $this->postJson("/api/v1/finance/transfers/{$id}/secondary-approve")
            ->assertStatus(403);

        // Pemegang hak kedua pun menolaknya: satu penyetuju sudah cukup di sini.
        $this->actingAsRole('bendahara');
        $this->postJson("/api/v1/finance/transfers/{$id}/secondary-approve")
            ->assertStatus(422)->assertJsonValidationErrors('requires_secondary_approval');

        $this->postJson("/api/v1/finance/transfers/{$id}/complete")
            ->assertOk()->assertJsonPath('data.status', FinanceTransfer::STATUS_COMPLETED);
    }

    public function test_ambang_persetujuan_dibaca_dari_konfigurasi(): void
    {
        $this->fundCash(20000000);

        // Turunkan ambang → transfer yang sama menjadi dua tingkat.
        app(FinanceSettingsService::class)->update(['transfer_approval_threshold' => 500000]);
        $besar = $this->submitTransfer(1000000, ['idempotency_key' => 'SLIP-AMB-BESAR']);

        // Naikkan ambang → aturan baru hanya berlaku untuk transfer sesudahnya.
        app(FinanceSettingsService::class)->update(['transfer_approval_threshold' => 15000000]);
        $kecil = $this->submitTransfer(1000000, ['idempotency_key' => 'SLIP-AMB-KECIL']);

        $this->assertTrue((bool) FinanceTransfer::find($besar)->requires_secondary_approval);
        $this->assertFalse((bool) FinanceTransfer::find($kecil)->requires_secondary_approval);

        // Yang terlanjur diajukan di bawah ambang lama tetap membawa jejak aturannya.
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/transfers/{$besar}/approve")->assertOk();
        $this->postJson("/api/v1/finance/transfers/{$besar}/complete")
            ->assertStatus(422)->assertJsonValidationErrors('secondary_approved_by');
    }

    // =================================================================
    // Pembatalan (§3 CANCELLED)
    // =================================================================

    public function test_transfer_yang_belum_dieksekusi_bisa_dibatalkan(): void
    {
        $this->fundCash(5000000);
        $id = $this->submitTransfer(1000000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/transfers/{$id}/approve")->assertOk();

        $this->postJson("/api/v1/finance/transfers/{$id}/cancel", [])
            ->assertStatus(422)->assertJsonValidationErrors('cancel_reason');

        $this->postJson("/api/v1/finance/transfers/{$id}/cancel", ['cancel_reason' => 'Setoran batal oleh bank'])
            ->assertOk()
            ->assertJsonPath('data.status', FinanceTransfer::STATUS_CANCELLED);

        // Transfer batal tidak punya jurnal dan tidak menggerakkan saldo.
        $this->assertNull(FinanceTransfer::find($id)->finance_transaction_id);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertEquals(5000000.0, (float) $this->cash()->fresh()->current_balance);

        // dan tidak bisa dieksekusi setelahnya.
        $this->postJson("/api/v1/finance/transfers/{$id}/complete")
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_transfer_yang_sudah_berjalan_tidak_bisa_dibatalkan(): void
    {
        $this->fundCash(5000000);
        $id = $this->submitTransfer(1000000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/transfers/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/finance/transfers/{$id}/complete")->assertOk();

        // Yang sudah bergerak dibalik lewat jurnal, bukan dengan status (§51).
        $this->postJson("/api/v1/finance/transfers/{$id}/cancel", ['cancel_reason' => 'Salah_input'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }
}
