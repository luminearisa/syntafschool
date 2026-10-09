<?php

namespace Tests\Feature;

use App\Models\BoardingHomecoming;
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
 * Kepulangan santri (§30–§31) dan kunjungan tamu (§40): alur approve → jemput →
 * kembali (terlambat per hari), expire, dan buku tamu check-in/check-out.
 */
class BoardingHomecomingVisitorTest extends TestCase
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
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function placedStudent(string $nis): Student
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
            'room_id' => BoardingRoom::query()->where('code', 'A101')->value('id'),
        ], User::factory()->create(['is_active' => true]));

        return $student;
    }

    /** @return array<string, mixed> */
    private function homecomingPayload(Student $student, ?string $home = null, ?string $return = null): array
    {
        return [
            'student_id' => $student->id,
            'type' => 'weekend',
            'home_date' => $home ?? now()->toDateString(),
            'return_date' => $return ?? now()->addDays(2)->toDateString(),
            'transport' => 'Dijemput ayah',
            'picked_up_by' => 'Bapak Ahmad',
        ];
    }

    // =================================================================
    // Kepulangan
    // =================================================================

    public function test_alur_kepulangan_lengkap_sampai_kembali(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4101');

        $id = $this->postJson('/api/v1/boarding/homecomings', [...$this->homecomingPayload($student), 'submit' => true])
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->json('data.id');

        $this->postJson('/api/v1/boarding/homecomings/'.$id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->postJson('/api/v1/boarding/homecomings/'.$id.'/check-out')
            ->assertOk()
            ->assertJsonPath('data.status', 'out')
            ->assertJsonPath('data.is_home', true);

        $this->postJson('/api/v1/boarding/homecomings/'.$id.'/check-in')
            ->assertOk()
            ->assertJsonPath('data.status', 'returned')
            ->assertJsonPath('data.late_return', false);
    }

    public function test_kembali_lewat_tanggal_seharusnya_dicatat_terlambat(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4102');

        $id = $this->postJson('/api/v1/boarding/homecomings', [...$this->homecomingPayload($student, now()->subDays(4)->toDateString(), now()->subDay()->toDateString()), 'submit' => true])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/boarding/homecomings/'.$id.'/approve')->assertOk();
        $this->postJson('/api/v1/boarding/homecomings/'.$id.'/check-out')->assertOk();

        $this->postJson('/api/v1/boarding/homecomings/'.$id.'/check-in', [
            'at' => now()->toDateString(), // satu hari lewat return_date
        ])
            ->assertOk()
            ->assertJsonPath('data.late_return', true);
    }

    public function test_validasi_tanggal_dan_status_kepulangan(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4103');

        // Tanggal kembali sebelum tanggal pulang.
        $this->postJson('/api/v1/boarding/homecomings', $this->homecomingPayload(
            $student,
            now()->toDateString(),
            now()->subDay()->toDateString(),
        ))->assertStatus(422)->assertJsonValidationErrors('return_date');

        // Check in sebelum dijemput (approved, belum out) ditolak.
        $id = $this->postJson('/api/v1/boarding/homecomings', [...$this->homecomingPayload($student), 'submit' => true])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/boarding/homecomings/'.$id.'/approve')->assertOk();
        $this->postJson('/api/v1/boarding/homecomings/'.$id.'/check-in')->assertStatus(422);

        // Santri tanpa penempatan asrama tidak bisa dipulangkan.
        $g = Gender::query()->firstOrCreate(['code' => 'L'], ['name' => 'Laki-laki']);
        $luar = Student::create(['nis' => '4104', 'full_name' => 'Santri 4104', 'gender_id' => $g->id, 'is_active' => true]);
        $this->postJson('/api/v1/boarding/homecomings', $this->homecomingPayload($luar))->assertStatus(422);
    }

    public function test_kepulangan_terbuka_berdouble_pada_rentang_sama_ditolak(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4105');

        $this->postJson('/api/v1/boarding/homecomings', [...$this->homecomingPayload($student), 'submit' => true])
            ->assertCreated();

        // Rentang sama, yang pertama masih `submitted` (terbuka).
        $this->postJson('/api/v1/boarding/homecomings', $this->homecomingPayload($student))
            ->assertStatus(422);
    }

    public function test_expire_kepulangan_dan_satu_keluar_satu_izin(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4106');

        $stale = $this->postJson('/api/v1/boarding/homecomings', [...$this->homecomingPayload(
            $student,
            now()->subDays(10)->toDateString(),
            now()->subDays(8)->toDateString(),
        ), 'submit' => true])
            ->assertCreated()->json('data.id');

        $this->artisan('boarding:expire-stale')->assertSuccessful();
        $this->assertSame('expired', BoardingHomecoming::find($stale)->status);

        // Santri yang sedang `out` karena kepulangan tidak boleh di-cek out izin biasa.
        $other = $this->placedStudent('4107');
        $homecomingId = $this->postJson('/api/v1/boarding/homecomings', [...$this->homecomingPayload($other), 'submit' => true])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/boarding/homecomings/'.$homecomingId.'/approve')->assertOk();
        $this->postJson('/api/v1/boarding/homecomings/'.$homecomingId.'/check-out')->assertOk();

        $permissionId = $this->postJson('/api/v1/boarding/permissions', [
            'student_id' => $other->id,
            'permission_type' => 'out',
            'reason' => 'Belanja',
            'start_at' => now()->addHour()->format('Y-m-d H:i'),
            'expected_return_at' => now()->addHours(3)->format('Y-m-d H:i'),
            'submit' => true,
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/boarding/permissions/'.$permissionId.'/approve')->assertOk();
        $this->postJson('/api/v1/boarding/permissions/'.$permissionId.'/check-out')
            ->assertStatus(422)
            ->assertJsonValidationErrors('student');
    }

    public function test_kepulangan_tercatat_di_audit_dan_bisa_ditolak(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4108');

        $id = $this->postJson('/api/v1/boarding/homecomings', [...$this->homecomingPayload($student), 'submit' => true])
            ->assertCreated()->json('data.id');

        $this->postJson('/api/v1/boarding/homecomings/'.$id.'/reject', ['note' => 'Pemimpin belum tersedia'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'boarding',
            'event' => 'BOARDING_HOMECOMING_CREATED',
            'subject_id' => $id,
        ]);
    }

    public function test_musyrif_boleh_menyetujui_tetapi_tidak_mencatat_kepulangan(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4109');
        $id = $this->postJson('/api/v1/boarding/homecomings', [...$this->homecomingPayload($student), 'submit' => true])
            ->assertCreated()->json('data.id');

        $musyrif = $this->actingAsRole('musyrif');
        // Pengasuh hanya bisa menyentuh santri binaannya — beri penugasan A101.
        $this->makeWaliKamarOf($musyrif, 'A101');

        // role musyrif punya homecoming.approve + view, TANPA manage.
        $this->getJson('/api/v1/boarding/homecomings')->assertOk();
        $this->postJson('/api/v1/boarding/homecomings', $this->homecomingPayload($student))->assertStatus(403);
        $this->postJson('/api/v1/boarding/homecomings/'.$id.'/approve')->assertOk();
    }

    /** Jadikan pengguna pengasuh resmi satu kamar (penugasan musyrif aktif). */
    private function makeWaliKamarOf(User $user, string $roomCode): void
    {
        $employee = \App\Models\Employee::create(['full_name' => 'Pengasuh '.$user->id, 'is_active' => true]);
        $user->forceFill(['userable_type' => \App\Models\Employee::class, 'userable_id' => $employee->id])->save();

        \App\Models\BoardingMusyrif::create([
            'employee_id' => $employee->id,
            'position' => \App\Models\BoardingMusyrif::POSITION_WALI_KAMAR,
            'room_id' => BoardingRoom::query()->where('code', $roomCode)->value('id'),
            'start_date' => now()->subDay()->toDateString(),
            'status' => \App\Models\BoardingMusyrif::STATUS_ACTIVE,
        ]);
    }

    // =================================================================
    // Kunjungan
    // =================================================================

    /** @return array<string, mixed> */
    private function visitorPayload(Student $student, ?string $date = null): array
    {
        return [
            'visitor_name' => 'Bapak Visitor',
            'relationship' => 'Orang tua',
            'student_id' => $student->id,
            'visit_date' => $date ?? now()->toDateString(),
            'purpose' => 'Mengantar makanan',
        ];
    }

    public function test_alur_kunjungan_waiting_visiting_finished(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4110');

        $id = $this->postJson('/api/v1/boarding/visitors', $this->visitorPayload($student))
            ->assertCreated()
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.can_check_in', true)
            ->json('data.id');

        $this->getJson('/api/v1/boarding/visitors/present')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/boarding/visitors/'.$id.'/check-in', [
            'at' => now()->subHour()->format('Y-m-d H:i'),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'visiting')
            ->assertJsonPath('data.is_present', true);

        // Check in ulang ditolak — status sudah berpindah.
        $this->postJson('/api/v1/boarding/visitors/'.$id.'/check-in')->assertStatus(422);

        $this->postJson('/api/v1/boarding/visitors/'.$id.'/check-out', [
            'check_out_at' => now()->format('Y-m-d H:i'),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.duration_minutes', 60);

        $this->getJson('/api/v1/boarding/visitors/present')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_check_out_tamu_belum_masuk_dan_kunjungan_selesai_tidak_bisa_batal(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4111');

        $waiting = $this->postJson('/api/v1/boarding/visitors', $this->visitorPayload($student))
            ->assertCreated()->json('data.id');

        $this->postJson('/api/v1/boarding/visitors/'.$waiting.'/check-out')->assertStatus(422);

        $this->postJson('/api/v1/boarding/visitors/'.$waiting.'/check-in')->assertOk();
        $this->postJson('/api/v1/boarding/visitors/'.$waiting.'/check-out')->assertOk();
        $this->postJson('/api/v1/boarding/visitors/'.$waiting.'/cancel')->assertStatus(422);
    }

    public function test_kunjungan_masa_lalu_dan_santri_non_asrama_ditolak(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4112');

        $this->postJson('/api/v1/boarding/visitors', $this->visitorPayload($student, now()->subDay()->toDateString()))
            ->assertStatus(422)
            ->assertJsonValidationErrors('visit_date');

        $g = Gender::query()->firstOrCreate(['code' => 'L'], ['name' => 'Laki-laki']);
        $luar = Student::create(['nis' => '4113', 'full_name' => 'Santri 4113', 'gender_id' => $g->id, 'is_active' => true]);
        $this->postJson('/api/v1/boarding/visitors', $this->visitorPayload($luar))->assertStatus(422);
    }

    public function test_indeks_kunjungan_terfilter_dan_otorisasi_petugas(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4114');
        $this->postJson('/api/v1/boarding/visitors', $this->visitorPayload($student))->assertCreated();

        $this->getJson('/api/v1/boarding/visitors?student_id='.$student->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/boarding/visitors?search=Bapak+Visitor')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // petugas-kesehatan: hak melihat kunjungan, bukan mencatat.
        $this->actingAsRole('petugas-kesehatan');
        $this->getJson('/api/v1/boarding/visitors')->assertOk();
        $this->postJson('/api/v1/boarding/visitors', $this->visitorPayload($student))->assertStatus(403);
    }
}
