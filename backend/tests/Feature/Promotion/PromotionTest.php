<?php

namespace Tests\Feature\Promotion;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\EducationLevel;
use App\Models\SchoolClass;
use App\Models\Score;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentHistory;
use App\Models\StudentStatus;
use App\Models\Subject;
use App\Models\User;
use App\Models\ViolationType;
use App\Modules\Promotion\Database\Seeders\PromotionPermissionSeeder;
use App\Modules\Promotion\Models\Graduation;
use App\Modules\Promotion\Models\PromotionDecision;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private AcademicYear $nextYear;

    private Semester $semester;

    private AssessmentType $assessmentType;

    private AttendanceStatus $present;

    private AttendanceStatus $absent;

    private Subject $subject;

    private EducationLevel $level7;

    private EducationLevel $level9;

    private SchoolClass $class7a;

    private SchoolClass $class8a;

    private SchoolClass $class9a;

    private Student $passingStudent;

    private Student $failingStudent;

    private Student $finalStudent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PromotionPermissionSeeder::class);

        foreach (['Aktif', 'Lulus', 'Tinggal Kelas'] as $name) {
            StudentStatus::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        $this->level7 = EducationLevel::create(['name' => 'Kelas 7', 'level_number' => 7, 'jenjang' => 'SMP', 'is_active' => true]);
        $this->level9 = EducationLevel::create(['name' => 'Kelas 9', 'level_number' => 9, 'jenjang' => 'SMP', 'is_active' => true]);

        $this->year = AcademicYear::create([
            'name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $this->nextYear = AcademicYear::create([
            'name' => '2027/2028', 'start_date' => '2027-07-01', 'end_date' => '2028-06-30', 'is_active' => false,
        ]);
        $this->semester = Semester::create([
            'academic_year_id' => $this->year->id, 'name' => 'Ganjil',
            'start_date' => '2026-07-01', 'end_date' => '2026-12-20', 'is_active' => true,
        ]);

        $this->assessmentType = AssessmentType::create(['code' => 'UH', 'name' => 'Ulangan Harian', 'category' => 'pengetahuan']);
        $this->subject = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);

        $this->present = AttendanceStatus::create(['code' => 'H', 'name' => 'Hadir']);
        $this->absent = AttendanceStatus::create(['code' => 'A', 'name' => 'Alpa']);

        $this->class7a = SchoolClass::create([
            'academic_year_id' => $this->year->id, 'education_level_id' => $this->level7->id,
            'name' => '7A', 'code' => '7A', 'capacity' => 32,
        ]);
        $this->class9a = SchoolClass::create([
            'academic_year_id' => $this->year->id, 'education_level_id' => $this->level9->id,
            'name' => '9A', 'code' => '9A', 'capacity' => 32,
        ]);
        $this->class8a = SchoolClass::create([
            'academic_year_id' => $this->nextYear->id, 'education_level_id' => $this->level7->id,
            'name' => '8A', 'code' => '8A', 'capacity' => 32,
        ]);

        ClassSubject::create(['school_class_id' => $this->class7a->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->year->id]);
        ClassSubject::create(['school_class_id' => $this->class9a->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->year->id]);

        $this->passingStudent = Student::create(['nis' => '26001', 'full_name' => 'Ahmad Fauzi']);
        $this->failingStudent = Student::create(['nis' => '26002', 'full_name' => 'Budi Santoso']);
        $this->finalStudent = Student::create(['nis' => '26009', 'full_name' => 'Citra Dewi']);

        $this->enroll($this->passingStudent, $this->class7a);
        $this->enroll($this->failingStudent, $this->class7a);
        $this->enroll($this->finalStudent, $this->class9a);

        $this->assess($this->class7a, [
            $this->passingStudent->id => 85,
            $this->failingStudent->id => 60,
        ]);
        $this->assess($this->class9a, [
            $this->finalStudent->id => 80,
        ]);

        $this->attend($this->passingStudent, $this->class7a, 5, 0);
        $this->attend($this->failingStudent, $this->class7a, 4, 1);
        $this->attend($this->finalStudent, $this->class9a, 5, 0);

        // Poin pelanggaran kecil; tidak melampaui batas aturan.
        $violationType = ViolationType::create(['name' => 'Terlambat', 'category' => 'Ringan', 'point' => 10]);
        DB::table('student_violations')->insert([
            'student_id' => $this->failingStudent->id,
            'violation_type_id' => $violationType->id,
            'date' => '2026-09-01',
            'point' => 10,
            'status' => 'recorded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->rule();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function enroll(Student $student, SchoolClass $class): void
    {
        ClassStudent::create([
            'school_class_id' => $class->id,
            'student_id' => $student->id,
            'academic_year_id' => $class->academic_year_id,
            'status' => 'aktif',
        ]);
    }

    /** @param array<int, float|int> $scores */
    private function assess(SchoolClass $class, array $scores): void
    {
        $assessment = Assessment::create([
            'school_class_id' => $class->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->semester->id,
            'assessment_type_id' => $this->assessmentType->id,
            'name' => 'UH '.$class->name,
            'max_score' => 100,
            'weight' => 100,
            'date' => '2026-09-10',
            'status' => Assessment::STATUS_FINALIZED,
        ]);

        foreach ($scores as $studentId => $score) {
            Score::create([
                'assessment_id' => $assessment->id,
                'student_id' => $studentId,
                'score' => $score,
            ]);
        }
    }

    private function attend(Student $student, SchoolClass $class, int $present, int $absent): void
    {
        $date = '2026-08-01';
        for ($i = 0; $i < $present; $i++) {
            StudentAttendance::create([
                'student_id' => $student->id,
                'school_class_id' => $class->id,
                'semester_id' => $this->semester->id,
                'date' => $date,
                'attendance_status_id' => $this->present->id,
                'status' => StudentAttendance::STATUS_VERIFIED,
            ]);
            $date = date('Y-m-d', strtotime($date.' +1 day'));
        }
        for ($i = 0; $i < $absent; $i++) {
            StudentAttendance::create([
                'student_id' => $student->id,
                'school_class_id' => $class->id,
                'semester_id' => $this->semester->id,
                'date' => $date,
                'attendance_status_id' => $this->absent->id,
                'status' => StudentAttendance::STATUS_VERIFIED,
            ]);
            $date = date('Y-m-d', strtotime($date.' +1 day'));
        }
    }

    private function rule(array $overrides = []): void
    {
        DB::table('promotion_rules')->insert(array_merge([
            'name' => 'Aturan SMP 2026/2027',
            'education_level_id' => null,
            'academic_year_id' => $this->year->id,
            'min_average_score' => 70,
            'min_attendance_percent' => 80,
            'max_unpassed_subjects' => 3,
            'min_passing_grade' => 75,
            'violation_point_limit' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles(['admin-sekolah']);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function noRole(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function bulkDecisions(): array
    {
        return $this->postJson('/api/v1/promotion-decisions/bulk', [
            'class_id' => $this->class7a->id,
            'decisions' => [
                ['student_id' => $this->passingStudent->id, 'decision' => 'naik', 'target_class_id' => $this->class8a->id],
                ['student_id' => $this->failingStudent->id, 'decision' => 'tinggal'],
            ],
        ]);
    }

    private function decisionFor(Student $student): PromotionDecision
    {
        return PromotionDecision::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $this->year->id)
            ->firstOrFail();
    }

    // -----------------------------------------------------------------
    // RBAC
    // -----------------------------------------------------------------

    public function test_admin_dapat_membuat_aturan_sedangkan_guru_hanya_membaca(): void
    {
        $this->admin();
        $this->getJson('/api/v1/promotion-rules')->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/v1/promotion-rules', [
            'name' => 'Aturan Khusus 9',
            'academic_year_id' => $this->year->id,
            'education_level_id' => $this->level9->id,
            'min_average_score' => 75,
        ])->assertCreated()->assertJsonPath('data.education_level_id', $this->level9->id);

        $this->noRole();
        $this->getJson('/api/v1/promotion-rules')->assertForbidden();

        $this->actor('guru');
        $this->getJson('/api/v1/promotion-rules')->assertOk();
        $this->postJson('/api/v1/promotion-rules', [
            'name' => 'Nekat',
            'academic_year_id' => $this->year->id,
        ])->assertForbidden();
    }

    public function test_guru_tidak_boleh_mengevaluasi_keputusan(): void
    {
        $this->actor('guru');

        $this->postJson('/api/v1/promotion-decisions/evaluate', [
            'class_id' => $this->class7a->id,
        ])->assertForbidden();
    }

    public function test_wali_kelas_tidak_boleh_menyetujui(): void
    {
        $this->admin();
        $this->bulkDecisions()->assertCreated();
        $decision = $this->decisionFor($this->passingStudent);
        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/submit')->assertOk();

        $this->actor('wali-kelas');
        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/approve', [
            'approver_role' => 'wali_kelas',
        ])->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Evaluasi
    // -----------------------------------------------------------------

    public function test_evaluate_hanya_pratinjau_dan_tidak_menyimpan(): void
    {
        $this->admin();

        $response = $this->postJson('/api/v1/promotion-decisions/evaluate', [
            'class_id' => $this->class7a->id,
        ])->assertOk()->json('data');

        $this->assertSame(2, $response['student_count']);
        $this->assertSame(1, $response['naik_count']);
        $this->assertSame(1, $response['tinggal_count']);
        $this->assertSame('naik', $response['students'][0]['suggested_decision']);
        $this->assertSame('tinggal', $response['students'][1]['suggested_decision']);
        $this->assertEquals(85.0, $response['students'][0]['snapshot']['average_score']);
        $this->assertSame(100.0, $response['students'][0]['snapshot']['attendance_percent']);

        $this->assertDatabaseCount('promotion_decisions', 0);
    }

    public function test_evaluate_menolak_kelas_tanpa_aturan(): void
    {
        $this->admin();

        $level10 = EducationLevel::create(['name' => 'Kelas 10', 'level_number' => 10, 'jenjang' => 'SMA', 'is_active' => true]);
        $class = SchoolClass::create([
            'academic_year_id' => $this->year->id, 'education_level_id' => $level10->id,
            'name' => '10A', 'code' => '10A', 'capacity' => 10,
        ]);
        $student = Student::create(['nis' => '26100', 'full_name' => 'Dedi']);
        $this->enroll($student, $class);

        // Aturan yang ada bukan untuk jenjang ini dan bukan umum -> dibuat khusus
        // untuk tahun ajaran berikutnya, sehingga tahun ini tetap tanpa aturan.
        DB::table('promotion_rules')->where('academic_year_id', $this->year->id)->update(['academic_year_id' => $this->nextYear->id]);

        $this->postJson('/api/v1/promotion-decisions/evaluate', [
            'class_id' => $class->id,
        ])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Bulk & snapshot
    // -----------------------------------------------------------------

    public function test_bulk_menyimpan_keputusan_dengan_snapshot(): void
    {
        $this->admin();

        $this->bulkDecisions()->assertCreated()->assertJsonPath('data.0.status', 'draft');

        $this->assertDatabaseCount('promotion_decisions', 2);

        $decision = $this->decisionFor($this->passingStudent);
        $this->assertSame('naik', $decision->decision);
        $this->assertSame($this->class8a->id, $decision->target_class_id);
        $this->assertEquals(85.0, $decision->snapshot['average_score']);
        $this->assertTrue($decision->snapshot['passed_all']);
        $this->assertSame($this->year->id, $decision->academic_year_id);

        $tinggal = $this->decisionFor($this->failingStudent);
        $this->assertFalse($tinggal->snapshot['passed_all']);
        $this->assertEquals(60.0, $tinggal->snapshot['average_score']);
        $this->assertSame(1, $tinggal->snapshot['unpassed_subjects']);
    }

    public function test_snapshot_tidak_dihitung_ulang_saat_nilai_berubah(): void
    {
        $this->admin();
        $this->bulkDecisions()->assertCreated();

        $before = $this->decisionFor($this->passingStudent)->snapshot['average_score'];

        // Nilai diubah setelah keputusan diambil.
        Score::query()->where('student_id', $this->passingStudent->id)->update(['score' => 10]);

        $this->assertEquals(85.0, $before);
        $this->assertEquals(85.0, $this->decisionFor($this->passingStudent)->snapshot['average_score']);
    }

    public function test_keputusan_ganda_ditolak(): void
    {
        $this->admin();
        $this->bulkDecisions()->assertCreated();

        $this->postJson('/api/v1/promotion-decisions/bulk', [
            'class_id' => $this->class7a->id,
            'decisions' => [
                ['student_id' => $this->passingStudent->id, 'decision' => 'naik', 'target_class_id' => $this->class8a->id],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('promotion_decisions', 2);
    }

    public function test_siswa_di_luar_kelas_ditolak(): void
    {
        $this->admin();

        $outsider = Student::create(['nis' => '26999', 'full_name' => 'Eka']);

        $this->postJson('/api/v1/promotion-decisions/bulk', [
            'class_id' => $this->class7a->id,
            'decisions' => [
                ['student_id' => $outsider->id, 'decision' => 'naik', 'target_class_id' => $this->class8a->id],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('promotion_decisions', 0);
    }

    // -----------------------------------------------------------------
    // Alur persetujuan
    // -----------------------------------------------------------------

    public function test_alur_persetujuan_dari_draft_hingga_disetujui(): void
    {
        $this->admin();
        $this->bulkDecisions()->assertCreated();
        $decision = $this->decisionFor($this->passingStudent);

        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/submit')
            ->assertOk()->assertJsonPath('data.status', 'reviewed');

        // Wali kelas menyetujui -> masih menunggu kepala sekolah.
        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/approve', [
            'approver_role' => 'wali_kelas',
        ])->assertOk()->assertJsonPath('data.status', 'reviewed');

        $this->assertDatabaseCount('promotion_approvals', 2);

        // Kepala sekolah menyetujui -> keputusan disetujui penuh.
        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/approve', [
            'approver_role' => 'kepala_sekolah',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame(0, \App\Modules\Promotion\Models\PromotionApproval::query()
            ->where('promotion_decision_id', $decision->id)
            ->where('status', '!=', 'approved')
            ->count());
    }

    public function test_approve_menolak_keputusan_yang_belum_diajukan(): void
    {
        $this->admin();
        $this->bulkDecisions()->assertCreated();
        $decision = $this->decisionFor($this->passingStudent);

        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/approve', [
            'approver_role' => 'kepala_sekolah',
        ])->assertStatus(422);
    }

    public function test_finalize_hanya_setelah_disetujui(): void
    {
        $this->admin();
        $this->bulkDecisions()->assertCreated();
        $decision = $this->decisionFor($this->passingStudent);

        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/finalize')->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Finalisasi & pembalikan
    // -----------------------------------------------------------------

    private function approveAndFinalize(PromotionDecision $decision): void
    {
        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/submit')->assertOk();
        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/approve', ['approver_role' => 'wali_kelas'])->assertOk();
        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/approve', ['approver_role' => 'kepala_sekolah'])->assertOk();
    }

    public function test_finalize_naik_memindahkan_siswa_dan_membuat_riwayat(): void
    {
        $this->admin();
        $this->bulkDecisions()->assertCreated();
        $decision = $this->decisionFor($this->passingStudent);
        $this->approveAndFinalize($decision);

        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/finalize')
            ->assertOk()->assertJsonPath('data.status', 'finalized');

        $this->assertDatabaseHas('student_histories', [
            'student_id' => $this->passingStudent->id,
            'academic_year_id' => $this->nextYear->id,
            'school_class_id' => $this->class8a->id,
            'status' => 'naik',
        ]);

        $this->assertDatabaseHas('class_students', [
            'student_id' => $this->passingStudent->id,
            'academic_year_id' => $this->nextYear->id,
            'school_class_id' => $this->class8a->id,
            'status' => 'aktif',
        ]);
    }

    public function test_finalize_tinggal_mengulang_kelas_yang_sama(): void
    {
        $this->admin();
        $this->bulkDecisions()->assertCreated();
        $decision = $this->decisionFor($this->failingStudent);
        $this->approveAndFinalize($decision);

        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/finalize')->assertOk();

        $this->assertDatabaseHas('student_histories', [
            'student_id' => $this->failingStudent->id,
            'academic_year_id' => $this->nextYear->id,
            'school_class_id' => $this->class7a->id,
            'status' => 'tinggal',
        ]);
    }

    public function test_finalize_idempoten(): void
    {
        $this->admin();
        $this->bulkDecisions()->assertCreated();
        $decision = $this->decisionFor($this->passingStudent);
        $this->approveAndFinalize($decision);

        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/finalize')->assertOk();
        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/finalize')->assertOk();

        $this->assertSame(1, StudentHistory::query()
            ->where('student_id', $this->passingStudent->id)
            ->where('academic_year_id', $this->nextYear->id)
            ->count());
    }

    public function test_rollback_membalikkan_finalisasi(): void
    {
        $this->admin();
        $this->bulkDecisions()->assertCreated();
        $decision = $this->decisionFor($this->passingStudent);
        $this->approveAndFinalize($decision);
        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/finalize')->assertOk();

        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/reopen', [
            'reason' => 'Salah memasukkan nilai',
        ])->assertOk()->assertJsonPath('data.status', 'reviewed');

        $this->assertDatabaseMissing('student_histories', [
            'student_id' => $this->passingStudent->id,
            'academic_year_id' => $this->nextYear->id,
        ]);
        $this->assertDatabaseMissing('class_students', [
            'student_id' => $this->passingStudent->id,
            'academic_year_id' => $this->nextYear->id,
        ]);
    }

    public function test_finalize_tanpa_tahun_ajaran_berikutnya_ditolak(): void
    {
        $this->admin();
        // Pindahkan kelas tujuan keluar dari tahun ajaran berikutnya lebih dulu,
        // karena FK `school_classes` melarang penghapusan tahun ajaran yang dipakai.
        $this->class8a->update(['academic_year_id' => $this->year->id]);
        $this->nextYear->delete();

        $this->bulkDecisions()->assertCreated();
        $decision = $this->decisionFor($this->passingStudent);
        $this->approveAndFinalize($decision);

        $this->postJson('/api/v1/promotion-decisions/'.$decision->id.'/finalize')->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Kelulusan
    // -----------------------------------------------------------------

    public function test_graduation_evaluate_hanya_pratinjau(): void
    {
        $this->admin();

        $result = $this->postJson('/api/v1/graduations/evaluate', [
            'academic_year_id' => $this->year->id,
        ])->assertOk()->json('data');

        // Tingkat akhir = Kelas 9 (level_number tertinggi pada tahun ajaran ini).
        $this->assertSame(1, $result['student_count']);
        $this->assertSame(1, $result['lulus_count']);
        $this->assertSame('lulus', $result['students'][0]['suggested_decision']);
        $this->assertDatabaseCount('graduations', 0);
    }

    public function test_graduation_bulk_menyimpan_dan_menolak_ganda(): void
    {
        $this->admin();

        $payload = [
            'academic_year_id' => $this->year->id,
            'graduations' => [
                ['student_id' => $this->finalStudent->id, 'decision' => 'lulus'],
            ],
        ];

        $this->postJson('/api/v1/graduations/bulk', $payload)->assertCreated()->assertJsonPath('data.0.decision', 'lulus');

        $this->assertDatabaseCount('graduations', 1);
        $this->assertEquals(80.0, Graduation::first()->average_score);

        $this->postJson('/api/v1/graduations/bulk', $payload)->assertStatus(422);
        $this->assertDatabaseCount('graduations', 1);
    }

    public function test_graduation_approve_menghasilkan_nomor_sekali_dan_menandai_status(): void
    {
        $this->admin();
        $this->postJson('/api/v1/graduations/bulk', [
            'academic_year_id' => $this->year->id,
            'graduations' => [
                ['student_id' => $this->finalStudent->id, 'decision' => 'lulus'],
            ],
        ])->assertCreated();

        $graduation = Graduation::firstOrFail();

        $first = $this->postJson('/api/v1/graduations/'.$graduation->id.'/approve')
            ->assertOk()->json('data.graduation_number');

        $this->assertNotNull($first);

        // Menyetujui ulang tidak menerbitkan nomor baru.
        $second = $this->postJson('/api/v1/graduations/'.$graduation->id.'/approve')
            ->assertOk()->json('data.graduation_number');

        $this->assertSame($first, $second);
        $this->assertSame(1, Graduation::query()->whereNotNull('graduation_number')->count());

        $this->assertDatabaseHas('students', [
            'id' => $this->finalStudent->id,
            'is_active' => false,
            'status_id' => StudentStatus::query()->where('name', 'Lulus')->value('id'),
        ]);
    }

    public function test_graduation_tidak_berlaku_di_luar_tingkat_akhir(): void
    {
        $this->admin();

        // Kelas 7A bukan tingkat akhir; evaluasi kelas itu tidak menghasilkan siswa.
        $result = $this->postJson('/api/v1/graduations/evaluate', [
            'academic_year_id' => $this->year->id,
            'class_id' => $this->class7a->id,
        ])->assertOk()->json('data');

        // Meski kelas dipaksa, hanya siswa kelas itu yang muncul (bukan siswa 9A).
        $this->assertSame(2, $result['student_count']);
        $this->assertSame($this->class7a->id, $result['students'][0]['class']['id']);
    }
}
