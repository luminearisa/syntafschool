<?php

namespace Tests\Feature;

use App\Models\FinanceInvoice;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 2 mobile (wali murid): pagar kepemilikan pada aksi tulis baru —
 * bayar tagihan, ajukan izin/kepulangan santri, dompet.
 */
class MobilePhase2Test extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /** @return array{0: string, 1: Student, 2: Student} token wali, anak sendiri, anak orang lain */
    private function guardianToken(): array
    {
        $guardian = Guardian::create(['full_name' => 'Wali Uji']);
        $mine = Student::create(['nis' => 'W1', 'full_name' => 'Anak Saya']);
        $other = Student::create(['nis' => 'W2', 'full_name' => 'Anak Orang']);
        DB::table('student_guardians')->insert(['student_id' => $mine->id, 'guardian_id' => $guardian->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        User::factory()->create([
            'email' => 'wali2@syntaf.test', 'password' => self::PASSWORD, 'is_active' => true,
            'userable_type' => $guardian->getMorphClass(), 'userable_id' => $guardian->id,
        ])->syncRoles(['wali-murid']);

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'wali2@syntaf.test', 'password' => self::PASSWORD, 'client' => 'mobile'])
            ->assertOk()->json('data.token');

        return [$token, $mine, $other];
    }

    private function h(string $token): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function invoice(Student $student): FinanceInvoice
    {
        return FinanceInvoice::create([
            'invoice_number' => 'INV-T-'.$student->id, 'customer_type' => 'student', 'customer_id' => $student->id, 'student_id' => $student->id,
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'subtotal' => 100000, 'total' => 100000, 'paid_amount' => 0, 'remaining_amount' => 100000, 'status' => 'issued',
        ]);
    }

    public function test_online_payment_is_off_by_default_and_never_touches_other_childrens_invoices(): void
    {
        [$token, $mine, $other] = $this->guardianToken();
        $mineInv = $this->invoice($mine);
        $otherInv = $this->invoice($other);

        // Bawaan: dimatikan sekolah → 422 dengan pesan yang bisa dibaca orang tua.
        $this->postJson("/api/v1/finance/portal/children/{$mine->id}/invoices/{$mineInv->id}/pay", ['amount' => 50000], $this->h($token))
            ->assertStatus(422)->assertJsonValidationErrors('payment');

        // Anak orang lain → 404 (bukan 403/422), apa pun status fiturnya.
        $this->postJson("/api/v1/finance/portal/children/{$other->id}/invoices/{$otherInv->id}/pay", [], $this->h($token))->assertStatus(404);

        // Tagihan anak orang lain lewat id anak sendiri → 404.
        $this->postJson("/api/v1/finance/portal/children/{$mine->id}/invoices/{$otherInv->id}/pay", [], $this->h($token))->assertStatus(404);

        $this->assertSame(0, DB::table('finance_payments')->count());
    }

    public function test_boarding_requests_are_limited_to_own_children_and_require_an_active_placement(): void
    {
        [$token, $mine, $other] = $this->guardianToken();
        $body = [
            'permission_type' => 'out', 'reason' => 'Kontrol dokter',
            'start_at' => now()->addDay()->format('Y-m-d 08:00'), 'expected_return_at' => now()->addDay()->format('Y-m-d 15:00'),
        ];

        $this->postJson("/api/v1/boarding/portal/children/{$other->id}/permissions", $body, $this->h($token))->assertStatus(404);
        $this->postJson("/api/v1/boarding/portal/children/{$other->id}/homecomings", [
            'type' => 'weekend', 'home_date' => now()->addDay()->toDateString(), 'return_date' => now()->addDays(3)->toDateString(),
        ], $this->h($token))->assertStatus(404);

        // Anak sendiri tapi belum ditempatkan di asrama → ditolak aturan layanan, bukan lolos diam-diam.
        $this->postJson("/api/v1/boarding/portal/children/{$mine->id}/permissions", $body, $this->h($token))
            ->assertStatus(422)->assertJsonValidationErrors('student_id');

        // Tanggal lampau ditolak di pintu portal.
        $this->postJson("/api/v1/boarding/portal/children/{$mine->id}/permissions", array_merge($body, ['start_at' => now()->subDay()->format('Y-m-d 08:00')]), $this->h($token))
            ->assertStatus(422)->assertJsonValidationErrors('start_at');
    }

    public function test_wallet_endpoints_are_ownership_gated_and_topup_is_guardian_only(): void
    {
        [$token, $mine, $other] = $this->guardianToken();

        $this->getJson("/api/v1/mobile/children/{$mine->id}/wallet", $this->h($token))
            ->assertOk()->assertJsonPath('data.has_wallet', false);
        $this->getJson("/api/v1/mobile/children/{$other->id}/wallet", $this->h($token))->assertStatus(404);
        $this->postJson("/api/v1/mobile/children/{$other->id}/wallet/topups", ['amount' => 50000], $this->h($token))->assertStatus(404);
        $this->postJson("/api/v1/mobile/children/{$mine->id}/wallet/topups", ['amount' => 50000], $this->h($token))->assertStatus(404); // belum punya dompet
    }

    public function test_guardian_token_cannot_reach_staff_boarding_or_finance_endpoints(): void
    {
        [$token, $mine] = $this->guardianToken();

        $this->getJson('/api/v1/finance/invoices', $this->h($token))->assertStatus(403);
        $this->getJson('/api/v1/boarding/permissions', $this->h($token))->assertStatus(403);
        $this->postJson("/api/v1/boarding/permissions/1/approve", [], $this->h($token))->assertStatus(403);
        $this->getJson('/api/v1/wallet/wallets', $this->h($token))->assertStatus(403);
    }
}
