<?php

namespace Tests\Feature;

use App\Models\BoardingItemDeposit;
use App\Models\BoardingItemDepositItem;
use App\Models\Gender;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\BoardingConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Titipan barang santri (gudang titipan pengasuhan): pembuatan titipan,
 * siklus pinjam-kembali per barang, penutupan, dan audit.
 */
class BoardingItemDepositTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(BoardingConfigSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function student(string $nis): Student
    {
        $g = Gender::query()->firstOrCreate(['code' => 'L'], ['name' => 'Laki-laki']);

        return Student::create([
            'nis' => $nis,
            'full_name' => 'Santri '.$nis,
            'gender_id' => $g->id,
            'is_active' => true,
        ]);
    }

    private function createDeposit(int $studentId, array $items = [['item_type' => 'laptop', 'item_name' => 'Laptop Acer']]): BoardingItemDeposit
    {
        $this->postJson('/api/v1/boarding/item-deposits', [
            'student_id' => $studentId,
            'notes' => 'Titip selama semester',
            'items' => $items,
        ])->assertCreated();

        /** @var BoardingItemDeposit $deposit */
        $deposit = BoardingItemDeposit::query()->where('student_id', $studentId)->latest('id')->firstOrFail();

        return $deposit;
    }

    public function test_titipan_tercatat_bersama_barangnya(): void
    {
        $this->actingAsRole('kepala-asrama');
        $student = $this->student('4100');

        $deposit = $this->createDeposit($student->id, [
            ['item_type' => 'laptop', 'item_name' => 'Laptop Acer Swift'],
            ['item_type' => 'hp', 'item_name' => 'Samsung A52'],
        ]);

        $this->assertDatabaseCount('boarding_item_deposit_items', 2);
        $this->assertSame('active', $deposit->status);

        $data = $this->getJson('/api/v1/boarding/item-deposits/'.$deposit->id)->assertOk()->json('data');
        $this->assertCount(2, $data['items']);
        $this->assertSame(2, $data['items_count']);
        $this->assertSame(0, $data['borrowed_count']);
        $this->assertSame('4100', $data['student']['nis']);
    }

    public function test_titipan_tanpa_barang_ditolak(): void
    {
        $this->actingAsRole('kepala-asrama');
        $student = $this->student('4101');

        $this->postJson('/api/v1/boarding/item-deposits', [
            'student_id' => $student->id,
            'items' => [],
        ])->assertStatus(422);
    }

    public function test_siklus_pinjam_dan_kembali_dan_ganda_pinjam_ditolak(): void
    {
        $this->actingAsRole('kepala-asrama');
        $student = $this->student('4102');

        $deposit = $this->createDeposit($student->id);
        $item = $deposit->items()->firstOrFail();

        // Pinjamkan.
        $this->postJson("/api/v1/boarding/item-deposits/{$deposit->id}/items/{$item->id}/borrow", [
            'notes' => 'Untuk tugas',
        ])->assertOk();

        $this->assertSame('borrowed', $item->fresh()->status);
        $this->assertDatabaseHas('boarding_item_deposit_item_loans', [
            'item_id' => $item->id,
            'returned_at' => null,
        ]);

        // Pinjamkan lagi saat masih dipinjam → ditolak.
        $this->postJson("/api/v1/boarding/item-deposits/{$deposit->id}/items/{$item->id}/borrow")
            ->assertStatus(422);

        // Terima kembali.
        $this->postJson("/api/v1/boarding/item-deposits/{$deposit->id}/items/{$item->id}/return")
            ->assertOk();

        $this->assertSame('in_storage', $item->fresh()->status);
        $this->assertDatabaseMissing('boarding_item_deposit_item_loans', [
            'item_id' => $item->id,
            'returned_at' => null,
        ]);

        // Audit kanal boarding mencatat kedua transisi.
        $this->assertTrue(
            Activity::query()->where('log_name', 'boarding')
                ->where('event', 'BOARDING_ITEM_DEPOSIT_ITEM_BORROWED')->exists()
        );
        $this->assertTrue(
            Activity::query()->where('log_name', 'boarding')
                ->where('event', 'BOARDING_ITEM_DEPOSIT_ITEM_RETURNED')->exists()
        );
    }

    public function test_penutupan_titipan_mensyaratkan_tidak_ada_barang_dipinjam(): void
    {
        $this->actingAsRole('kepala-asrama');
        $student = $this->student('4103');

        $deposit = $this->createDeposit($student->id, [
            ['item_type' => 'laptop', 'item_name' => 'Laptop'],
            ['item_type' => 'kamera', 'item_name' => 'Kamera DSLR'],
        ]);
        [$laptop, $camera] = $deposit->items()->orderBy('id')->get();

        $this->postJson("/api/v1/boarding/item-deposits/{$deposit->id}/items/{$laptop->id}/borrow")->assertOk();

        // Tutup saat barang dipinjam → ditolak.
        $this->postJson("/api/v1/boarding/item-deposits/{$deposit->id}/finish")->assertStatus(422);

        // Kembalikan dulu, lalu tutup.
        $this->postJson("/api/v1/boarding/item-deposits/{$deposit->id}/items/{$laptop->id}/return")->assertOk();
        $this->postJson("/api/v1/boarding/item-deposits/{$deposit->id}/finish")->assertOk();

        $fresh = $deposit->fresh();
        $this->assertSame('returned', $fresh->status);
        $this->assertNotNull($fresh->returned_at);
        $this->assertSame('returned_permanently', $laptop->fresh()->status);
        $this->assertSame('returned_permanently', $camera->fresh()->status);

        // Titipan tertutup tidak dapat diubah lagi.
        $this->postJson("/api/v1/boarding/item-deposits/{$deposit->id}/items", [
            'item_type' => 'hp',
            'item_name' => 'HP baru',
        ])->assertStatus(422);
    }

    public function test_barang_dapat_ditambah_dan_dihapus_saat_aktif(): void
    {
        $this->actingAsRole('kepala-asrama');
        $student = $this->student('4104');

        $deposit = $this->createDeposit($student->id);
        $existing = $deposit->items()->firstOrFail();

        // Tambah barang baru.
        $this->postJson("/api/v1/boarding/item-deposits/{$deposit->id}/items", [
            'item_type' => 'ipad',
            'item_name' => 'iPad Gen 9',
        ])->assertCreated();

        $this->assertSame(2, $deposit->items()->count());

        // Barang yang sedang dipinjam tidak bisa dihapus.
        $this->postJson("/api/v1/boarding/item-deposits/{$deposit->id}/items/{$existing->id}/borrow")->assertOk();
        $this->deleteJson("/api/v1/boarding/item-deposits/{$deposit->id}/items/{$existing->id}")->assertStatus(422);
        $this->postJson("/api/v1/boarding/item-deposits/{$deposit->id}/items/{$existing->id}/return")->assertOk();

        // Barang di gudang boleh dihapus.
        $this->deleteJson("/api/v1/boarding/item-deposits/{$deposit->id}/items/{$existing->id}")->assertOk();
        $this->assertSame(1, $deposit->items()->count());
    }

    public function test_peran_tanpa_hak_deposit_ditolak(): void
    {
        $this->actingAsRole('petugas-absensi'); // tidak punya boarding.deposit.*
        $student = $this->student('4105');

        $this->getJson('/api/v1/boarding/item-deposits')->assertForbidden();
        $this->postJson('/api/v1/boarding/item-deposits', [
            'student_id' => $student->id,
            'items' => [['item_type' => 'laptop', 'item_name' => 'X']],
        ])->assertForbidden();
    }

    public function test_daftar_terfilter_pencarian_dan_status_barang(): void
    {
        $this->actingAsRole('kepala-asrama');
        $withBorrowed = $this->student('4106');
        $plain = $this->student('4107');

        $borrowed = $this->createDeposit($withBorrowed->id);
        $item = $borrowed->items()->firstOrFail();
        $this->postJson("/api/v1/boarding/item-deposits/{$borrowed->id}/items/{$item->id}/borrow")->assertOk();

        $this->createDeposit($plain->id, [['item_type' => 'custom', 'item_name' => 'Rice Cooker']]);

        // Filter status "borrowed" → hanya titipan ber-barang dipinjam.
        $rows = $this->getJson('/api/v1/boarding/item-deposits?status=borrowed')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($borrowed->id, $rows[0]['id']);

        // Pencarian nama santri.
        $rows = $this->getJson('/api/v1/boarding/item-deposits?search=4107')->assertOk()->json('data');
        $this->assertCount(1, $rows);

        // Pencarian nama barang.
        $rows = $this->getJson('/api/v1/boarding/item-deposits?item_search=Rice')->assertOk()->json('data');
        $this->assertCount(1, $rows);
    }
}
