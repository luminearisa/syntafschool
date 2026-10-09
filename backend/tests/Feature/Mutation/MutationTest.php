<?php

namespace Tests\Feature\Mutation;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Modules\Mutation\Database\Seeders\MutationPermissionSeeder;
use App\Modules\Mutation\Models\StudentTransfer;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Modul Mutation — Mutasi Siswa.
 *
 * Menguji RBAC, validasi per jenis mutasi, alur persetujuan, dan efek
 * penyelesaian (termasuk idempotensi serta penomoran dokumen).
 */
class MutationTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private AcademicYear $nextYear;

    private SchoolClass $classA;

    private SchoolClass $classB;

    private SchoolClass $classNext;

    private Student $student;

    private Student $otherStudent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ReferenceSeeder::class);
        $this->seed(MutationPermissionSeeder::class);

        $this->year = AcademicYear::create([
            'name' => '2025/2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'is_active' => true,
        ]);

        $this->nextYear = AcademicYear::create([
            'name' => '2026/2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => false,
        ]);

        $this->classA = SchoolClass::create([
            'academic_year_id' => $this->year->id,
            'name' => 'X IPA 1',
            'code' => 'X-IPA-1',
            'capacity' => 32,
        ]);

        $this->classB = SchoolClass::create([
            'academic_year_id' => $this->year->id,
            'name' => 'X IPA 2',
            'code' => 'X-IPA-2',
            'capacity' => 32,
        ]);

        $this->classNext = SchoolClass::create([
            'academic_year_id' => $this->nextYear->id,
            'name' => 'XI IPA 1',
            'code' => 'XI-IPA-1',
            'capacity' => 32,
        ]);

        $this->student = Student::create([
            'nis' => '26001',
            'full_name' => 'Aisyah Putri',
            'is_active' => true,
        ]);

        $this->otherStudent = Student::create([
            'nis' => '26002',
            'full_name' => 'Budi Santoso',
            'is_active' => true,
        ]);

        // Siswa utama sudah punya rombel di kelas asal pada tahun ajaran aktif.
        ClassStudent::create([
            'student_id' => $this->student->id,
            'school_class_id' => $this->classA->id,
            'academic_year_id' => $this->year->id,
            'status' => 'aktif',
        ]);
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    private function actingAsRole(string $role = 'admin-sekolah'): User
    {
        $user = User::factory()->create([
            'email' => "{$role}@mutation.test",
            'is_active' => true,
        ]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'student_id' => $this->otherStudent->id,
            'type' => StudentTransfer::TYPE_MASUK,
            'academic_year_id' => $this->year->id,
            'to_class_id' => $this->classB->id,
            'request_date' => now()->toDateString(),
            'reason' => 'Pindah tugas orang tua',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function createTransfer(array $overrides = []): array
    {
        return $this->postJson('/api/v1/student-transfers', $this->payload($overrides))
            ->assertCreated()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function createSubmitted(array $overrides = []): array
    {
        $transfer = $this->createTransfer($overrides);
        $this->postJson("/api/v1/student-transfers/{$transfer['id']}/submit")->assertOk();

        return $transfer;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function createApproved(array $overrides = []): array
    {
        $transfer = $this->createSubmitted($overrides);
        $this->postJson("/api/v1/student-transfers/{$transfer['id']}/approve")->assertOk();

        return $transfer;
    }

    private function historyCount(int $transferId): int
    {
        return DB::table('student_transfer_histories')
            ->where('student_transfer_id', $transferId)
            ->count();
    }

    // -----------------------------------------------------------------
    // RBAC
    // -----------------------------------------------------------------

    public function test_guest_cannot_access_mutation_list(): void
    {
        $this->getJson('/api/v1/student-transfers')->assertUnauthorized();
    }

    public function test_staf_can_create_transfer_but_cannot_approve(): void
    {
        $this->actingAsRole('staf');

        $transfer = $this->createTransfer();
        $this->assertSame(StudentTransfer::STATUS_DRAFT, $transfer['status']);

        // staf tidak punya mutation.approve.
        $this->postJson("/api/v1/student-transfers/{$transfer['id']}/approve")->assertForbidden();
    }

    public function test_kepala_sekolah_can_approve_but_cannot_complete(): void
    {
        $this->actingAsRole('admin-sekolah');
        $transfer = $this->createSubmitted();

        $this->actingAsRole('kepala-sekolah');
        $this->postJson("/api/v1/student-transfers/{$transfer['id']}/approve")->assertOk();

        // kepala-sekolah tidak punya mutation.complete.
        $this->postJson("/api/v1/student-transfers/{$transfer['id']}/complete")->assertForbidden();
    }

    public function test_wali_kelas_can_view_and_create(): void
    {
        $this->actingAsRole('wali-kelas');

        $this->getJson('/api/v1/student-transfers')->assertOk();
        $this->createTransfer();
    }

    public function test_lookups_endpoint_returns_support_data(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->getJson('/api/v1/student-transfers/lookups')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['students', 'classes', 'academic_years'],
            ]);
    }

    // -----------------------------------------------------------------
    // Validasi per jenis
    // -----------------------------------------------------------------

    public function test_masuk_requires_class_or_school(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/student-transfers', $this->payload([
            'to_class_id' => null,
        ]))->assertStatus(422);
    }

    public function test_masuk_accepts_school_name_only(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->createTransfer([
            'to_class_id' => null,
            'from_school_name' => 'SMP Negeri 1',
        ]);
    }

    public function test_keluar_requires_from_class(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/student-transfers', $this->payload([
            'type' => StudentTransfer::TYPE_KELUAR,
            'from_class_id' => null,
        ]))->assertStatus(422);
    }

    public function test_pindah_kelas_requires_both_classes(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/student-transfers', $this->payload([
            'type' => StudentTransfer::TYPE_PINDAH_KELAS,
            'to_class_id' => null,
        ]))->assertStatus(422);

        $this->postJson('/api/v1/student-transfers', $this->payload([
            'type' => StudentTransfer::TYPE_PINDAH_KELAS,
            'from_class_id' => $this->classA->id,
        ]))->assertCreated();
    }

    public function test_pindah_kelas_rejects_same_class(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/student-transfers', $this->payload([
            'type' => StudentTransfer::TYPE_PINDAH_KELAS,
            'from_class_id' => $this->classA->id,
            'to_class_id' => $this->classA->id,
        ]))->assertStatus(422);
    }

    public function test_pindah_sekolah_requires_school_names(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/student-transfers', $this->payload([
            'type' => StudentTransfer::TYPE_PINDAH_SEKOLAH,
        ]))->assertStatus(422);

        $this->postJson('/api/v1/student-transfers', $this->payload([
            'type' => StudentTransfer::TYPE_PINDAH_SEKOLAH,
            'from_class_id' => $this->classA->id,
            'from_school_name' => 'SMP Negeri 1',
            'to_school_name' => 'SMP Negeri 2',
        ]))->assertCreated();
    }

    public function test_class_must_belong_to_selected_academic_year(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/student-transfers', $this->payload([
            'to_class_id' => $this->classNext->id,
        ]))->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Alur persetujuan
    // -----------------------------------------------------------------

    public function test_full_lifecycle_draft_submitted_approved_completed(): void
    {
        $this->actingAsRole('admin-sekolah');
        $transfer = $this->createTransfer();
        $id = $transfer['id'];

        $this->assertSame(StudentTransfer::STATUS_DRAFT, $transfer['status']);

        $submitted = $this->postJson("/api/v1/student-transfers/{$id}/submit")->assertOk()->json('data');
        $this->assertSame(StudentTransfer::STATUS_SUBMITTED, $submitted['status']);

        $approved = $this->postJson("/api/v1/student-transfers/{$id}/approve")->assertOk()->json('data');
        $this->assertSame(StudentTransfer::STATUS_APPROVED, $approved['status']);
        $this->assertNotNull($approved['approved_at']);
        $this->assertNotNull($approved['approved_by']);

        $completed = $this->postJson("/api/v1/student-transfers/{$id}/complete")->assertOk()->json('data');
        $this->assertSame(StudentTransfer::STATUS_COMPLETED, $completed['status']);
        $this->assertNotNull($completed['document_number']);

        // 3 perpindahan status tercatat (submitted, approved, completed).
        $this->assertSame(3, $this->historyCount($id));
    }

    public function test_cannot_approve_unsubmitted(): void
    {
        $this->actingAsRole('admin-sekolah');
        $transfer = $this->createTransfer();

        $this->postJson("/api/v1/student-transfers/{$transfer['id']}/approve")->assertStatus(422);
    }

    public function test_cannot_complete_before_approved(): void
    {
        $this->actingAsRole('admin-sekolah');
        $transfer = $this->createSubmitted();

        $this->postJson("/api/v1/student-transfers/{$transfer['id']}/complete")->assertStatus(422);
    }

    public function test_reject_records_reason(): void
    {
        $this->actingAsRole('admin-sekolah');
        $transfer = $this->createSubmitted();

        $rejected = $this->postJson("/api/v1/student-transfers/{$transfer['id']}/reject", [
            'note' => 'Dokumen tidak lengkap',
        ])->assertOk()->json('data');

        $this->assertSame(StudentTransfer::STATUS_REJECTED, $rejected['status']);

        $this->assertDatabaseHas('student_transfer_histories', [
            'student_transfer_id' => $transfer['id'],
            'to_status' => StudentTransfer::STATUS_REJECTED,
            'note' => 'Dokumen tidak lengkap',
        ]);
    }

    public function test_reject_requires_note(): void
    {
        $this->actingAsRole('admin-sekolah');
        $transfer = $this->createSubmitted();

        $this->postJson("/api/v1/student-transfers/{$transfer['id']}/reject", [])->assertStatus(422);
    }

    public function test_cannot_update_after_submitted(): void
    {
        $this->actingAsRole('admin-sekolah');
        $transfer = $this->createSubmitted();

        $this->patchJson("/api/v1/student-transfers/{$transfer['id']}", [
            'reason' => 'Alasan baru',
        ])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Efek penyelesaian
    // -----------------------------------------------------------------

    public function test_complete_masuk_adds_membership_and_history(): void
    {
        $this->actingAsRole('admin-sekolah');
        $transfer = $this->createApproved([
            'student_id' => $this->otherStudent->id,
            'to_class_id' => $this->classB->id,
        ]);

        $this->postJson("/api/v1/student-transfers/{$transfer['id']}/complete")->assertOk();

        $this->assertDatabaseHas('class_students', [
            'student_id' => $this->otherStudent->id,
            'academic_year_id' => $this->year->id,
            'school_class_id' => $this->classB->id,
            'status' => 'aktif',
        ]);

        $this->assertDatabaseHas('student_histories', [
            'student_id' => $this->otherStudent->id,
            'academic_year_id' => $this->year->id,
            'status' => 'baru',
        ]);

        $this->assertTrue($this->otherStudent->fresh()->is_active);
    }

    public function test_complete_keluar_deactivates_student(): void
    {
        $this->actingAsRole('admin-sekolah');
        $transfer = $this->createApproved([
            'student_id' => $this->student->id,
            'type' => StudentTransfer::TYPE_KELUAR,
            'from_class_id' => $this->classA->id,
        ]);

        $this->postJson("/api/v1/student-transfers/{$transfer['id']}/complete")->assertOk();

        $this->assertFalse($this->student->fresh()->is_active);

        $this->assertDatabaseHas('class_students', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'school_class_id' => $this->classA->id,
            'status' => 'keluar',
        ]);

        $this->assertDatabaseHas('student_histories', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'status' => 'keluar',
        ]);
    }

    public function test_complete_pindah_kelas_moves_membership(): void
    {
        $this->actingAsRole('admin-sekolah');
        $transfer = $this->createApproved([
            'student_id' => $this->student->id,
            'type' => StudentTransfer::TYPE_PINDAH_KELAS,
            'from_class_id' => $this->classA->id,
            'to_class_id' => $this->classB->id,
        ]);

        $this->postJson("/api/v1/student-transfers/{$transfer['id']}/complete")->assertOk();

        // Keanggotaan berpindah dari kelas asal ke kelas tujuan.
        $this->assertDatabaseMissing('class_students', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'school_class_id' => $this->classA->id,
        ]);

        $this->assertDatabaseHas('class_students', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'school_class_id' => $this->classB->id,
            'status' => 'aktif',
        ]);

        $this->assertDatabaseHas('student_histories', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'school_class_id' => $this->classB->id,
            'status' => 'pindah',
        ]);

        $this->assertTrue($this->student->fresh()->is_active);
    }

    public function test_complete_pindah_sekolah_deactivates_student(): void
    {
        $this->actingAsRole('admin-sekolah');
        $transfer = $this->createApproved([
            'student_id' => $this->student->id,
            'type' => StudentTransfer::TYPE_PINDAH_SEKOLAH,
            'from_class_id' => $this->classA->id,
            'from_school_name' => 'SMP Negeri 1',
            'to_school_name' => 'SMP Negeri 2',
        ]);

        $this->postJson("/api/v1/student-transfers/{$transfer['id']}/complete")->assertOk();

        $this->assertFalse($this->student->fresh()->is_active);

        $this->assertDatabaseHas('student_histories', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'status' => 'pindah',
        ]);
    }

    public function test_completion_is_idempotent(): void
    {
        $this->actingAsRole('admin-sekolah');
        $transfer = $this->createApproved([
            'student_id' => $this->student->id,
            'type' => StudentTransfer::TYPE_PINDAH_KELAS,
            'from_class_id' => $this->classA->id,
            'to_class_id' => $this->classB->id,
        ]);

        $first = $this->postJson("/api/v1/student-transfers/{$transfer['id']}/complete")->assertOk()->json('data');
        $second = $this->postJson("/api/v1/student-transfers/{$transfer['id']}/complete")->assertOk()->json('data');

        // Nomor dokumen tidak dihitung ulang.
        $this->assertSame($first['document_number'], $second['document_number']);
        $this->assertSame(StudentTransfer::STATUS_COMPLETED, $second['status']);

        // Riwayat status tidak bertambah (3 saja) dan keanggotaan tetap satu.
        $this->assertSame(3, $this->historyCount($transfer['id']));
        $this->assertSame(1, DB::table('class_students')
            ->where('student_id', $this->student->id)
            ->where('academic_year_id', $this->year->id)
            ->count());
    }

    public function test_document_number_is_generated_and_unique(): void
    {
        $this->actingAsRole('admin-sekolah');

        $first = $this->createApproved(['student_id' => $this->otherStudent->id, 'to_class_id' => $this->classB->id]);
        $firstNumber = $this->postJson("/api/v1/student-transfers/{$first['id']}/complete")
            ->assertOk()->json('data.document_number');

        $second = $this->createApproved([
            'student_id' => $this->student->id,
            'type' => StudentTransfer::TYPE_PINDAH_KELAS,
            'from_class_id' => $this->classA->id,
            'to_class_id' => $this->classB->id,
        ]);
        $secondNumber = $this->postJson("/api/v1/student-transfers/{$second['id']}/complete")
            ->assertOk()->json('data.document_number');

        $this->assertStringStartsWith('MUT/', $firstNumber);
        $this->assertNotSame($firstNumber, $secondNumber);
    }

    // -----------------------------------------------------------------
    // Daftar & hapus
    // -----------------------------------------------------------------

    public function test_index_filters_and_search(): void
    {
        $this->actingAsRole('admin-sekolah');
        $this->createTransfer(['reason' => 'Alasan Budi']);
        $submitted = $this->createSubmitted([
            'student_id' => $this->student->id,
            'type' => StudentTransfer::TYPE_KELUAR,
            'from_class_id' => $this->classA->id,
        ]);

        $this->getJson('/api/v1/student-transfers?type=keluar')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/student-transfers?status=submitted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $submitted['id']);

        $this->getJson('/api/v1/student-transfers?search=Aisyah')
            ->assertOk()
            ->assertJsonPath('data.0.student.full_name', 'Aisyah Putri');
    }

    public function test_destroy_removes_draft_but_blocks_completed(): void
    {
        $this->actingAsRole('admin-sekolah');

        $draft = $this->createTransfer();
        $this->deleteJson("/api/v1/student-transfers/{$draft['id']}")->assertOk();
        $this->assertSoftDeleted('student_transfers', ['id' => $draft['id']]);

        $completed = $this->createApproved([
            'student_id' => $this->otherStudent->id,
            'to_class_id' => $this->classB->id,
        ]);
        $this->postJson("/api/v1/student-transfers/{$completed['id']}/complete")->assertOk();

        $this->deleteJson("/api/v1/student-transfers/{$completed['id']}")->assertStatus(422);
    }

    public function test_show_returns_history(): void
    {
        $this->actingAsRole('admin-sekolah');
        $transfer = $this->createApproved();

        $this->getJson("/api/v1/student-transfers/{$transfer['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', StudentTransfer::STATUS_APPROVED)
            ->assertJsonCount(2, 'data.histories');
    }
}
