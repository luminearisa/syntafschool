<?php

namespace Tests\Feature;

use App\Models\BoardingHealthRecord;
use App\Models\BoardingPlacement;
use App\Models\BoardingRoom;
use App\Models\Gender;
use App\Models\Student;
use App\Models\User;
use App\Services\Boarding\BoardingPlacementService;
use Database\Seeders\BoardingConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prestasi (§41) dan kesehatan asrama (§39): CRUD prestasi, riwayat kesehatan
 * dengan mesin transisi status, serta isolasi sebagai mutasi penempatan.
 */
class BoardingAchievementHealthTest extends TestCase
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

    private function placedStudent(string $nis, string $roomCode = 'A101'): Student
    {
        $g = Gender::query()->firstOrCreate(['code' => 'L'], ['name' => 'Laki-laki']);
        $student = Student::create([
            'nis' => $nis,
            'full_name' => 'Santri '.$nis,
            'gender_id' => $g->id,
            'is_active' => true,
        ]);

        app(BoardingPlacementService::class)->assign([
            'student_id' => $student->id,
            'room_id' => BoardingRoom::query()->where('code', $roomCode)->value('id'),
        ], User::factory()->create(['is_active' => true]));

        return $student;
    }

    /** Kamar isolasi di gedung yang sama dengan A101, tanpa tempat tidur. */
    private function isolationRoom(string $code = 'ISO1'): BoardingRoom
    {
        $source = BoardingRoom::query()->where('code', 'A101')->firstOrFail();

        return BoardingRoom::query()->firstOrCreate(
            ['dormitory_id' => $source->dormitory_id, 'code' => $code],
            [
                'floor_id' => $source->floor_id,
                'name' => 'Ruang Isolasi '.$code,
                'capacity' => 4,
                'gender' => $source->gender,
                'room_type' => BoardingRoom::TYPE_ISOLATION,
                'status' => BoardingRoom::STATUS_ACTIVE,
            ],
        );
    }

    // ================================================ prestasi (§41)

    public function test_prestasi_dicatat_dan_label_terpetakan(): void
    {
        // kepala-asrama: achievement.view + manage tanpa pembatasan scope pengasuh.
        $this->actingAsRole('kepala-asrama');
        $student = $this->placedStudent('6001');

        $id = $this->postJson('/api/v1/boarding/achievements', [
            'student_id' => $student->id,
            'title' => 'Juara 1 MHQ antar-asrama',
            'category' => 'tahfidz',
            'level' => 'sekolah',
            'date' => now()->toDateString(),
            'description' => 'Tingkat antar-kamar.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.category_label', 'Tahfidz')
            ->assertJsonPath('data.level_label', 'Tingkat Sekolah')
            ->json('data.id');

        $this->getJson('/api/v1/boarding/achievements/'.$id)
            ->assertOk()
            ->assertJsonPath('data.title', 'Juara 1 MHQ antar-asrama');

        $this->patchJson('/api/v1/boarding/achievements/'.$id, ['level' => 'kota'])
            ->assertOk()
            ->assertJsonPath('data.level_label', 'Tingkat Kota/Kabupaten');
    }

    public function test_prestasi_validasi_kategori_dan_level(): void
    {
        $this->actingAsRole('kepala-asrama');
        $student = $this->placedStudent('6002');

        $this->postJson('/api/v1/boarding/achievements', [
            'student_id' => $student->id,
            'title' => 'X',
            'category' => 'bukan-kategori',
            'date' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('category');

        $this->postJson('/api/v1/boarding/achievements', [
            'student_id' => $student->id,
            'title' => 'Juara Cerdas Cermat',
            'category' => 'academic',
            'level' => 'internasional',
            'date' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('level');
    }

    public function test_prestasi_butuh_hak_manage_sendiri(): void
    {
        $student = $this->placedStudent('6003');
        $payload = [
            'student_id' => $student->id,
            'title' => 'Pidato Bahasa Arab',
            'category' => 'language',
            'date' => now()->toDateString(),
        ];

        // petugas-kedisiplinan: achievement.view tapi tidak achievement.manage.
        $this->actingAsRole('petugas-kedisiplinan');
        $this->postJson('/api/v1/boarding/achievements', $payload)->assertForbidden();
        $this->getJson('/api/v1/boarding/achievements')->assertOk();
    }

    public function test_opsi_dan_ringkasan_prestasi(): void
    {
        $this->actingAsRole('kepala-asrama');
        $student = $this->placedStudent('6004');

        foreach ([['tahfidz', 'sekolah'], ['tahfidz', 'kota'], ['sports', null]] as $i => [$category, $level]) {
            $this->postJson('/api/v1/boarding/achievements', array_filter([
                'student_id' => $student->id,
                'title' => 'Prestasi '.$category.'-'.($level ?? 'tanpa-level').' '.$i,
                'category' => $category,
                'level' => $level,
                'date' => now()->toDateString(),
            ], static fn ($v) => $v !== null))->assertCreated();
        }

        $this->getJson('/api/v1/boarding/achievements/options')
            ->assertOk()
            ->assertJsonPath('data.categories.tahfidz', 'Tahfidz')
            ->assertJsonPath('data.levels.sekolah', 'Tingkat Sekolah');

        $this->getJson('/api/v1/boarding/achievements/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.by_category.tahfidz', 2)
            ->assertJsonPath('data.by_category.sports', 1)
            ->assertJsonPath('data.by_level.sekolah', 1)
            ->assertJsonPath('data.by_level.kota', 1);
    }

    // ================================================ kesehatan (§39)

    private function healthPayload(Student $student, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $student->id,
            'condition' => 'Demam',
            'complaint' => 'Suhu 38,5°C sejak malam.',
            'date' => now()->toDateString(),
        ], $overrides);
    }

    public function test_catatan_kesehatan_dan_rantai_transisi_status(): void
    {
        $this->actingAsRole('petugas-kesehatan');
        $student = $this->placedStudent('6005');

        $id = $this->postJson('/api/v1/boarding/health-records', $this->healthPayload($student))
            ->assertCreated()
            ->assertJsonPath('data.status', 'reported')
            ->assertJsonPath('data.is_open', true)
            ->json('data.id');

        $this->postJson('/api/v1/boarding/health-records/'.$id.'/status', ['status' => 'being_treated'])
            ->assertOk()
            ->assertJsonPath('data.status', 'being_treated')
            ->assertJsonPath('data.allowed_next_statuses.0', 'recovered');

        $this->postJson('/api/v1/boarding/health-records/'.$id.'/status', ['status' => 'recovered'])
            ->assertOk()
            ->assertJsonPath('data.is_open', false);

        // recovered adalah kondisi akhir — tidak ada transisi lanjutan.
        $this->postJson('/api/v1/boarding/health-records/'.$id.'/status', ['status' => 'being_treated'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_status_dirujuk_wajib_mencantumkan_tindakan(): void
    {
        $this->actingAsRole('petugas-kesehatan');
        $student = $this->placedStudent('6006');

        $id = $this->postJson('/api/v1/boarding/health-records', $this->healthPayload($student, ['condition' => 'Suspek demam berdarah']))
            ->assertCreated()->json('data.id');

        $this->postJson('/api/v1/boarding/health-records/'.$id.'/status', ['status' => 'referred'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->postJson('/api/v1/boarding/health-records/'.$id.'/status', [
            'status' => 'referred',
            'action' => 'Dibawa ke RS dengan surat pengantar asrama.',
        ])->assertOk()->assertJsonPath('data.status', 'referred');
    }

    public function test_pengobatan_musyrif_hanya_baca_catatan_kesehatan(): void
    {
        $student = $this->placedStudent('6007');

        $this->actingAsRole('musyrif');
        $this->getJson('/api/v1/boarding/health-records')->assertOk();
        $this->postJson('/api/v1/boarding/health-records', $this->healthPayload($student))->assertForbidden();
    }

    // ================================================ isolasi

    public function test_isolasi_adalah_mutasi_penempatan_dan_bisa_dilepas(): void
    {
        $this->actingAsRole('petugas-kesehatan');
        $student = $this->placedStudent('6008', 'A101');
        $iso = $this->isolationRoom();

        $healthId = $this->postJson('/api/v1/boarding/health-records', $this->healthPayload($student, ['condition' => 'Campak']))
            ->assertCreated()->json('data.id');

        $placement = $this->postJson('/api/v1/boarding/isolation', [
            'student_id' => $student->id,
            'room_id' => $iso->id,
            'reason' => 'Kontak erat dengan pasien campak, perlu observasi terpisah.',
            'health_record_id' => $healthId,
        ])
            ->assertCreated()
            ->assertJsonPath('data.room_id', $iso->id)
            ->json('data');

        // Placement lama ditutup dengan alasan isolasi.
        $old = BoardingPlacement::query()
            ->where('student_id', $student->id)
            ->where('status', BoardingPlacement::STATUS_INACTIVE)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('isolation', $old->end_reason);
        $this->assertSame(
            'A101',
            BoardingRoom::find($old->room_id)->code,
        );

        // Daftar penghuni isolasi menampilkan santri ini.
        $this->getJson('/api/v1/boarding/health-records/isolations')
            ->assertOk()
            ->assertJsonPath('data.0.student_id', $student->id);

        // Pemulihan → kembali ke kamar asal (A101) karena room_id tidak dikirim.
        $this->postJson('/api/v1/boarding/isolation/'.$placement['id'].'/release', [
            'reason' => 'Sudah sembuh, kembali ke kamar asal.',
        ])
            ->assertOk()
            ->assertJsonPath('data.room_id', BoardingRoom::query()->where('code', 'A101')->value('id'));

        $this->assertSame(
            (int) BoardingRoom::query()->where('code', 'A101')->value('id'),
            (int) BoardingPlacement::query()->where('student_id', $student->id)
                ->where('status', BoardingPlacement::STATUS_ACTIVE)->value('room_id'),
        );

        $this->assertSame(0, BoardingPlacement::query()->where('student_id', $student->id)
            ->where('status', BoardingPlacement::STATUS_ACTIVE)->where('room_id', $iso->id)->count());
    }

    public function test_isolasi_menolak_room_bukan_isolasi_dan_alasan_kosong(): void
    {
        $this->actingAsRole('petugas-kesehatan');
        $student = $this->placedStudent('6009', 'A101');

        $this->postJson('/api/v1/boarding/isolation', [
            'student_id' => $student->id,
            'room_id' => BoardingRoom::query()->where('code', 'A102')->value('id'),
            'reason' => 'Demam tinggi',
        ])->assertStatus(422)->assertJsonValidationErrors('room_id');

        $this->postJson('/api/v1/boarding/isolation', [
            'student_id' => $student->id,
            'room_id' => $this->isolationRoom('ISO2')->id,
            'reason' => 'ab',
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_isolasi_mengubah_status_monitoring_santri(): void
    {
        $this->actingAsRole('petugas-kesehatan');
        $student = $this->placedStudent('6010', 'A101');
        $iso = $this->isolationRoom('ISO3');

        // Status isolasi hanya menyala bila ada catatan kesehatan terbuka §39+§42.
        $this->postJson('/api/v1/boarding/health-records', $this->healthPayload($student, ['condition' => 'Campak']))
            ->assertCreated();

        $this->postJson('/api/v1/boarding/isolation', [
            'student_id' => $student->id,
            'room_id' => $iso->id,
            'reason' => 'Suspek penyakit menular',
        ])->assertCreated();

        // Petugas kesehatan tidak punya dashboard.view — pakai kepala asrama.
        $this->actingAsRole('kepala-asrama');
        $this->getJson('/api/v1/boarding/monitoring/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'isolation')
            ->assertJsonPath('data.room_type', 'isolation');
    }

    public function test_hapus_catatan_kesehatan(): void
    {
        $this->actingAsRole('petugas-kesehatan');
        $student = $this->placedStudent('6011');

        $id = $this->postJson('/api/v1/boarding/health-records', $this->healthPayload($student))
            ->assertCreated()->json('data.id');

        $this->deleteJson('/api/v1/boarding/health-records/'.$id)->assertOk();
        $this->assertDatabaseMissing('boarding_health_records', ['id' => $id]);
    }
}
