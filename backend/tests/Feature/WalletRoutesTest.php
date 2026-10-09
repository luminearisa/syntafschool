<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_wallet_dan_buku_besar_dapat_diakses_petugas_yang_memiliki_permission(): void
    {
        $this->actingAsRole('bendahara');
        $student = Student::create(['nis' => 'W001', 'full_name' => 'Siswa Wallet', 'is_active' => true]);
        $wallet = Wallet::create([
            'number' => 'WLT-000001', 'student_id' => $student->id,
            'balance' => 25000, 'held_balance' => 5000, 'status' => 'active',
        ]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'transaction_number' => 'WTX-000001',
            'type' => 'topup',
            'direction' => 'credit',
            'amount' => 25000,
            'balance_before' => 0,
            'balance_after' => 25000,
            'status' => 'posted',
            'description' => 'Setoran awal',
            'posted_at' => now(),
        ]);

        $this->getJson('/api/v1/wallet/wallets')
            ->assertOk()
            ->assertJsonPath('data.0.owner_name', 'Siswa Wallet')
            ->assertJsonPath('data.0.available_balance', 20000);

        $this->getJson("/api/v1/wallet/wallets/{$wallet->id}/ledger")
            ->assertOk()
            ->assertJsonPath('data.0.transaction_number', 'WTX-000001');

        // The verification queue must resolve the existing `wallet_topups` table.
        $this->getJson('/api/v1/wallet/topups?pending_verification=1')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_petugas_dapat_membuat_wallet_siswa_dari_endpoint_management(): void
    {
        $this->actingAsRole('bendahara');
        $student = Student::create(['nis' => 'W002', 'full_name' => 'Calon Pemilik', 'is_active' => true]);

        $this->getJson('/api/v1/wallet/students?search=Calon')
            ->assertOk()
            ->assertJsonPath('data.0.id', $student->id)
            ->assertJsonPath('data.0.full_name', 'Calon Pemilik');

        $this->postJson('/api/v1/wallet/wallets', ['student_id' => $student->id])
            ->assertCreated()
            ->assertJsonPath('data.owner_name', 'Calon Pemilik')
            ->assertJsonPath('data.balance', 0);
    }

    public function test_wallet_tidak_terekspos_ke_pengguna_tanpa_permission_wallet(): void
    {
        $this->actingAsRole('siswa');
        $this->getJson('/api/v1/wallet/wallets')->assertForbidden();
    }
}
