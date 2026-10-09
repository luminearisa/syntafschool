<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\Employee;
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

class ReportCardTest extends TestCase
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
        foreach ([['R001', 'Alya'], ['R002', 'Bima']] as [$nis, $name]) {
            $student = Student::create(['nis' => $nis, 'full_name' => $name, 'is_active' => true]);
            ClassStudent::create([
                'school_class_id' => $class->id, 'student_id' => $student->id,
                'academic_year_id' => $year->id, 'status' => 'aktif',
            ]);
            $students[] = $student;
        }

        return compact('class', 'semester', 'students', 'subject');
    }

    private function finalizedAssessment(array $world): Assessment
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

        return $assessment;
    }

    public function test_rapor_dapat_digenerate_disetujui_dan_diterbitkan_dengan_snapshot_nilai(): void
    {
        $world = $this->world();
        $this->finalizedAssessment($world);
        $this->actingAsRole();

        $generated = $this->postJson('/api/v1/report-cards/generate', [
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'type' => ReportCard::TYPE_SEMESTER,
        ])->assertOk()->assertJsonPath('data.generated', 2);

        $cardId = (int) $generated->json('data.reports.0.id');
        $card = ReportCard::findOrFail($cardId);
        $this->assertCount(1, $card->items);
        $this->assertNotNull($card->average_score);

        $this->putJson("/api/v1/report-cards/{$cardId}", [
            'homeroom_note' => 'Pertahankan semangat belajar.',
        ])->assertOk();
        $this->postJson("/api/v1/report-cards/{$cardId}/ready")->assertOk()
            ->assertJsonPath('data.status', ReportCard::STATUS_READY);
        $this->postJson("/api/v1/report-cards/{$cardId}/approve")->assertOk()
            ->assertJsonPath('data.status', ReportCard::STATUS_APPROVED);
        $this->postJson("/api/v1/report-cards/{$cardId}/publish")->assertOk()
            ->assertJsonPath('data.status', ReportCard::STATUS_PUBLISHED);

        $this->getJson("/api/v1/report-cards/{$cardId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.subject.name', 'Matematika')
            ->assertJsonPath('data.homeroom_note', 'Pertahankan semangat belajar.');
    }

    public function test_rapor_tidak_dapat_diajukan_jika_masih_ada_mapel_tanpa_nilai(): void
    {
        $world = $this->world();
        $type = AssessmentType::create(['code' => 'UH', 'name' => 'Ulangan Harian', 'category' => 'pengetahuan', 'is_active' => true]);
        Assessment::create([
            'school_class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'semester_id' => $world['semester']->id,
            'assessment_type_id' => $type->id,
            'name' => 'UH 1', 'max_score' => 100, 'weight' => 100,
            'status' => Assessment::STATUS_FINALIZED,
        ]);
        $this->actingAsRole();

        $cardId = $this->postJson('/api/v1/report-cards/generate', [
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'type' => ReportCard::TYPE_SEMESTER,
        ])->assertOk()->json('data.reports.0.id');

        $this->postJson("/api/v1/report-cards/{$cardId}/ready")
            ->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_rapor_tidak_dapat_digenerate_sebelum_semua_nilai_difinalisasi(): void
    {
        $world = $this->world();
        $assessment = $this->finalizedAssessment($world);
        $assessment->update(['status' => Assessment::STATUS_SUBMITTED]);
        $this->actingAsRole();

        $this->postJson('/api/v1/report-cards/generate', [
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'type' => ReportCard::TYPE_SEMESTER,
        ])->assertUnprocessable()->assertJsonValidationErrors('assessment');
    }

    public function test_rapor_terbit_tidak_bisa_dibuat_ulang(): void
    {
        $world = $this->world();
        $this->finalizedAssessment($world);
        $this->actingAsRole();

        $response = $this->postJson('/api/v1/report-cards/generate', [
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'type' => ReportCard::TYPE_SEMESTER,
        ])->assertOk();
        $cardId = (int) $response->json('data.reports.0.id');
        ReportCard::findOrFail($cardId)->update(['status' => ReportCard::STATUS_PUBLISHED]);

        $this->postJson('/api/v1/report-cards/generate', [
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'type' => ReportCard::TYPE_SEMESTER,
        ])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_guru_tidak_bisa_menyentuh_rapor_kelas_yang_bukan_ampunya(): void
    {
        $world = $this->world();
        $this->finalizedAssessment($world);

        $this->actingAsRole();
        $cardId = (int) $this->postJson('/api/v1/report-cards/generate', [
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'type' => ReportCard::TYPE_SEMESTER,
        ])->assertOk()->json('data.reports.0.id');

        $teacher = Employee::create([
            'full_name' => 'Guru Lain', 'employee_type' => 'guru',
            'employment_status' => 'PNS', 'is_teacher' => true, 'is_active' => true,
        ]);
        $guru = User::factory()->create([
            'is_active' => true,
            'userable_type' => $teacher->getMorphClass(),
            'userable_id' => $teacher->id,
        ]);
        $guru->syncRoles(['guru']);
        Sanctum::actingAs($guru, ['*']);

        $this->getJson("/api/v1/report-cards/{$cardId}")->assertForbidden();
        $this->patchJson("/api/v1/report-cards/{$cardId}", ['notes' => 'coba'])->assertForbidden();
        $this->postJson("/api/v1/report-cards/{$cardId}/ready")->assertForbidden();
    }

    public function test_wali_kelas_bisa_mengubah_rapor_kelasnya(): void
    {
        $world = $this->world();
        $this->finalizedAssessment($world);

        $this->actingAsRole();
        $cardId = (int) $this->postJson('/api/v1/report-cards/generate', [
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'type' => ReportCard::TYPE_SEMESTER,
        ])->assertOk()->json('data.reports.0.id');

        $teacher = Employee::create([
            'full_name' => 'Bu Wali', 'employee_type' => 'guru',
            'employment_status' => 'PNS', 'is_teacher' => true, 'is_active' => true,
        ]);
        $world['class']->update(['homeroom_teacher_id' => $teacher->id]);

        $wali = User::factory()->create([
            'is_active' => true,
            'userable_type' => $teacher->getMorphClass(),
            'userable_id' => $teacher->id,
        ]);
        $wali->syncRoles(['wali-kelas']);
        Sanctum::actingAs($wali, ['*']);

        $this->patchJson("/api/v1/report-cards/{$cardId}", ['notes' => 'Pertahankan semangat.'])->assertOk();
    }

    public function test_pdf_rapor_dapat_diunduh(): void
    {
        $world = $this->world();
        $this->finalizedAssessment($world);
        $this->actingAsRole();
        $cardId = $this->postJson('/api/v1/report-cards/generate', [
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'type' => ReportCard::TYPE_SEMESTER,
        ])->assertOk()->json('data.reports.0.id');

        $this->get("/api/v1/report-cards/{$cardId}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
