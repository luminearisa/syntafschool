<?php

namespace Tests\Feature;

use App\Models\BoardingBed;
use App\Models\BoardingDormitory;
use App\Models\BoardingPlacement;
use App\Models\BoardingRoom;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\BoardingConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Struktur asrama (§5–§8): asrama → gedung → lantai → kamar → bed, plus
 * pengaturan modul dan proteksi hapus — lewat HTTP, seperti dipakai frontend.
 */
class BoardingMasterTest extends TestCase
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
        $email = str_replace('-', '.', $role).'@schoolerp.test';

        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function dormitory(string $code = 'ASM-01'): BoardingDormitory
    {
        return BoardingDormitory::query()->where('code', $code)->firstOrFail();
    }

    private function room(string $code = 'A101'): BoardingRoom
    {
        return BoardingRoom::query()->where('code', $code)->firstOrFail();
    }

    private function student(string $nis): Student
    {
        return Student::create(['nis' => $nis, 'full_name' => 'Santri '.$nis, 'is_active' => true]);
    }

    // =================================================================
    // CRUD rantai struktur
    // =================================================================

    public function test_rantai_struktur_dapat_dibuat_lewat_api(): void
    {
        $this->actingAsRole('admin-boarding');

        $dormitoryId = $this->postJson('/api/v1/boarding/dormitories', [
            'code' => 'asm-03',
            'name' => 'Asrama Ibnu Sina',
            'gender' => 'male',
        ])->assertCreated()->json('data.id');

        // Kode diseragamkan huruf besar seperti modul lain.
        $this->assertSame('ASM-03', $this->getJson('/api/v1/boarding/dormitories/'.$dormitoryId)->assertOk()->json('data.code'));

        $buildingId = $this->postJson('/api/v1/boarding/buildings', [
            'dormitory_id' => $dormitoryId,
            'code' => 'GED-B',
            'name' => 'Gedung B',
            'floor_count' => 1,
        ])->assertCreated()->json('data.id');

        $floorId = $this->postJson('/api/v1/boarding/floors', [
            'building_id' => $buildingId,
            'floor_number' => 1,
            'name' => 'Lantai 1',
        ])->assertCreated()->json('data.id');

        $roomId = $this->postJson('/api/v1/boarding/rooms', [
            'floor_id' => $floorId,
            'code' => 'C101',
            'name' => 'Kamar C101',
            'capacity' => 4,
            'gender' => 'male',
        ])->assertCreated()->json('data');

        // dormitory_id denormalisasi ikut terisi dari lantai.
        $this->assertSame($dormitoryId, $roomId['dormitory_id']);
        $this->assertArrayHasKey('current_occupancy', $roomId);
        $this->assertSame(0, $roomId['current_occupancy']);

        $this->postJson('/api/v1/boarding/beds', [
            'room_id' => $roomId['id'],
            'bed_number' => '01',
        ])->assertCreated();
    }

    public function test_kode_duplikat_ditolak(): void
    {
        $this->actingAsRole('admin-boarding');

        $this->postJson('/api/v1/boarding/dormitories', [
            'code' => 'ASM-01',
            'name' => 'Asrama Kembar',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        // Nomor kamar unik per asrama, bukan global: ASM-02 boleh memakai 'A101'.
        $this->postJson('/api/v1/boarding/beds', [
            'room_id' => $this->room('A101')->id,
            'bed_number' => '01',
        ])->assertStatus(422)->assertJsonValidationErrors('bed_number');
    }

    public function test_kode_dapat_dipakai_usulang_setelah_data_dihapus_lunak(): void
    {
        $this->actingAsRole('admin-boarding');

        // Validasi mengabaikan baris soft-deleted, jadi indeks unique di DB
        // juga harus begitu — membuat ulang kode kamar lama tidak boleh 500.
        $room = $this->room('A102');
        $this->deleteJson('/api/v1/boarding/rooms/'.$room->id)->assertOk();

        $this->postJson('/api/v1/boarding/rooms', [
            'floor_id' => $room->floor_id,
            'code' => 'A102',
            'name' => 'Kamar A102 (bangkit ulang)',
            'capacity' => 4,
        ])->assertCreated();

        // Hal yang sama untuk asrama dengan kode unik global.
        $dormitoryId = $this->postJson('/api/v1/boarding/dormitories', [
            'code' => 'ASM-07',
            'name' => 'Asrama Al-Biruni',
            'gender' => 'female',
        ])->assertCreated()->json('data.id');
        $this->deleteJson('/api/v1/boarding/dormitories/'.$dormitoryId)->assertOk();
        $this->postJson('/api/v1/boarding/dormitories', [
            'code' => 'ASM-07',
            'name' => 'Asrama Al-Biruni (bangkit ulang)',
            'gender' => 'female',
        ])->assertCreated();
    }

    // =================================================================
    // Proteksi hapus
    // =================================================================

    public function test_asrama_tidak_boleh_dihapus_selama_masih_ada_gedung_atau_penempatan(): void
    {
        $this->actingAsRole('admin-boarding');

        $this->deleteJson('/api/v1/boarding/dormitories/'.$this->dormitory()->id)
            ->assertStatus(422);
    }

    public function test_kamar_yang_masih_ditempati_tidak_boleh_dihapus(): void
    {
        $this->actingAsRole('admin-boarding');

        $room = $this->room();
        BoardingPlacement::create([
            'student_id' => $this->student('1001')->id,
            'dormitory_id' => $room->dormitory_id,
            'room_id' => $room->id,
            'start_date' => now()->toDateString(),
            'status' => BoardingPlacement::STATUS_ACTIVE,
        ]);

        $this->deleteJson('/api/v1/boarding/rooms/'.$room->id)
            ->assertStatus(422)
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('boarding_placements', ['room_id' => $room->id, 'status' => 'active']);
    }

    public function test_kamar_dihapus_lembut_dan_induknya_lalu_boleh_dihapus(): void
    {
        // Kamar/gedung/asrama memakai soft delete; bed ikut hilang saat
        // baris kamar benar-benar dicabut (cascade FK) — yang diuji di sini:
        // setelah kamar "hapus", lantai → gedung → asrama boleh dihapus API.
        $this->actingAsRole('admin-boarding');

        $room = $this->room('A101');
        $roomIds = BoardingRoom::query()->where('dormitory_id', $room->dormitory_id)->pluck('id');
        $floorIds = BoardingRoom::query()->where('dormitory_id', $room->dormitory_id)->pluck('floor_id')->unique();
        $building = $room->floor->building;
        $dormitory = $room->dormitory;

        foreach ($roomIds as $id) {
            $this->deleteJson('/api/v1/boarding/rooms/'.$id)->assertOk();
        }

        $this->assertSoftDeleted('boarding_rooms', ['id' => $room->id]);

        foreach ($floorIds as $floorId) {
            $this->deleteJson('/api/v1/boarding/floors/'.$floorId)->assertOk();
        }

        $this->deleteJson('/api/v1/boarding/buildings/'.$building->id)->assertOk();
        $this->deleteJson('/api/v1/boarding/dormitories/'.$dormitory->id)->assertOk();
        $this->assertSoftDeleted('boarding_dormitories', ['id' => $dormitory->id]);
    }

    public function test_bed_yang_ditempati_tidak_boleh_dihapus(): void
    {
        $this->actingAsRole('admin-boarding');

        $room = $this->room();
        $bed = BoardingBed::query()->where('room_id', $room->id)->orderBy('bed_number')->firstOrFail();

        BoardingPlacement::create([
            'student_id' => $this->student('1002')->id,
            'dormitory_id' => $room->dormitory_id,
            'room_id' => $room->id,
            'bed_id' => $bed->id,
            'start_date' => now()->toDateString(),
            'status' => BoardingPlacement::STATUS_ACTIVE,
        ]);

        $this->deleteJson('/api/v1/boarding/beds/'.$bed->id)->assertStatus(422);
        $this->patchJson('/api/v1/boarding/beds/'.$bed->id, ['status' => 'maintenance'])->assertStatus(422);
    }

    // =================================================================
    // Bulk bed
    // =================================================================

    public function test_bulk_bed_mengisi_kamar_baru_dengan_nomor_berurutan(): void
    {
        $this->actingAsRole('admin-boarding');

        $dormitoryId = $this->dormitory('ASM-02')->id;

        // Kamar kosong tanpa bed sama sekali.
        $roomId = BoardingRoom::create([
            'floor_id' => BoardingRoom::query()->where('code', 'B101')->value('floor_id'),
            'dormitory_id' => $dormitoryId,
            'code' => 'B900',
            'name' => 'Kamar Percobaan',
            'capacity' => 5,
            'gender' => 'female',
            'status' => BoardingRoom::STATUS_ACTIVE,
        ])->id;

        $this->postJson('/api/v1/boarding/beds/bulk', ['room_id' => $roomId, 'count' => 5])
            ->assertCreated()
            ->assertJsonPath('data.created', 5);

        $numbers = BoardingBed::query()->where('room_id', $roomId)->orderBy('bed_number')->pluck('bed_number')->all();
        $this->assertSame(['01', '02', '03', '04', '05'], $numbers);

        // Batch kedua melompati nomor yang sudah ada.
        $this->postJson('/api/v1/boarding/beds/bulk', ['room_id' => $roomId, 'count' => 2])
            ->assertCreated()
            ->assertJsonPath('data.created', 2);

        $this->assertDatabaseHas('boarding_beds', ['room_id' => $roomId, 'bed_number' => '06']);
        $this->assertDatabaseHas('boarding_beds', ['room_id' => $roomId, 'bed_number' => '07']);
    }

    // =================================================================
    // Otorisasi
    // =================================================================

    public function test_role_tanpa_hak_struktur_ditolak(): void
    {
        $this->actingAsRole('siswa');
        $this->getJson('/api/v1/boarding/dormitories')->assertStatus(403);

        // Petugas absensi boleh melihat asrama untuk filter, bukan membuatnya.
        $this->actingAsRole('petugas-absensi');
        $this->postJson('/api/v1/boarding/dormitories', ['code' => 'X', 'name' => 'Tanpa Hak'])->assertStatus(403);
    }

    // =================================================================
    // Pengaturan modul
    // =================================================================

    public function test_pengaturan_dibaca_dan_disimpan(): void
    {
        $this->actingAsRole('admin-boarding');

        $response = $this->getJson('/api/v1/boarding/settings')->assertOk();
        $response->assertJsonPath('data.point_threshold_warning', 25);

        $this->putJson('/api/v1/boarding/settings', [
            'settings' => ['point_threshold_warning' => 30, 'permission_approval_steps' => 2],
        ])->assertOk()->assertJsonPath('data.point_threshold_warning', 30);

        $this->getJson('/api/v1/boarding/settings')
            ->assertOk()
            ->assertJsonPath('data.point_threshold_warning', 30);

        // Kunci tak dikenal ditolak, bukan disimpan diam-diam.
        $this->putJson('/api/v1/boarding/settings', ['settings' => 'nonsens'])
            ->assertStatus(422);
    }

    public function test_role_non_admin_tidak_boleh_mengubah_pengaturan(): void
    {
        $this->actingAsRole('petugas-kedisiplinan');

        $this->putJson('/api/v1/boarding/settings', ['settings' => ['point_threshold_warning' => 1]])
            ->assertStatus(403);
    }

    // =================================================================
    // Audit & listing
    // =================================================================

    public function test_perubahan_struktur_tercatat_di_log_boarding(): void
    {
        $this->actingAsRole('admin-boarding');

        $this->postJson('/api/v1/boarding/dormitories', ['code' => 'ASM-AUDIT', 'name' => 'Asrama Audit'])
            ->assertCreated();

        $this->assertTrue(
            Activity::query()->where('log_name', 'boarding')->where('description', 'like', '%created%')->exists(),
            'Aktivitas boarding untuk pembuatan asrama harus tercatat.'
        );
    }

    public function test_indeks_kamar_memuat_hitungan_okupansi_dan_filter(): void
    {
        $this->actingAsRole('admin-boarding');

        $response = $this->getJson('/api/v1/boarding/rooms?dormitory_id='.$this->dormitory()->id.'&all=1')
            ->assertOk();

        // ASM-01 punya A101, A102, A201, A202.
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame(['A101', 'A102', 'A201', 'A202'], $codes);
        $this->assertSame(8, $response->json('data.0.available_beds'));
        $this->assertSame(0, $response->json('data.0.current_occupancy'));

        $this->getJson('/api/v1/boarding/rooms?search=A2&all=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
