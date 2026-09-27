<?php

namespace Tests\Feature;

use App\Models\BoardingBed;
use App\Models\BoardingDormitory;
use App\Models\BoardingPlacement;
use App\Models\BoardingRoom;
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
 * Penempatan & mutasi santri (§9–§12) lewat HTTP.
 *
 * Aturan bisnis yang diuji: satu placement aktif per santri, cocok gender,
 * kapasitas kamar, bed ganda, rantai riwayat transfer (placement lama ditutup
 * + ditautkan), penutupan, dan status bed yang ikut berubah.
 */
class BoardingPlacementTest extends TestCase
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

    private function student(string $nis, string $gender = 'L'): Student
    {
        $g = Gender::query()->firstOrCreate(
            ['code' => $gender],
            ['name' => $gender === 'L' ? 'Laki-laki' : 'Perempuan'],
        );

        return Student::create([
            'nis' => $nis,
            'full_name' => 'Santri '.$nis,
            'gender_id' => $g->id,
            'is_active' => true,
        ]);
    }

    private function room(string $code): BoardingRoom
    {
        return BoardingRoom::query()->where('code', $code)->firstOrFail();
    }

    private function bed(BoardingRoom $room, string $number): BoardingBed
    {
        return BoardingBed::query()->where('room_id', $room->id)->where('bed_number', $number)->firstOrFail();
    }

    /** @return array{id:int,...} data placement dari respons */
    private function assign(Student $student, BoardingRoom $room, ?BoardingBed $bed = null): array
    {
        return $this->postJson('/api/v1/boarding/placements', array_filter([
            'student_id' => $student->id,
            'room_id' => $room->id,
            'bed_id' => $bed?->id,
        ], fn (mixed $v): bool => $v !== null))
            ->assertCreated()
            ->json('data');
    }

    // =================================================================
    // Penempatan baru
    // =================================================================

    public function test_santri_dapat_ditempatkan_dan_bed_berubah_terisi(): void
    {
        $this->actingAsRole('admin-boarding');

        $student = $this->student('2001');
        $room = $this->room('A101');
        $bed = $this->bed($room, '01');

        $data = $this->assign($student, $room, $bed);

        $this->assertSame('active', $data['status']);
        $this->assertSame($room->id, $data['room']['id']);
        $this->assertSame($bed->id, $data['bed']['id']);
        // Rantai lokasi denormalisasi diisi otomatis oleh service.
        $this->assertSame($room->dormitory_id, $data['dormitory']['id']);
        $this->assertSame('occupied', $this->bed($room, '01')->fresh()?->status ?? $bed->fresh()->status);

        // Penempatan aktif santri terbaca lewat endpoint khusus.
        $this->getJson('/api/v1/boarding/students/'.$student->id.'/placement')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->getJson('/api/v1/boarding/students/'.$student->id.'/placements')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_penempatan_aktif_kedua_ditolak(): void
    {
        $this->actingAsRole('admin-boarding');

        $student = $this->student('2002');
        $this->assign($student, $this->room('A101'));

        $this->postJson('/api/v1/boarding/placements', [
            'student_id' => $student->id,
            'room_id' => $this->room('A102')->id,
        ])->assertStatus(422)->assertJsonValidationErrors('student_id');
    }

    public function test_gender_santri_harus_cocok_dengan_kamar(): void
    {
        $this->actingAsRole('admin-boarding');

        $putri = $this->student('2003', 'P');

        $this->postJson('/api/v1/boarding/placements', [
            'student_id' => $putri->id,
            'room_id' => $this->room('A101')->id,
        ])->assertStatus(422)->assertJsonValidationErrors('room_id');

        // Kamar putri diterima.
        $this->assign($putri, $this->room('B101'));
    }

    public function test_kamar_penuh_menolak_penempatan_tanpa_bed(): void
    {
        $this->actingAsRole('admin-boarding');

        $room = $this->room('A201'); // kapasitas 8

        for ($i = 1; $i <= 8; $i++) {
            $this->assign($this->student('210'.$i), $room);
        }

        $this->postJson('/api/v1/boarding/placements', [
            'student_id' => $this->student('2109')->id,
            'room_id' => $room->id,
        ])->assertStatus(422)->assertJsonValidationErrors('room_id');
    }

    public function test_bed_yang_sudah_terisi_ditolak(): void
    {
        $this->actingAsRole('admin-boarding');

        $room = $this->room('A202');
        $bed = $this->bed($room, '03');

        $this->assign($this->student('2010'), $room, $bed);

        $this->postJson('/api/v1/boarding/placements', [
            'student_id' => $this->student('2011')->id,
            'room_id' => $room->id,
            'bed_id' => $bed->id,
        ])->assertStatus(422)->assertJsonValidationErrors('bed_id');
    }

    public function test_bed_harus_milik_kamar_yang_ditujukan(): void
    {
        $this->actingAsRole('admin-boarding');

        $this->postJson('/api/v1/boarding/placements', [
            'student_id' => $this->student('2012')->id,
            'room_id' => $this->room('A102')->id,
            'bed_id' => $this->bed($this->room('A101'), '01')->id,
        ])->assertStatus(422)->assertJsonValidationErrors('bed_id');
    }

    public function test_santri_nonaktif_ditolak(): void
    {
        $this->actingAsRole('admin-boarding');

        $student = $this->student('2013');
        $student->update(['is_active' => false]);

        $this->postJson('/api/v1/boarding/placements', [
            'student_id' => $student->id,
            'room_id' => $this->room('A101')->id,
        ])->assertStatus(422)->assertJsonValidationErrors('student_id');
    }

    // =================================================================
    // Mutasi / transfer
    // =================================================================

    public function test_transfer_menutup_penempatan_lama_dan_menggeser_bed(): void
    {
        $this->actingAsRole('admin-boarding');

        $student = $this->student('2020');
        $old = $this->room('A101');
        $new = $this->room('A102');

        $placement = $this->assign($student, $old, $this->bed($old, '01'));

        $result = $this->postJson('/api/v1/boarding/placements/'.$placement['id'].'/transfer', [
            'room_id' => $new->id,
            'bed_id' => $this->bed($new, '02')->id,
            'reason' => 'Penyesuaian kelompok halaqah',
        ])->assertOk()->json('data');

        $this->assertSame('active', $result['status']);
        $this->assertSame($new->id, $result['room']['id']);

        // Placement lama: ditutup dengan alasan terdeteksi otomatis + tertaut.
        $oldPlacement = BoardingPlacement::find($placement['id']);
        $this->assertSame('inactive', $oldPlacement->status);
        $this->assertSame(BoardingPlacement::END_REASON_TRANSFER_ROOM, $oldPlacement->end_reason);
        $this->assertSame($result['id'], $oldPlacement->replaced_by_placement_id);

        // Bed lama bebas lagi, bed baru terisi.
        $this->assertSame('available', $this->bed($old, '01')->fresh()->status);
        $this->assertSame('occupied', $this->bed($new, '02')->fresh()->status);

        // Riwayat berisi dua baris berurutan.
        $history = $this->getJson('/api/v1/boarding/students/'.$student->id.'/placements')->assertOk()->json('data');
        $this->assertCount(2, $history);
        $this->assertSame('active', $history[0]['status']);
        $this->assertSame('inactive', $history[1]['status']);
    }

    public function test_transfer_sesama_kamar_hanya_bed_terdeteksi_transfer_bed(): void
    {
        $this->actingAsRole('admin-boarding');

        $room = $this->room('A101');
        $placement = $this->assign($this->student('2021'), $room, $this->bed($room, '04'));

        $this->postJson('/api/v1/boarding/placements/'.$placement['id'].'/transfer', [
            'room_id' => $room->id,
            'bed_id' => $this->bed($room, '05')->id,
            'reason' => 'Berbatasan dengan kasur kosong',
        ])->assertOk();

        $old = BoardingPlacement::find($placement['id']);
        $this->assertSame(BoardingPlacement::END_REASON_TRANSFER_BED, $old->end_reason);
    }

    public function test_transfer_lintas_asrama_terdeteksi_transfer_dorm(): void
    {
        $this->actingAsRole('admin-boarding');

        // Kamar B101 dan B201 masih satu asrama (ASM-02) — beda asrama butuh
        // struktur baru agar deteksi `transfer_dorm` benar-benar teruji.
        $dormitory = BoardingDormitory::create([
            'code' => 'ASM-03',
            'name' => 'Asrama Maryam',
            'gender' => 'female',
            'status' => 'active',
        ]);
        $building = \App\Models\BoardingBuilding::create([
            'dormitory_id' => $dormitory->id,
            'code' => 'GED-C',
            'name' => 'Gedung C',
            'status' => 'active',
        ]);
        $floor = \App\Models\BoardingFloor::create([
            'building_id' => $building->id,
            'floor_number' => 1,
            'name' => 'Lantai 1',
        ]);
        $room = BoardingRoom::create([
            'floor_id' => $floor->id,
            'dormitory_id' => $dormitory->id,
            'code' => 'C101',
            'name' => 'Kamar C101',
            'capacity' => 8,
            'gender' => 'female',
            'room_type' => 'standard',
            'status' => 'active',
        ]);

        $student = $this->student('2022', 'P');
        $placement = $this->assign($student, $this->room('B101'), null);

        $this->postJson('/api/v1/boarding/placements/'.$placement['id'].'/transfer', [
            'room_id' => $room->id,
            'reason' => 'Perpindahan asrama',
        ])->assertOk();

        $old = BoardingPlacement::find($placement['id']);
        $this->assertSame(BoardingPlacement::END_REASON_TRANSFER_DORM, $old->end_reason);
    }

    public function test_placement_yang_sudah_ditutup_tidak_boleh_dimutasi(): void
    {
        $this->actingAsRole('admin-boarding');

        $student = $this->student('2023');
        $placement = $this->assign($student, $this->room('A101'));

        $this->postJson('/api/v1/boarding/placements/'.$placement['id'].'/end', [
            'reason' => BoardingPlacement::END_REASON_GRADUATED,
        ])->assertOk();

        $this->postJson('/api/v1/boarding/placements/'.$placement['id'].'/transfer', [
            'room_id' => $this->room('A102')->id,
            'reason' => 'Salah prosedur',
        ])->assertStatus(422);
    }

    // =================================================================
    // Penutupan
    // =================================================================

    public function test_end_melegalkan_penempatan_baru(): void
    {
        $this->actingAsRole('admin-boarding');

        $student = $this->student('2024');
        $room = $this->room('A101');
        $bed = $this->bed($room, '06');

        $placement = $this->assign($student, $room, $bed);

        $this->postJson('/api/v1/boarding/placements/'.$placement['id'].'/end', [
            'reason' => BoardingPlacement::END_REASON_GRADUATED,
            'end_date' => now()->toDateString(),
        ])->assertOk()->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.end_reason', 'graduated');

        $this->assertSame('available', $bed->fresh()->status);

        // Santri yang sama boleh masuk lagi (mis. tahun ajaran berikutnya).
        $this->assign($student, $room, $this->bed($room, '07'));
    }

    public function test_alasan_penutupan_transfer_ditolak_oleh_endpoint_end(): void
    {
        $this->actingAsRole('admin-boarding');

        $placement = $this->assign($this->student('2025'), $this->room('A101'));

        $this->postJson('/api/v1/boarding/placements/'.$placement['id'].'/end', [
            'reason' => BoardingPlacement::END_REASON_TRANSFER_ROOM,
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    // =================================================================
    // Otorisasi & audit
    // =================================================================

    public function test_petugas_absensi_tidak_boleh_menempatkan(): void
    {
        $student = $this->student('2030');
        $roomId = $this->room('A101')->id;

        $this->actingAsRole('petugas-absensi');
        $this->getJson('/api/v1/boarding/placements')->assertStatus(403);

        $this->actingAsRole('wali-kamar');
        $this->postJson('/api/v1/boarding/placements', [
            'student_id' => $student->id,
            'room_id' => $roomId,
        ])->assertStatus(403);
    }

    public function test_setiap_mutasi_penempatan_menghasilkan_activity_log(): void
    {
        $actor = $this->actingAsRole('admin-boarding');

        $student = $this->student('2031');
        $placement = $this->assign($student, $this->room('A101'), $this->bed($this->room('A101'), '08'));

        $this->postJson('/api/v1/boarding/placements/'.$placement['id'].'/transfer', [
            'room_id' => $this->room('A102')->id,
            'reason' => 'Pindah kamar',
        ])->assertOk();

        $events = Activity::query()->where('log_name', 'boarding')->pluck('event')->all();

        $this->assertContains('BOARDING_PLACEMENT_ASSIGNED', $events);
        $this->assertContains('BOARDING_PLACEMENT_TRANSFERRED', $events);
    }

    public function test_indeks_penempatan_bisa_difilter_per_kamar(): void
    {
        $this->actingAsRole('admin-boarding');

        $room = $this->room('A101');
        $this->assign($this->student('2040'), $room);
        $this->assign($this->student('2041'), $this->room('A102'));

        $this->getJson('/api/v1/boarding/placements?room_id='.$room->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/boarding/placements?search=2040')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_okupansi_kamar_mencerminkan_penempatan_aktif(): void
    {
        $this->actingAsRole('admin-boarding');

        $room = $this->room('A101');
        $this->assign($this->student('2050'), $room);
        $this->assign($this->student('2051'), $room);

        $this->getJson('/api/v1/boarding/placements/occupancy?room_id='.$room->id)
            ->assertOk()
            ->assertJsonPath('data.occupied', 2);

        $this->getJson('/api/v1/boarding/rooms/'.$room->id)
            ->assertOk()
            ->assertJsonPath('data.room.current_occupancy', 2)
            ->assertJsonPath('data.room.remaining_capacity', 6);

        $this->getJson('/api/v1/boarding/rooms/'.$room->id.'/occupants')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
