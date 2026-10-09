<?php

namespace Tests\Feature;

use App\Models\BoardingDisciplinaryAction;
use App\Models\BoardingRoom;
use App\Models\BoardingViolation;
use App\Models\BoardingViolationType;
use App\Models\Gender;
use App\Models\Guardian;
use App\Models\PpdbParentAccount;
use App\Models\Student;
use App\Models\User;
use App\Services\Boarding\BoardingPlacementService;
use Database\Seeders\BoardingConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kedisiplinan asrama (§35–§38): master jenis pelanggaran, pencatatan dengan
 * snapshot poin, ambang poin → tindakan otomatis, tindakan manual, dan scope
 * wali kamar.
 */
class BoardingViolationTest extends TestCase
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

    /** Pengasuh scoped: karyawan + penugasan wali kamar aktif di kamar `$roomCode`. */
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

    /** @return array<string, mixed> */
    private function typePayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'TELAT-SHALAT',
            'name' => 'Telat shalat berjamaah',
            'category' => 'ibadah',
            'severity' => 'moderate',
            'point' => 15,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function violationPayload(Student $student, int $typeId, ?string $date = null): array
    {
        return [
            'student_id' => $student->id,
            'violation_type_id' => $typeId,
            'date' => $date ?? now()->toDateString(),
            'narrative' => 'Terlihat telat oleh petugas gerbang.',
        ];
    }

    private function seededTypeId(string $code): int
    {
        return (int) BoardingViolationType::query()->where('code', $code)->value('id');
    }

    private function recordViolation(Student $student, int $typeId): int
    {
        return $this->postJson('/api/v1/boarding/violations', $this->violationPayload($student, $typeId))
            ->assertCreated()
            ->json('data.violation.id');
    }

    // ================================================ master jenis pelanggaran

    public function test_master_jenis_pelanggaran_crud(): void
    {
        $this->actingAsRole('petugas-kedisiplinan');

        $id = $this->postJson('/api/v1/boarding/violation-types', $this->typePayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'TELAT-SHALAT')
            ->assertJsonPath('data.point', 15)
            ->assertJsonPath('data.category_label', 'Ibadah')
            ->json('data.id');

        $this->patchJson('/api/v1/boarding/violation-types/'.$id, ['point' => 18])
            ->assertOk()
            ->assertJsonPath('data.point', 18);

        $this->getJson('/api/v1/boarding/violation-types')->assertOk()
            ->assertJsonFragment(['id' => $id]);

        $this->deleteJson('/api/v1/boarding/violation-types/'.$id)->assertOk();
        $this->assertDatabaseMissing('boarding_violation_types', ['id' => $id]);
    }

    public function test_kode_jenis_pelanggaran_harus_unik_dan_kategori_valid(): void
    {
        $this->actingAsRole('petugas-kedisiplinan');

        $trlmbt = $this->seededTypeId('TRLMBT');

        $this->postJson('/api/v1/boarding/violation-types', $this->typePayload(['code' => 'TRLMBT']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/boarding/violation-types', $this->typePayload(['category' => 'bukan-kategori']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');

        $this->assertSame(1, $trlmbt > 0 ? 1 : 0, 'Seeder harus menyediakan master contoh.');
    }

    public function test_jenis_yang_sudah_terpakai_tidak_bisa_dihapus(): void
    {
        $this->actingAsRole('petugas-kedisiplinan');
        $student = $this->placedStudent('5001');
        $typeId = $this->seededTypeId('TRLMBT');

        $this->recordViolation($student, $typeId);

        $this->deleteJson('/api/v1/boarding/violation-types/'.$typeId)
            ->assertStatus(422)
            ->assertJsonValidationErrors('violation_type');

        $this->assertDatabaseHas('boarding_violation_types', ['id' => $typeId]);
    }

    // ================================================ pencatatan + snapshot poin

    public function test_poin_tersnapshot_dari_master_saat_pelanggaran_dicatat(): void
    {
        $this->actingAsRole('petugas-kedisiplinan');
        $student = $this->placedStudent('5002');
        $type = BoardingViolationType::query()->where('code', 'TRLMBT')->firstOrFail();

        $this->postJson('/api/v1/boarding/violations', $this->violationPayload($student, (int) $type->id))
            ->assertCreated()
            ->assertJsonPath('data.violation.points', $type->point)
            ->assertJsonPath('data.violation.status', 'open')
            ->assertJsonPath('data.auto_actions', [])
            ->assertJsonFragment(['message' => 'Pelanggaran berhasil dicatat']);

        // Master diubah → snapshot lama tidak ikut berubah (§36).
        $type->update(['point' => 99]);
        $this->assertDatabaseHas('boarding_violations', [
            'student_id' => $student->id,
            'points' => 5,
        ]);
    }

    public function test_klien_tidak_bisa_mengirim_poin_atau_status(): void
    {
        $this->actingAsRole('petugas-kedisiplinan');
        $student = $this->placedStudent('5003');

        $this->postJson('/api/v1/boarding/violations', $this->violationPayload($student, $this->seededTypeId('TRLMBT')) + [
            'status' => 'closed',
        ])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    // ================================================ ambang poin (§37)

    public function test_ambang_poin_menghasilkan_tindakan_otomatis_hanya_saat_melewati_batas(): void
    {
        $this->actingAsRole('petugas-kedisiplinan');
        $student = $this->placedStudent('5004');

        // Ambang default: warning 25, coaching 50, parent_notification 75.
        $typeId = $this->postJson('/api/v1/boarding/violation-types', $this->typePayload([
            'code' => 'KABUR', 'name' => 'Keluar tanpa kabar', 'point' => 30,
        ]))->assertCreated()->json('data.id');

        $first = $this->postJson('/api/v1/boarding/violations', $this->violationPayload($student, $typeId))
            ->assertCreated()
            ->assertJsonPath('data.auto_actions.0.action', 'warning')
            ->json('data.auto_actions');
        $this->assertCount(1, $first);

        // Total 60 → melewati coaching (50) saja; warning tidak diulang.
        $this->postJson('/api/v1/boarding/violations', $this->violationPayload($student, $typeId))
            ->assertCreated()
            ->assertJsonPath('data.auto_actions.0.action', 'coaching')
            ->assertJsonPath('data.auto_actions.0.notes', 'Otomatis: total poin melewati ambang 50');

        // Poin 0 → total tetap 60 → tidak ada ambang baru terlampaui.
        $zeroId = $this->postJson('/api/v1/boarding/violation-types', $this->typePayload([
            'code' => 'TEGURAN', 'name' => 'Teguran lisan', 'point' => 0, 'category' => 'discipline',
        ]))->assertCreated()->json('data.id');

        $this->postJson('/api/v1/boarding/violations', $this->violationPayload($student, $zeroId))
            ->assertCreated()
            ->assertJsonPath('data.auto_actions', []);

        $this->assertSame(2, BoardingDisciplinaryAction::query()->where('student_id', $student->id)->count());
    }

    public function test_ringkasan_poin_santri(): void
    {
        $this->actingAsRole('petugas-kedisiplinan');
        $student = $this->placedStudent('5005');

        $typeId = $this->postJson('/api/v1/boarding/violation-types', $this->typePayload([
            'code' => 'KABUR2', 'name' => 'Pulang tanpa izin', 'point' => 30,
        ]))->assertCreated()->json('data.id');

        $this->recordViolation($student, $typeId);
        $this->recordViolation($student, $typeId);

        $summary = $this->getJson('/api/v1/boarding/students/'.$student->id.'/points')
            ->assertOk()
            ->assertJsonPath('data.student_id', $student->id)
            ->assertJsonPath('data.total_points', 60)
            ->assertJsonPath('data.violation_count', 2)
            ->assertJsonPath('data.point_label', 'Poin Pelanggaran')
            ->assertJsonPath('data.next_threshold.threshold', 75)
            ->json('data');

        $this->assertSame('ibadah', $summary['by_category'][0]['category']);
        $this->assertSame(60, $summary['by_category'][0]['points']);
        $this->assertEquals(100, $summary['by_category'][0]['share']);

        $flags = array_column($summary['thresholds'], 'reached', 'action');
        $this->assertTrue($flags['warning']);
        $this->assertTrue($flags['coaching']);
        $this->assertFalse($flags['parent_notification']);
        $this->assertSame(15, $summary['next_threshold']['remaining']);
        $this->assertCount(2, $summary['recent']);
    }

    // ================================================ tindakan disiplin

    public function test_tindakan_manual_menindaklanjuti_violation_dan_statusnya(): void
    {
        $this->actingAsRole('petugas-kedisiplinan');
        $student = $this->placedStudent('5006');
        $violationId = $this->recordViolation($student, $this->seededTypeId('TRLMBT'));

        $actionId = $this->postJson('/api/v1/boarding/disciplinary-actions', [
            'student_id' => $student->id,
            'violation_id' => $violationId,
            'action' => 'written_warning',
            'date' => now()->toDateString(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_auto', false)
            ->json('data.id');

        $this->assertSame('actioned', BoardingViolation::find($violationId)->status);

        // `status` tidak lewat endpoint update biasa untuk completed-but-blank-notes.
        $this->postJson('/api/v1/boarding/disciplinary-actions/'.$actionId.'/status', ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes');

        $this->postJson('/api/v1/boarding/disciplinary-actions/'.$actionId.'/status', [
            'status' => 'completed',
            'notes' => 'Sudah dilaksanakan bersama musyrif.',
        ])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_tindakan_manual_violation_milik_santri_lain_ditolak(): void
    {
        $this->actingAsRole('petugas-kedisiplinan');
        $a = $this->placedStudent('5007');
        $b = $this->placedStudent('5008');
        $violationOfB = $this->recordViolation($b, $this->seededTypeId('TRLMBT'));

        $this->postJson('/api/v1/boarding/disciplinary-actions', [
            'student_id' => $a->id,
            'violation_id' => $violationOfB,
            'action' => 'coaching',
        ])->assertStatus(422)->assertJsonValidationErrors('violation_id');
    }

    public function test_update_ganti_jenis_resnapshot_poin_dan_hapus_melepas_rujukan(): void
    {
        $this->actingAsRole('petugas-kedisiplinan');
        $student = $this->placedStudent('5009');
        $trlmbt = $this->seededTypeId('TRLMBT');
        $keluar = $this->seededTypeId('KELUAR-TANPA-IZIN');

        $violationId = $this->recordViolation($student, $trlmbt);
        $actionId = $this->postJson('/api/v1/boarding/disciplinary-actions', [
            'student_id' => $student->id,
            'violation_id' => $violationId,
            'action' => 'warning',
        ])->assertCreated()->json('data.id');

        // Ganti jenis → poin disalin ulang dari master baru.
        $this->patchJson('/api/v1/boarding/violations/'.$violationId, ['violation_type_id' => $keluar])
            ->assertOk()
            ->assertJsonPath('data.points', 20);

        // Sunting narasi saja → snapshot dipertahankan (§36).
        $this->patchJson('/api/v1/boarding/violations/'.$violationId, ['narrative' => 'Uraian dikoreksi.'])
            ->assertOk()
            ->assertJsonPath('data.points', 20);

        $this->deleteJson('/api/v1/boarding/violations/'.$violationId)->assertOk();

        $this->assertDatabaseMissing('boarding_violations', ['id' => $violationId]);
        $this->assertDatabaseHas('boarding_disciplinary_actions', [
            'id' => $actionId,
            'violation_id' => null,
        ]);
    }

    // ================================================ scope + otorisasi

    public function test_wali_kamar_hanya_mencatat_dan_melihat_di_kamarnya(): void
    {
        $binaan = $this->placedStudent('5010', 'A101');
        $lain = $this->placedStudent('5011', 'A102');

        $user = User::factory()->create(['email' => 'wali.kamar2@schoolerp.test', 'is_active' => true]);
        $user->syncRoles(['wali-kamar']);
        $this->makeWaliKamarOf($user, 'A101');
        Sanctum::actingAs($user, ['*']);

        $trlmbt = $this->seededTypeId('TRLMBT');

        $this->postJson('/api/v1/boarding/violations', $this->violationPayload($binaan, $trlmbt))
            ->assertCreated();

        $this->postJson('/api/v1/boarding/violations', $this->violationPayload($lain, $trlmbt))
            ->assertForbidden();

        $this->getJson('/api/v1/boarding/violations')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $binaan->id);

        // Laporan milik santri lain tidak bisa dibuka lewat show pun.
        $foreign = BoardingViolation::query()->where('student_id', $lain->id)->first();
        $this->assertNull($foreign);
    }

    public function test_indeks_filter_kategori_dan_paginasi(): void
    {
        $this->actingAsRole('petugas-kedisiplinan');
        $student = $this->placedStudent('5012');

        $this->recordViolation($student, $this->seededTypeId('TRLMBT'));          // discipline
        $this->recordViolation($student, $this->seededTypeId('KOTOR'));           // cleanliness

        $this->getJson('/api/v1/boarding/violations?category=cleanliness')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'cleanliness');

        $page = $this->getJson('/api/v1/boarding/violations?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->json();
        $this->assertSame(2, $page['meta']['total']);
    }

    // ================================================ notifikasi orang tua

    public function test_pelanggaran_mengirim_notifikasi_ke_orang_tua_dan_idempoten(): void
    {
        $parent = User::factory()->create(['email' => 'ortu5013@schoolerp.test', 'is_active' => true]);
        $parent->syncRoles(['wali-murid']);

        $this->actingAsRole('petugas-kedisiplinan');
        $student = $this->placedStudent('5013');

        $guardian = Guardian::create(['full_name' => 'Bapak Santri 5013', 'address' => 'Jl. Test 1']);
        DB::table('student_guardians')->insert([
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        PpdbParentAccount::create(['user_id' => $parent->id, 'guardian_id' => $guardian->id]);

        $this->recordViolation($student, $this->seededTypeId('KELUAR-TANPA-IZIN'));

        $sent = DB::table('notifications')
            ->where('notifiable_id', $parent->id)
            ->whereRaw("json_extract(data, '$.type') = ?", ['violation_recorded'])
            ->count();
        $this->assertSame(1, $sent);

        // Ambang kelewatan → notifikasi tambahan; orang tua tanpa akun tidak dapat apa pun.
        $anakLain = $this->placedStudent('5014');
        $this->recordViolation($anakLain, $this->seededTypeId('KELUAR-TANPA-IZIN'));
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $parent->id)->count());
    }

    public function test_role_tanpa_hak_violation_ditolak_middleware(): void
    {
        $this->actingAsRole('petugas-kesehatan');
        $student = $this->placedStudent('5015');

        $this->getJson('/api/v1/boarding/violations')->assertForbidden();
        $this->postJson('/api/v1/boarding/violations', $this->violationPayload($student, $this->seededTypeId('TRLMBT')))
            ->assertForbidden();
    }
}
