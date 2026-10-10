<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\ReportCard;
use App\Models\Score;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TranscriptTest extends TestCase
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

        $students = [];
        foreach ([['T001', 'Alya'], ['T002', 'Bima']] as [$nis, $name]) {
            $student = Student::create(['nis' => $nis, 'full_name' => $name, 'is_active' => true]);
            ClassStudent::create([
                'school_class_id' => $class->id, 'student_id' => $student->id,
                'academic_year_id' => $year->id, 'status' => 'aktif',
            ]);
            $students[] = $student;
        }

        return compact('class', 'semester', 'students', 'subject', 'year');
    }

    private function finalizedAssessment(array $world): void
    {
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
        foreach ($world['students'] as $index => $student) {
            Score::create([
                'assessment_id' => $assessment->id,
                'student_id' => $student->id,
                'score' => $index === 0 ? 90 : 80,
            ]);
        }
    }

    private function publishCardFor(array $world, int $studentId): int
    {
        $this->actingAsRole();
        $this->postJson('/api/v1/report-cards/generate', [
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'type' => ReportCard::TYPE_SEMESTER,
        ])->assertOk();

        $card = ReportCard::query()
            ->where('student_id', $studentId)
            ->where('semester_id', $world['semester']->id)
            ->where('type', ReportCard::TYPE_SEMESTER)
            ->firstOrFail();

        $this->postJson("/api/v1/report-cards/{$card->id}/ready")->assertOk();
        $this->postJson("/api/v1/report-cards/{$card->id}/approve")->assertOk();
        $this->postJson("/api/v1/report-cards/{$card->id}/publish")->assertOk();

        return (int) $card->id;
    }

    public function test_transkrip_dibangun_dari_rapor_terbit(): void
    {
        $world = $this->world();
        $this->finalizedAssessment($world);
        $this->publishCardFor($world, (int) $world['students'][0]->id);

        $this->actingAsRole();
        $this->postJson('/api/v1/transcripts/generate', [
            'student_id' => $world['students'][0]->id,
            'academic_year_id' => $world['year']->id,
        ])->assertOk()
            ->assertJsonPath('data.data.student.full_name', 'Alya')
            ->assertJsonCount(1, 'data.data.subjects')
            ->assertJsonPath('data.data.subjects.0.name', 'Matematika')
            ->assertJsonPath('data.data.subjects.0.average', 90);
    }

    public function test_transkrip_ditolak_bila_belum_ada_rapor_terbit(): void
    {
        $world = $this->world();
        $this->finalizedAssessment($world);

        $this->actingAsRole();
        $this->postJson('/api/v1/transcripts/generate', [
            'student_id' => $world['students'][0]->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('student_id');
    }

    public function test_transkrip_daftar_memfilter_per_siswa(): void
    {
        $world = $this->world();
        $this->finalizedAssessment($world);
        $this->publishCardFor($world, (int) $world['students'][0]->id);

        $this->actingAsRole();
        $this->postJson('/api/v1/transcripts/generate', [
            'student_id' => $world['students'][0]->id,
        ])->assertOk();

        $this->getJson('/api/v1/transcripts?student_id='.$world['students'][0]->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_pdf_transkrip_dapat_diunduh(): void
    {
        $world = $this->world();
        $this->finalizedAssessment($world);
        $this->publishCardFor($world, (int) $world['students'][0]->id);

        $this->actingAsRole();
        $transcriptId = (int) $this->postJson('/api/v1/transcripts/generate', [
            'student_id' => $world['students'][0]->id,
        ])->assertOk()->json('data.id');

        $this->get("/api/v1/transcripts/{$transcriptId}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
