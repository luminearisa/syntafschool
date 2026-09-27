<?php

namespace Tests\Feature;

use App\Models\BoardingPermission;
use App\Models\BoardingRoom;
use App\Models\Gender;
use App\Models\Student;
use App\Models\User;
use App\Services\Boarding\BoardingPlacementService;
use App\Services\Boarding\BoardingSettingsService;
use Database\Seeders\BoardingConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Izin keluar santri (§27–§29): alur draft → diajukan → persetujuan (1/2
 * langkah) → check out gerbang → check in pulang (terlambat + grace),
 * penolakan beralasan, tumpang tindih jendela, dan expire otomatis.
 */
class BoardingPermissionTest extends TestCase
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
    private function permissionPayload(Student $student, ?string $start = null, ?string $expected = null): array
    {
        return [
            'student_id' => $student->id,
            'permission_type' => 'out',
            'destination' => 'Rumah nenek',
            'reason' => 'Acara keluarga',
            'start_at' => $start ?? now()->addHour()->format('Y-m-d H:i'),
            'expected_return_at' => $expected ?? now()->addHours(5)->format('Y-m-d H:i'),
        ];
    }

    /** Draft → submitted → approved (satu langkah). */
    private function approvedPermission(Student $student): BoardingPermission
    {
        $id = $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($student))
            ->assertCreated()
            ->json('data.id');

        $this->postJson('/api/v1/boarding/permissions/'.$id.'/submit')->assertOk();
        $this->postJson('/api/v1/boarding/permissions/'.$id.'/approve')->assertOk();

        return BoardingPermission::findOrFail($id);
    }

    // =================================================================

    public function test_alur_lengkap_izin_keluar_sampai_kembali_tepat_waktu(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4001');

        $created = $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($student))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.current_step', 0)
            ->json('data');

        $id = $created['id'];

        $this->postJson('/api/v1/boarding/permissions/'.$id.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.can_approve', true);

        $this->postJson('/api/v1/boarding/permissions/'.$id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->postJson('/api/v1/boarding/permissions/'.$id.'/check-out')
            ->assertOk()
            ->assertJsonPath('data.status', 'out')
            ->assertJsonPath('data.is_out', true);

        $this->postJson('/api/v1/boarding/permissions/'.$id.'/check-in', [
            'at' => now()->addHours(4)->format('Y-m-d H:i'),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'returned')
            ->assertJsonPath('data.late_return', false);

        $this->assertSame('returned', BoardingPermission::find($id)->status);
    }

    public function test_check_in_setelah_tenggat_grace_dicatat_terlambat(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4002');

        // Tenggat grace default 30 menit (§29).
        $start = now()->subHours(6)->format('Y-m-d H:i');
        $expected = now()->subHour()->format('Y-m-d H:i');

        $id = $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($student, $start, $expected))
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/boarding/permissions/'.$id.'/submit')->assertOk();
        $this->postJson('/api/v1/boarding/permissions/'.$id.'/approve')->assertOk();
        $this->postJson('/api/v1/boarding/permissions/'.$id.'/check-out', ['at' => $start])->assertOk();

        $this->postJson('/api/v1/boarding/permissions/'.$id.'/check-in', [
            'at' => now()->format('Y-m-d H:i'), // 60 menit > grace 30
        ])
            ->assertOk()
            ->assertJsonPath('data.late_return', true);
    }

    public function test_persetujuan_dua_langkah_berjalan_sesuai_konfigurasi(): void
    {
        $this->actingAsRole('admin-boarding');
        app(BoardingSettingsService::class)->update(['permission_approval_steps' => 2]);

        $student = $this->placedStudent('4003');
        $id = $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($student))
            ->assertCreated()
            ->assertJsonPath('data.required_steps', 2)
            ->json('data.id');

        $this->postJson('/api/v1/boarding/permissions/'.$id.'/submit')->assertOk();

        // Langkah 1: status MASIH submitted, hanya current_step yang naik.
        $this->postJson('/api/v1/boarding/permissions/'.$id.'/approve', ['note' => 'Oke dari wali kamar'])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.current_step', 1);

        $this->postJson('/api/v1/boarding/permissions/'.$id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.current_step', 2);

        $detail = $this->getJson('/api/v1/boarding/permissions/'.$id)
            ->assertOk()
            ->assertJsonCount(2, 'data.approvals')
            ->json('data');

        $this->assertSame([1, 2], array_column($detail['approvals'], 'step'));
    }

    public function test_penolakan_wajib_alasan_dan_menutup_pengajuan(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4004');

        $id = $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($student))
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/boarding/permissions/'.$id.'/submit')->assertOk();

        // Alasan kurang dari 5 karakter ditolak.
        $this->postJson('/api/v1/boarding/permissions/'.$id.'/reject', ['note' => 'no'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->postJson('/api/v1/boarding/permissions/'.$id.'/reject', [
            'note' => 'Berkas surat pengantar belum lengkap.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        // Rejected final: tidak bisa disetujui atau diajukan lagi.
        $this->postJson('/api/v1/boarding/permissions/'.$id.'/approve')->assertStatus(422);
    }

    public function test_izin_bertumpuk_pada_jendela_waktu_sama_ditolak(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4005');

        // Hanya permintaan TERBUKA (submitted/approved/out) yang menutup; draft
        // boleh berjejer sampai diajukan.
        $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($student))
            ->assertCreated();
        $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($student))
            ->assertCreated();

        $id = $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($student))
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/boarding/permissions/'.$id.'/submit')->assertOk();

        // Sekarang jendela yang sama bertabrakan dengan izin `submitted`.
        $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($student))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_at');
    }

    public function test_check_out_hanya_untuk_izin_disetujui_dan_santri_berasrama(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4006');

        // Draft belum boleh di-check out.
        $draftId = $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($student))
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/boarding/permissions/'.$draftId.'/check-out')->assertStatus(422);

        // Santri tanpa penempatan asrama ditolak sejak pembuatan (§28).
        $g = Gender::query()->firstOrCreate(['code' => 'L'], ['name' => 'Laki-laki']);
        $luar = Student::create(['nis' => '4007', 'full_name' => 'Santri 4007', 'gender_id' => $g->id, 'is_active' => true]);

        $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($luar))
            ->assertStatus(422);
    }

    public function test_satu_santri_hanya_boleh_satu_keadaan_keluar(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4008');

        $first = $this->approvedPermission($student);
        $this->postJson('/api/v1/boarding/permissions/'.$first->id.'/check-out')->assertOk();

        // Permintaan baru pada jendela yang sama ditolak: izin `out` masih terbuka.
        $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($student))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_at');
    }

    public function test_perintah_expire_menutup_izin_terbuka_yang_lewat_tenggat(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4009');

        $stale = $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload(
            $student,
            now()->subDays(3)->format('Y-m-d H:i'),
            now()->subDay()->format('Y-m-d H:i'),
        ))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/boarding/permissions/'.$stale.'/submit')->assertOk();

        // `out` TIDAK boleh di-expire — kepulangannya ditutup manual.
        $active = $this->placedStudent('4010');
        $approved = $this->approvedPermission($active);
        $this->postJson('/api/v1/boarding/permissions/'.$approved->id.'/check-out')->assertOk();

        $this->artisan('boarding:expire-stale')->assertSuccessful();

        $this->assertSame('expired', BoardingPermission::find($stale)->status);
        $this->assertSame('out', BoardingPermission::find($approved->id)->status);

        // Idempoten: dijalankan lagi tidak mengubah apa pun.
        $this->artisan('boarding:expire-stale')->assertSuccessful();
        $this->assertSame('expired', BoardingPermission::find($stale)->status);
    }

    public function test_semua_perubahan_izin_tercatat_di_audit_log(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4011');

        $id = $this->approvedPermission($student)->id;
        $this->postJson('/api/v1/boarding/permissions/'.$id.'/check-out')->assertOk();
        $this->postJson('/api/v1/boarding/permissions/'.$id.'/check-in')->assertOk();

        foreach (['BOARDING_PERMISSION_CREATED', 'BOARDING_PERMISSION_SUBMITTED', 'BOARDING_PERMISSION_APPROVED', 'BOARDING_PERMISSION_CHECKED_OUT', 'BOARDING_PERMISSION_CHECKED_IN'] as $event) {
            $this->assertDatabaseHas('activity_log', [
                'log_name' => 'boarding',
                'event' => $event,
                'subject_type' => BoardingPermission::class,
                'subject_id' => $id,
            ]);
        }
    }

    public function test_otorisasi_peraksi(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4012');
        $id = $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($student))
            ->assertCreated()->json('data.id');

        // wali-kamar: boleh melihat, tidak boleh menyetujui.
        $this->actingAsRole('wali-kamar');
        $this->getJson('/api/v1/boarding/permissions')->assertOk();
        $this->postJson('/api/v1/boarding/permissions/'.$id.'/approve')->assertStatus(403);

        // petugas-absensi: tidak punya hak modul perizinan sama sekali.
        $this->actingAsRole('petugas-absensi');
        $this->getJson('/api/v1/boarding/permissions')->assertStatus(403);
        $this->postJson('/api/v1/boarding/permissions', $this->permissionPayload($student))->assertStatus(403);
    }

    public function test_indeks_terfilter_dan_detail_terbaca(): void
    {
        $this->actingAsRole('petugas-perizinan');
        $student = $this->placedStudent('4013');

        $id = $this->approvedPermission($student)->id;

        $this->getJson('/api/v1/boarding/permissions?status=approved')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->getJson('/api/v1/boarding/permissions?student_id='.$student->id.'&search=4013')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/boarding/permissions/'.$id)
            ->assertOk()
            ->assertJsonPath('data.student.nis', '4013')
            ->assertJsonPath('data.permission_type_label', 'Keluar biasa');
    }
}
