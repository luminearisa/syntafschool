<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\ClassStudent;
use App\Models\Employee;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 3 mobile (siswa): pengumpulan tugas oleh siswa sendiri + gerbang perpustakaan.
 */
class MobilePhase3Test extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-123';

    private Student $student;

    private SchoolClass $class;

    private AcademicYear $year;

    private Semester $semester;

    private Subject $subject;

    private Employee $teacher;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->semester = Semester::create(['academic_year_id' => $this->year->id, 'name' => 'Ganjil', 'start_date' => '2026-07-01', 'end_date' => '2026-12-20', 'is_active' => true]);
        $this->subject = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $this->class = SchoolClass::create(['academic_year_id' => $this->year->id, 'name' => '7A', 'code' => '7A', 'capacity' => 32]);
        $this->teacher = Employee::create(['full_name' => 'Guru Uji', 'is_active' => true]);
        $this->student = Student::create(['nis' => 'S1', 'full_name' => 'Siswa Uji']);
        ClassStudent::create(['school_class_id' => $this->class->id, 'student_id' => $this->student->id, 'academic_year_id' => $this->year->id, 'status' => 'aktif']);

        User::factory()->create([
            'email' => 'siswa@syntaf.test', 'password' => self::PASSWORD, 'is_active' => true,
            'userable_type' => $this->student->getMorphClass(), 'userable_id' => $this->student->id,
        ])->syncRoles(['siswa']);

        $this->token = $this->postJson('/api/v1/auth/login', ['email' => 'siswa@syntaf.test', 'password' => self::PASSWORD, 'client' => 'mobile'])
            ->assertOk()->json('data.token');
    }

    private function h(): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function assignment(array $over = [], ?SchoolClass $class = null): Assignment
    {
        return Assignment::create($over + [
            'class_id' => ($class ?? $this->class)->id, 'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id,
            'semester_id' => $this->semester->id, 'title' => 'PR Bab 1', 'description' => 'Kerjakan', 'type' => 'tugas',
            'max_score' => 100, 'assigned_date' => now()->subDay()->toDateString(), 'due_date' => now()->addDays(3)->toDateString(),
            'allow_late_submission' => false, 'status' => 'published', 'published_at' => now(),
        ]);
    }

    public function test_student_submits_own_assignment_with_server_time(): void
    {
        $a = $this->assignment();

        $this->postJson("/api/v1/mobile/assignments/{$a->id}/submit", ['content' => 'Jawaban saya', 'student_id' => 999, 'submitted_at' => '2000-01-01 00:00:00'], $this->h())
            ->assertStatus(201);

        $row = DB::table('assignment_submissions')->where('assignment_id', $a->id)->first();
        $this->assertSame($this->student->id, (int) $row->student_id);
        $this->assertSame('submitted', $row->status);
        $this->assertGreaterThan('2020-01-01', $row->submitted_at);
    }

    public function test_assignment_of_another_class_is_not_found(): void
    {
        $other = SchoolClass::create(['academic_year_id' => $this->year->id, 'name' => '8B', 'code' => '8B', 'capacity' => 32]);
        $a = $this->assignment([], $other);

        $this->postJson("/api/v1/mobile/assignments/{$a->id}/submit", ['content' => 'x'], $this->h())->assertStatus(404);
        $this->assertSame(0, DB::table('assignment_submissions')->count());
    }

    public function test_late_rule_and_graded_lock(): void
    {
        $late = $this->assignment(['due_date' => now()->subDays(2)->toDateString()]);
        $this->postJson("/api/v1/mobile/assignments/{$late->id}/submit", ['content' => 'x'], $this->h())->assertStatus(422);

        $lateOk = $this->assignment(['due_date' => now()->subDays(2)->toDateString(), 'allow_late_submission' => true, 'title' => 'Boleh telat']);
        $this->postJson("/api/v1/mobile/assignments/{$lateOk->id}/submit", ['content' => 'x'], $this->h())->assertStatus(201);
        $this->assertSame('late', DB::table('assignment_submissions')->where('assignment_id', $lateOk->id)->value('status'));

        DB::table('assignment_submissions')->where('assignment_id', $lateOk->id)->update(['status' => 'graded', 'score' => 90]);
        $this->postJson("/api/v1/mobile/assignments/{$lateOk->id}/submit", ['content' => 'timpa'], $this->h())->assertStatus(422);
        $this->assertSame('x', DB::table('assignment_submissions')->where('assignment_id', $lateOk->id)->value('content'));
    }

    public function test_draft_assignment_cannot_be_submitted(): void
    {
        $a = $this->assignment(['status' => 'draft', 'published_at' => null]);
        $this->postJson("/api/v1/mobile/assignments/{$a->id}/submit", ['content' => 'x'], $this->h())->assertStatus(422);
    }

    public function test_library_requires_membership_and_guards_other_members_records(): void
    {
        $this->getJson('/api/v1/mobile/library/me', $this->h())->assertOk()->assertJsonPath('data.is_member', false);
        $this->postJson('/api/v1/mobile/library/reservations', ['slug' => 'apa-saja'], $this->h())->assertStatus(403);
        $this->postJson('/api/v1/mobile/library/loans/999/renew', [], $this->h())->assertStatus(404);
        $this->postJson('/api/v1/mobile/library/reservations/999/cancel', [], $this->h())->assertStatus(404);
    }

    public function test_student_cannot_reach_staff_assignment_submissions_endpoint(): void
    {
        $a = $this->assignment();
        $this->getJson("/api/v1/assignments/{$a->id}/submissions", $this->h())->assertStatus(403);
    }
}
