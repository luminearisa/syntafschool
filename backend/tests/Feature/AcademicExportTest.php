<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Score;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(string $role = 'admin-sekolah'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** @return array{class: SchoolClass, semester: Semester, students: list<Student>, subject: Subject} */
    private function world(): array
    {
        $year = AcademicYear::create([
            'name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $semester = Semester::create([
            'academic_year_id' => $year->id, 'name' => 'Ganjil',
            'start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'is_active' => true,
        ]);
        $class = SchoolClass::create([
            'academic_year_id' => $year->id, 'name' => 'X IPA 1', 'code' => 'X-IPA-1', 'capacity' => 30, 'is_active' => true,
        ]);
        $subject = Subject::create([
            'code' => 'MAT', 'name' => 'Matematika', 'category' => 'Wajib', 'credit' => 4, 'is_active' => true,
        ]);
        ClassSubject::create([
            'school_class_id' => $class->id, 'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $student = Student::create(['nis' => 'E001', 'full_name' => 'Citra', 'is_active' => true]);
        ClassStudent::create([
            'school_class_id' => $class->id, 'student_id' => $student->id,
            'academic_year_id' => $year->id, 'status' => 'aktif',
        ]);

        return compact('class', 'semester', 'subject') + ['students' => [$student]];
    }

    public function test_ekspor_nilai_mengembalikan_csv(): void
    {
        $world = $this->world();
        $type = AssessmentType::create([
            'code' => 'TUGAS', 'name' => 'Tugas', 'category' => 'pengetahuan', 'is_active' => true,
        ]);
        $assessment = Assessment::create([
            'school_class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'semester_id' => $world['semester']->id,
            'assessment_type_id' => $type->id,
            'name' => 'Tugas Bab 1',
            'max_score' => 100,
            'weight' => 100,
            'date' => '2026-09-01',
            'status' => Assessment::STATUS_FINALIZED,
        ]);
        Score::create([
            'assessment_id' => $assessment->id,
            'student_id' => $world['students'][0]->id,
            'score' => 90,
        ]);

        $this->actingAsRole();
        $response = $this->get('/api/v1/gradebook/export?class_id='.$world['class']->id.'&subject_id='.$world['subject']->id.'&semester_id='.$world['semester']->id);

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('Nama', $content);
        $this->assertStringContainsString('Tugas Bab 1', $content);
        $this->assertStringContainsString('Citra', $content);
    }

    public function test_ekspor_presensi_mengembalikan_csv(): void
    {
        $world = $this->world();
        $this->actingAsRole();

        $response = $this->get('/api/v1/attendance-recap/export?class_id='.$world['class']->id.'&semester_id='.$world['semester']->id);

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('Nama', $content);
        $this->assertStringContainsString('Citra', $content);
    }
}
