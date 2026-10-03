<?php

declare(strict_types=1);

namespace Tests\Feature\Laundry;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRfidCard;
use App\Models\WalletTransaction;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryCatalogService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryOrderService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryPolicyService;
use App\Modules\SchoolOperations\Laundry\Database\Seeders\LaundryMasterSeeder;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPolicy;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPolicyVersion;
use App\Modules\SchoolOperations\Laundry\Models\LaundryService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Basis tes modul laundry: data fondasi (service, izin, peran) + helper untuk
 * membangun santri, wali, wallet ber-RFID, policy jatah, dan harga.
 */
abstract class LaundryTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $laundryAdmin;

    private int $nisSequence = 0;

    private int $emailSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LaundryMasterSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->laundryAdmin = $this->makeUser('Laundry Admin');
        $this->laundryAdmin->syncRoles([Role::findOrCreate('LAUNDRY_ADMIN', 'web')]);
    }

    // =====================================================================
    // IDENTITAS
    // =====================================================================

    protected function makeUser(string $name = 'Pengguna', ?string $email = null): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email ?? sprintf('user%d@example.test', ++$this->emailSequence),
            'is_active' => true,
        ]);
    }

    protected function makeStudent(string $name = 'Santri Uji', bool $active = true): Student
    {
        return Student::create([
            'nis' => sprintf('LDR%04d', ++$this->nisSequence),
            'full_name' => $name,
            'is_active' => $active,
        ]);
    }

    /** Wali sah (guardian + akun user terhubung lewat email) untuk satu santri. */
    protected function makeGuardianUserFor(Student $student, string $name = 'Wali Santri'): User
    {
        $email = sprintf('wali%d@example.test', ++$this->emailSequence);

        $guardian = Guardian::create([
            'full_name' => $name,
            'email' => $email,
            'phone' => '+628'.str_pad((string) random_int(100000, 999999), 9, '0'),
        ]);

        StudentGuardian::create([
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'is_primary' => true,
        ]);

        return $this->makeUser($name, $email);
    }

    /** Akun santri (userable) — dipakai ContextService utk /me/laundry. */
    protected function makeStudentUserFor(Student $student): User
    {
        $user = $this->makeUser($student->full_name);
        $user->forceFill([
            'userable_type' => $student->getMorphClass(),
            'userable_id' => $student->id,
        ])->save();
        $user->syncRoles([Role::findOrCreate('STUDENT', 'web')]);

        return $user;
    }

    protected function makeParentRoleUser(User $user): User
    {
        $user->syncRoles([Role::findOrCreate('PARENT', 'web')]);

        return $user;
    }

    // =====================================================================
    // WALLET (modul Wallet tetap sumber kebenaran saldo)
    // =====================================================================

    protected function makeWallet(Student $student, float $balance = 0, ?string $rfidUid = null): Wallet
    {
        $wallet = Wallet::create([
            'number' => 'WLT-LDRY-'.str_pad((string) $student->id, 6, '0', STR_PAD_LEFT),
            'student_id' => $student->id,
            'balance' => 0,
            'held_balance' => 0,
            'status' => Wallet::STATUS_ACTIVE,
            'opened_at' => now(),
        ]);

        if ($balance > 0) {
            app(WalletLedgerService::class)->post($wallet, WalletTransaction::TYPE_TOPUP, $balance, [
                'description' => 'Saldo awal tes laundry',
            ]);
        }

        if ($rfidUid !== null) {
            WalletRfidCard::create([
                'uid' => $rfidUid,
                'wallet_id' => $wallet->id,
                'status' => WalletRfidCard::STATUS_ACTIVE,
                'assigned_at' => now(),
            ]);
        }

        return $wallet->fresh();
    }

    // =====================================================================
    // POLICY & HARGA
    // =====================================================================

    /**
     * Policy jatah gratis terpublikasi (default: 10 kg/bulan, tanpa carry over).
     */
    protected function publishPolicy(array $overrides = []): LaundryPolicyVersion
    {
        $policies = app(LaundryPolicyService::class);

        $policy = $policies->createPolicy([
            'code' => $overrides['code'] ?? 'POL-BRD-'.(++$this->nisSequence),
            'name' => $overrides['name'] ?? 'Jatah Santri Boarding',
        ], $this->laundryAdmin);

        $version = $policies->createVersion($policy, array_merge([
            'quota_type' => 'WEIGHT',
            'quota_amount' => 10,
            'period_type' => 'MONTHLY',
            'carry_over_mode' => 'NO_CARRY_OVER',
            'effective_from' => now()->subYear()->toDateString(),
            'student_category' => null,
        ], $overrides), $this->laundryAdmin);

        return $policies->publishVersion($version, $this->laundryAdmin);
    }

    /** Harga per kg untuk satu layanan (default Rp10.000/kg). */
    protected function pricePerKg(?LaundryService $service = null, float $price = 10000, array $overrides = []): void
    {
        app(LaundryCatalogService::class)->createPricingRule(array_merge([
            'laundry_service_id' => ($service ?? $this->regularService())->id,
            'pricing_model' => 'WEIGHT',
            'unit_price' => $price,
            'effective_from' => now()->subYear()->toDateString(),
        ], $overrides), $this->laundryAdmin);
    }

    protected function regularService(): LaundryService
    {
        return LaundryService::query()->where('code', 'REGULAR')->firstOrFail();
    }

    // =====================================================================
    // ORDER
    // =====================================================================

    /** Order DRAFT + terima + timbang — siap dikalkulasi. */
    protected function weighedOrder(Student $student, float $netWeight, array $overrides = []): LaundryOrder
    {
        $orders = app(LaundryOrderService::class);

        $order = $orders->create(array_merge([
            'student_id' => $student->id,
            'laundry_service_id' => $this->regularService()->id,
        ], $overrides), $this->laundryAdmin);

        $gross = $netWeight + (float) ($overrides['bag_weight'] ?? 0);
        $orders->receive($order, [], $this->laundryAdmin);
        $orders->weigh($order->fresh(), [
            'gross_weight' => $gross,
            'bag_weight' => $overrides['bag_weight'] ?? 0,
        ], $this->laundryAdmin);

        return $order->fresh();
    }

    protected function actingAsLaundryAdmin(): User
    {
        Sanctum::actingAs($this->laundryAdmin, ['*']);

        return $this->laundryAdmin;
    }
}
