<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A11b — Tugas (Assignment) & Pengumpulan.
 *
 * Setiap aturan bisnis punya test tersendiri: urutan tanggal, rentang skor &
 * bobot, publish/close, pengumpulan terlambat, keanggotaan kelas, pengumpulan
 * ganda, dan penilaian.
 */
class AssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'email' => "{$role}@syntaf.test",
            'is_active' => true,
        ]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function makeSemester(): Semester
    {
        $year = AcademicYear::firstOrCreate(
            ['name' => '2025/2026'],
            ['start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => true],
        );

        return Semester::firstOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'Ganjil'],
            ['start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'is_active' => true],
        );
    }

    private function makeClass(Semester $semester, string $code = 'X-IPA-1', string $name = 'X IPA 1'): SchoolClass
    {
        return SchoolClass::create([
            'academic_year_id' => $semester->academic_year_id,
            'name' => $name,
            'code' => $code,
            'capacity' => 32,
            'is_active' => true,
        ]);
    }

    private function makeSubject(string $code = 'MTK', string $name = 'Matematika'): Subject
    {
        return Subject::create([
            'code' => $code,
            'name' => $name,
            'category' => 'Wajib',
            'credit' => 4,
            'is_active' => true,
        ]);
    }

    private function makeTeacher(string $name = 'Budi Santoso'): Employee
    {
        return Employee::create([
            'full_name' => $name,
            'employee_type' => 'guru',
            'employment_status' => 'PNS',
            'is_teacher' => true,
            'is_active' => true,
        ]);
    }

    private function makeStudent(string $nis, string $name): Student
    {
        return Student::create([
            'nis' => $nis,
            'full_name' => $name,
            'is_active' => true,
        ]);
    }

    private function enroll(SchoolClass $class, Student $student): ClassStudent
    {
        return ClassStudent::create([
            'school_class_id' => $class->id,
            'student_id' => $student->id,
            'academic_year_id' => $class->academic_year_id,
        ]);
    }

    /**
     * Dunia minimal: satu kelas dengan Matematika terdaftar, satu guru, dua siswa.
     *
     * @return array{semester: Semester, class: SchoolClass, subject: Subject, teacher: Employee, students: list<Student>}
     */
    private function world(): array
    {
        $semester = $this->makeSemester();
        $class = $this->makeClass($semester);
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();

        ClassSubject::create([
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'employee_id' => $teacher->id,
            'academic_year_id' => $class->academic_year_id,
        ]);

        $students = [
            $this->makeStudent('2026001', 'Andi Pratama'),
            $this->makeStudent('2026002', 'Bunga Lestari'),
        ];

        foreach ($students as $student) {
            $this->enroll($class, $student);
        }

        return compact('semester', 'class', 'subject', 'teacher', 'students');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $world, array $overrides = []): array
    {
        return array_merge([
            'class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'teacher_id' => $world['teacher']->id,
            'semester_id' => $world['semester']->id,
            'title' => 'Latihan Soal Bab 1',
            'description' => 'Mengerjakan latihan soal bab 1.',
            'instructions' => 'Tulis tangan pada buku tugas.',
            'type' => Assignment::TYPE_TUGAS,
            'max_score' => 100,
            'weight' => 20,
            'assigned_date' => '2026-09-01',
            'due_date' => '2026-09-10',
            'allow_late_submission' => false,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAssignment(array $world, array $overrides = []): Assignment
    {
        return Assignment::create(array_merge([
            'class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'teacher_id' => $world['teacher']->id,
            'semester_id' => $world['semester']->id,
            'title' => 'Latihan Soal Bab 1',
            'description' => 'Mengerjakan latihan soal bab 1.',
            'type' => Assignment::TYPE_TUGAS,
            'max_score' => 100,
            'weight' => 20,
            'assigned_date' => '2026-09-01',
            'due_date' => '2026-09-10',
            'allow_late_submission' => false,
            'status' => Assignment::STATUS_PUBLISHED,
            'published_at' => '2026-09-01 07:00:00',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSubmission(Assignment $assignment, Student $student, array $overrides = []): AssignmentSubmission
    {
        return AssignmentSubmission::create(array_merge([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
            'submitted_at' => '2026-09-09 10:00:00',
            'content' => 'Jawaban saya.',
            'status' => AssignmentSubmission::STATUS_SUBMITTED,
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // CRUD tugas
    // -----------------------------------------------------------------

    public function test_super_admin_can_list_assignments(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $this->makeAssignment($world);

        $this->getJson('/api/v1/assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Latihan Soal Bab 1')
            ->assertJsonPath('data.0.subject.code', 'MTK')
            ->assertJsonPath('data.0.teacher.full_name', 'Budi Santoso')
            ->assertJsonPath('data.0.submission_count', 0)
            ->assertJsonPath('data.0.graded_count', 0);
    }

    public function test_list_can_filter_by_class_status_and_type(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $this->makeAssignment($world, ['title' => 'Tugas A', 'status' => Assignment::STATUS_PUBLISHED]);
        $this->makeAssignment($world, ['title' => 'Kuis B', 'type' => Assignment::TYPE_KUIS, 'status' => Assignment::STATUS_DRAFT]);

        $this->getJson('/api/v1/assignments?filter[class_id]='.$world['class']->id.'&filter[status]=draft')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Kuis B');

        $this->getJson('/api/v1/assignments?filter[type]=kuis')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'kuis');
    }

    public function test_list_can_search_by_title(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $this->makeAssignment($world, ['title' => 'Laporan Praktikum']);
        $this->makeAssignment($world, ['title' => 'Kuis Mendadak']);

        $this->getJson('/api/v1/assignments?search=praktikum')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Laporan Praktikum');
    }

    public function test_super_admin_can_create_assignment(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->postJson('/api/v1/assignments', $this->payload($world))
            ->assertCreated()
            ->assertJsonPath('data.title', 'Latihan Soal Bab 1')
            ->assertJsonPath('data.status', Assignment::STATUS_DRAFT)
            ->assertJsonPath('data.is_overdue', true);

        $this->assertDatabaseHas('assignments', [
            'class_id' => $world['class']->id,
            'title' => 'Latihan Soal Bab 1',
            'status' => Assignment::STATUS_DRAFT,
        ]);
    }

    public function test_show_returns_assignment_with_counts(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);
        $this->makeSubmission($assignment, $world['students'][0], ['status' => AssignmentSubmission::STATUS_GRADED, 'score' => 90]);

        $this->getJson("/api/v1/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('data.submission_count', 1)
            ->assertJsonPath('data.graded_count', 1)
            ->assertJsonPath('data.school_class.name', 'X IPA 1');
    }

    public function test_super_admin_can_update_assignment(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);

        $this->putJson("/api/v1/assignments/{$assignment->id}", $this->payload($world, [
            'title' => 'Latihan Soal Bab 1 (Revisi)',
            'weight' => 30,
        ]))
            ->assertOk()
            ->assertJsonPath('data.title', 'Latihan Soal Bab 1 (Revisi)');

        $this->assertSame('30.00', $assignment->refresh()->weight);
    }

    public function test_super_admin_can_delete_assignment(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);

        $this->deleteJson("/api/v1/assignments/{$assignment->id}")->assertOk();

        $this->assertSoftDeleted('assignments', ['id' => $assignment->id]);
    }

    // -----------------------------------------------------------------
    // Aturan urutan tanggal & rentang nilai
    // -----------------------------------------------------------------

    public function test_due_date_before_assigned_date_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->postJson('/api/v1/assignments', $this->payload($world, [
            'assigned_date' => '2026-09-10',
            'due_date' => '2026-09-01',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_date');

        $this->assertDatabaseCount('assignments', 0);
    }

    public function test_max_score_must_be_greater_than_zero(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->postJson('/api/v1/assignments', $this->payload($world, ['max_score' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_score');

        $this->assertDatabaseCount('assignments', 0);
    }

    public function test_weight_must_be_between_zero_and_hundred(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->postJson('/api/v1/assignments', $this->payload($world, ['weight' => 120]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('weight');

        $this->postJson('/api/v1/assignments', $this->payload($world, ['weight' => -5]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('weight');

        $this->assertDatabaseCount('assignments', 0);
    }

    public function test_teacher_must_be_a_teacher(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $staff = Employee::create([
            'full_name' => 'Staf TU',
            'employee_type' => 'staf',
            'employment_status' => 'Honorer',
            'is_teacher' => false,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/assignments', $this->payload($world, ['teacher_id' => $staff->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('teacher_id');
    }

    // -----------------------------------------------------------------
    // Publish & close
    // -----------------------------------------------------------------

    public function test_publish_sets_status_and_timestamp(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world, ['status' => Assignment::STATUS_DRAFT, 'published_at' => null]);

        $this->postJson("/api/v1/assignments/{$assignment->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', Assignment::STATUS_PUBLISHED);

        $assignment->refresh();
        $this->assertSame(Assignment::STATUS_PUBLISHED, $assignment->status);
        $this->assertNotNull($assignment->published_at);
    }

    public function test_close_sets_status_without_removing_submissions(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);
        $this->makeSubmission($assignment, $world['students'][0]);

        $this->postJson("/api/v1/assignments/{$assignment->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', Assignment::STATUS_CLOSED);

        $this->assertDatabaseCount('assignment_submissions', 1);
    }

    // -----------------------------------------------------------------
    // Pengumpulan
    // -----------------------------------------------------------------

    public function test_member_student_can_submit(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", [
            'student_id' => $world['students'][0]->id,
            'content' => 'Ini jawaban saya.',
            'submitted_at' => '2026-09-09 10:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', AssignmentSubmission::STATUS_SUBMITTED)
            ->assertJsonPath('data.student.nis', '2026001');

        $this->assertDatabaseHas('assignment_submissions', [
            'assignment_id' => $assignment->id,
            'student_id' => $world['students'][0]->id,
        ]);
    }

    public function test_submit_on_draft_assignment_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world, ['status' => Assignment::STATUS_DRAFT]);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", [
            'student_id' => $world['students'][0]->id,
            'submitted_at' => '2026-09-09 10:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assignment');
    }

    public function test_submit_on_closed_assignment_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world, ['status' => Assignment::STATUS_CLOSED]);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", [
            'student_id' => $world['students'][0]->id,
            'submitted_at' => '2026-09-09 10:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assignment');
    }

    public function test_late_submission_is_rejected_when_not_allowed(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world, ['allow_late_submission' => false]);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", [
            'student_id' => $world['students'][0]->id,
            'submitted_at' => '2026-09-12 10:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('submitted_at');

        $this->assertDatabaseCount('assignment_submissions', 0);
    }

    public function test_late_submission_is_stored_as_late_when_allowed(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world, ['allow_late_submission' => true]);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", [
            'student_id' => $world['students'][0]->id,
            'submitted_at' => '2026-09-12 10:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', AssignmentSubmission::STATUS_LATE)
            ->assertJsonPath('data.is_late', true);
    }

    public function test_submitting_twice_updates_existing_submission(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", [
            'student_id' => $world['students'][0]->id,
            'content' => 'Versi pertama',
            'submitted_at' => '2026-09-08 10:00',
        ])->assertCreated();

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", [
            'student_id' => $world['students'][0]->id,
            'content' => 'Versi perbaikan',
            'submitted_at' => '2026-09-09 10:00',
        ])
            ->assertOk()
            ->assertJsonPath('data.content', 'Versi perbaikan');

        $this->assertDatabaseCount('assignment_submissions', 1);
    }

    public function test_student_outside_the_class_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);
        $outsider = $this->makeStudent('2026999', 'Siswa Luar');

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions", [
            'student_id' => $outsider->id,
            'submitted_at' => '2026-09-09 10:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_id');
    }

    public function test_update_submission_content(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);
        $submission = $this->makeSubmission($assignment, $world['students'][0]);

        $this->putJson("/api/v1/assignments/{$assignment->id}/submissions/{$submission->id}", [
            'content' => 'Isi yang diperbarui',
        ])
            ->assertOk()
            ->assertJsonPath('data.content', 'Isi yang diperbarui');
    }

    public function test_delete_submission(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);
        $submission = $this->makeSubmission($assignment, $world['students'][0]);

        $this->deleteJson("/api/v1/assignments/{$assignment->id}/submissions/{$submission->id}")->assertOk();

        $this->assertSoftDeleted('assignment_submissions', ['id' => $submission->id]);
    }

    public function test_submission_from_another_assignment_returns_404(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignmentA = $this->makeAssignment($world, ['title' => 'Tugas A']);
        $assignmentB = $this->makeAssignment($world, ['title' => 'Tugas B']);
        $submission = $this->makeSubmission($assignmentA, $world['students'][0]);

        $this->deleteJson("/api/v1/assignments/{$assignmentB->id}/submissions/{$submission->id}")
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Penilaian
    // -----------------------------------------------------------------

    public function test_grade_without_submission_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);

        // Baris ada tetapi belum benar-benar dikumpulkan (submitted_at null).
        $pending = AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $world['students'][0]->id,
            'status' => AssignmentSubmission::STATUS_PENDING,
        ]);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions/{$pending->id}/grade", [
            'score' => 80,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('submission');
    }

    public function test_grade_fills_graded_by_and_graded_at(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);
        $submission = $this->makeSubmission($assignment, $world['students'][0]);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions/{$submission->id}/grade", [
            'score' => 88,
            'feedback' => 'Bagus sekali.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', AssignmentSubmission::STATUS_GRADED)
            ->assertJsonPath('data.feedback', 'Bagus sekali.');

        $submission->refresh();
        $this->assertSame('88.00', $submission->score);
        $this->assertNotNull($submission->graded_by);
        $this->assertNotNull($submission->graded_at);
    }

    public function test_score_above_max_score_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world, ['max_score' => 100]);
        $submission = $this->makeSubmission($assignment, $world['students'][0]);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions/{$submission->id}/grade", [
            'score' => 120,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('score');
    }

    public function test_negative_score_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);
        $submission = $this->makeSubmission($assignment, $world['students'][0]);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions/{$submission->id}/grade", [
            'score' => -1,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('score');
    }

    // -----------------------------------------------------------------
    // Daftar pengumpulan & rekap
    // -----------------------------------------------------------------

    public function test_submission_list_includes_students_who_have_not_submitted(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);
        $this->makeSubmission($assignment, $world['students'][0]);

        $this->getJson("/api/v1/assignments/{$assignment->id}/submissions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.submitted', 1)
            ->assertJsonPath('meta.pending', 1);

        $pending = collect($this->getJson("/api/v1/assignments/{$assignment->id}/submissions")->json('data'))
            ->firstWhere('student_id', $world['students'][1]->id);

        $this->assertSame(AssignmentSubmission::STATUS_PENDING, $pending['status']);
        $this->assertNull($pending['submission_id']);
    }

    public function test_submission_list_still_shows_pending_after_close(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world, ['status' => Assignment::STATUS_CLOSED]);

        $this->getJson("/api/v1/assignments/{$assignment->id}/submissions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pending', 2);
    }

    public function test_recap_counts_correctly(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $published = $this->makeAssignment($world, ['title' => 'Tugas Terbit']);
        $this->makeAssignment($world, ['title' => 'Tugas Draft', 'status' => Assignment::STATUS_DRAFT, 'published_at' => null]);

        $this->makeSubmission($published, $world['students'][0], [
            'status' => AssignmentSubmission::STATUS_GRADED,
            'score' => 90,
        ]);

        $this->getJson('/api/v1/assignments/recap?class_id='.$world['class']->id.'&semester_id='.$world['semester']->id)
            ->assertOk()
            ->assertJsonPath('data.total_assignments', 2)
            ->assertJsonPath('data.published_assignments', 1)
            ->assertJsonPath('data.total_students', 2)
            ->assertJsonPath('data.expected_submissions', 2)
            ->assertJsonPath('data.submitted', 1)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.graded', 1);
    }

    public function test_student_assignments_endpoint_returns_submission_status(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $assignment = $this->makeAssignment($world, ['title' => 'Tugas Andi']);
        $this->makeSubmission($assignment, $world['students'][0]);

        $this->getJson('/api/v1/students/'.$world['students'][0]->id.'/assignments?semester_id='.$world['semester']->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.assignment.title', 'Tugas Andi')
            ->assertJsonPath('data.0.status', AssignmentSubmission::STATUS_SUBMITTED)
            ->assertJsonPath('data.0.submission.student.nis', '2026001');

        // Siswa yang belum mengumpulkan tetap melihat tugasnya dengan status pending.
        $this->getJson('/api/v1/students/'.$world['students'][1]->id.'/assignments?semester_id='.$world['semester']->id)
            ->assertOk()
            ->assertJsonPath('data.0.status', AssignmentSubmission::STATUS_PENDING)
            ->assertJsonPath('data.0.submission', null);
    }

    // -----------------------------------------------------------------
    // RBAC
    // -----------------------------------------------------------------

    public function test_guru_can_grade_a_submission(): void
    {
        $this->actingAsRole('guru');
        $world = $this->world();
        $assignment = $this->makeAssignment($world);
        $submission = $this->makeSubmission($assignment, $world['students'][0]);

        $this->postJson("/api/v1/assignments/{$assignment->id}/submissions/{$submission->id}/grade", [
            'score' => 75,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', AssignmentSubmission::STATUS_GRADED);
    }

    public function test_siswa_cannot_access_staff_assignment_api(): void
    {
        $this->actingAsRole('siswa');
        $world = $this->world();

        // Daftar tugas tingkat sekolah bukan untuk siswa: setelah pengetatan
        // `academic.view`, siswa memakai portal kepemilikan
        // `/students/{student}/assignments` (academic.portal).
        $this->getJson('/api/v1/assignments')->assertForbidden();

        $this->postJson('/api/v1/assignments', $this->payload($world))->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/assignments')->assertUnauthorized();
    }
}
