<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\Subject;
use App\Models\User;
use App\Modules\Workflow\Database\Seeders\WorkflowPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 1 mobile (karyawan): batas kewenangan guru, pagar kepemilikan data
 * siswa, perbaikan FK `recorded_by`, dan hak inbox persetujuan pegawai.
 */
class MobilePhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        foreach (['H' => 'Hadir', 'A' => 'Alpa', 'I' => 'Izin', 'S' => 'Sakit', 'T' => 'Terlambat'] as $code => $name) {
            AttendanceStatus::firstOrCreate(['code' => $code], ['name' => $name, 'color' => '#000000']);
        }
    }

    private function semester(): Semester
    {
        $year = AcademicYear::firstOrCreate(['name' => '2025/2026'], ['start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => true]);

        return Semester::firstOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'Ganjil'],
            ['start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'is_active' => true],
        );
    }

    private function klass(Semester $sem, string $code): SchoolClass
    {
        return SchoolClass::create(['academic_year_id' => $sem->academic_year_id, 'name' => $code, 'code' => $code, 'capacity' => 30, 'is_active' => true]);
    }

    private function teacher(string $email, string $role = 'guru'): array
    {
        $employee = Employee::create(['full_name' => 'Guru '.$email, 'is_teacher' => true, 'is_active' => true]);
        $user = User::factory()->create(['email' => $email, 'is_active' => true, 'userable_type' => $employee->getMorphClass(), 'userable_id' => $employee->id]);
        $user->syncRoles([$role]);

        return [$user, $employee];
    }

    private function enroll(SchoolClass $class, Semester $sem, string $nis): Student
    {
        $student = Student::create(['nis' => $nis, 'full_name' => 'Siswa '.$nis]);
        ClassStudent::create(['school_class_id' => $class->id, 'student_id' => $student->id, 'academic_year_id' => $sem->academic_year_id, 'status' => 'aktif']);

        return $student;
    }

    public function test_teacher_may_only_record_attendance_for_own_classes(): void
    {
        $sem = $this->semester();
        $mine = $this->klass($sem, 'A-1');
        $other = $this->klass($sem, 'B-1');
        $subject = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        [$user, $employee] = $this->teacher('guru1@syntaf.test');
        ClassSubject::create(['school_class_id' => $mine->id, 'subject_id' => $subject->id, 'employee_id' => $employee->id, 'academic_year_id' => $sem->academic_year_id]);
        $s1 = $this->enroll($mine, $sem, 'N1');
        $s2 = $this->enroll($other, $sem, 'N2');
        Sanctum::actingAs($user, ['*']);

        $payload = fn (SchoolClass $c, Student $s) => [
            'class_id' => $c->id, 'semester_id' => $sem->id, 'date' => '2026-10-05',
            'entries' => [['student_id' => $s->id, 'attendance_status_id' => AttendanceStatus::where('code', 'H')->value('id')]],
        ];

        $this->postJson('/api/v1/student-attendances/bulk', $payload($other, $s2))->assertStatus(403);
        $this->getJson("/api/v1/student-attendances/sheet?class_id={$other->id}&semester_id={$sem->id}&date=2026-10-05")->assertStatus(403);
        $this->postJson('/api/v1/student-attendances/bulk', $payload($mine, $s1))->assertOk();

        // recorded_by = ID AKUN (bukan ID pegawai) — dulu FK-nya salah ke `employees`.
        $this->assertSame($user->id, (int) StudentAttendance::where('student_id', $s1->id)->value('recorded_by'));
    }

    public function test_teacher_may_only_score_own_assessments(): void
    {
        $sem = $this->semester();
        $class = $this->klass($sem, 'C-1');
        $subject = Subject::create(['code' => 'IPA', 'name' => 'IPA']);
        [$owner, $ownerEmp] = $this->teacher('owner@syntaf.test');
        [$rival] = $this->teacher('rival@syntaf.test');
        ClassSubject::create(['school_class_id' => $class->id, 'subject_id' => $subject->id, 'employee_id' => $ownerEmp->id, 'academic_year_id' => $sem->academic_year_id]);
        $student = $this->enroll($class, $sem, 'N3');
        $type = AssessmentType::create(['code' => 'uh', 'name' => 'Ulangan Harian', 'category' => 'pengetahuan']);
        $assessment = Assessment::create([
            'school_class_id' => $class->id, 'subject_id' => $subject->id, 'semester_id' => $sem->id, 'employee_id' => $ownerEmp->id,
            'assessment_type_id' => $type->id, 'name' => 'UH 1', 'max_score' => 100, 'status' => Assessment::STATUS_DRAFT,
        ]);
        $body = ['entries' => [['student_id' => $student->id, 'score' => 80]]];

        Sanctum::actingAs($rival, ['*']);
        $this->postJson("/api/v1/assessments/{$assessment->id}/scores/bulk", $body)->assertStatus(403);
        $this->postJson("/api/v1/assessments/{$assessment->id}/submit")->assertStatus(403);

        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/v1/assessments/{$assessment->id}/scores/bulk", $body)->assertOk();
    }

    public function test_family_account_cannot_read_scores_of_students_that_are_not_theirs(): void
    {
        $guardian = Guardian::create(['full_name' => 'Wali']);
        $child = Student::create(['nis' => 'K1', 'full_name' => 'Anak']);
        $stranger = Student::create(['nis' => 'K2', 'full_name' => 'Orang Lain']);
        DB::table('student_guardians')->insert(['student_id' => $child->id, 'guardian_id' => $guardian->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['email' => 'wali@syntaf.test', 'is_active' => true, 'userable_type' => $guardian->getMorphClass(), 'userable_id' => $guardian->id]);
        $user->syncRoles(['wali-murid']);
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/v1/students/{$child->id}/scores")->assertOk();
        $this->getJson("/api/v1/students/{$stranger->id}/scores")->assertStatus(404);
        $this->getJson("/api/v1/students/{$stranger->id}/assignments")->assertStatus(404);
        $this->getJson("/api/v1/attendance-recap/student/{$stranger->id}")->assertStatus(404);
    }

    public function test_staff_roles_are_not_restricted_by_the_family_gate(): void
    {
        $student = Student::create(['nis' => 'K3', 'full_name' => 'Siapa Saja']);
        [$teacher] = $this->teacher('gurux@syntaf.test');
        Sanctum::actingAs($teacher, ['*']);

        $this->getJson("/api/v1/students/{$student->id}/scores")->assertOk();
    }

    public function test_regular_employee_roles_can_use_the_approval_inbox_but_not_create_requests(): void
    {
        $this->seed(WorkflowPermissionSeeder::class);
        [$teacher] = $this->teacher('inbox@syntaf.test');
        Sanctum::actingAs($teacher, ['*']);

        $this->getJson('/api/v1/workflows/inbox/counter')->assertOk();
        $this->getJson('/api/v1/workflows/inbox/approvals')->assertOk();
        $this->getJson('/api/v1/workflows/inbox/requests')->assertOk();
        $this->postJson('/api/v1/workflows/instances', ['definition_code' => 'LEAVE_REQUEST_APPROVAL', 'title' => 'x'])->assertStatus(403);
    }

    public function test_teacher_manages_only_own_assignments(): void
    {
        $sem = $this->semester();
        $class = $this->klass($sem, 'D-1');
        $subject = Subject::create(['code' => 'BIN', 'name' => 'B. Indonesia']);
        [$owner, $ownerEmp] = $this->teacher('tugas1@syntaf.test');
        [$rival, $rivalEmp] = $this->teacher('tugas2@syntaf.test');

        $payload = fn (int $teacherId) => [
            'class_id' => $class->id, 'subject_id' => $subject->id, 'teacher_id' => $teacherId, 'semester_id' => $sem->id,
            'title' => 'Tugas 1', 'description' => 'Kerjakan', 'type' => 'tugas', 'max_score' => 100, 'weight' => 10,
            'assigned_date' => '2026-10-01', 'due_date' => '2026-10-08',
        ];

        Sanctum::actingAs($owner, ['*']);
        $this->postJson('/api/v1/assignments', $payload($rivalEmp->id))->assertStatus(403);
        $id = $this->postJson('/api/v1/assignments', $payload($ownerEmp->id))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/assignments/{$id}/publish")->assertOk();

        Sanctum::actingAs($rival, ['*']);
        $this->postJson("/api/v1/assignments/{$id}/close")->assertStatus(403);
    }
}
