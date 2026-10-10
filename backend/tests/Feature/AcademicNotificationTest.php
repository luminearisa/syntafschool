<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\EffectiveDay;
use App\Models\Guardian;
use App\Models\PassingGradeRule;
use App\Models\ReportCard;
use App\Models\SchoolClass;
use App\Models\Score;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A8 — Notifikasi akademik ke wali murid (kanal database).
 *
 * Cakupan: rapor terbit (termasuk penanda nilai di bawah KKTP) dan alpa
 * berturut-turut. Penerima adalah akun wali yang tertaut ke siswa.
 */
class AcademicNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seedAttendanceStatuses();
    }

    private function seedAttendanceStatuses(): void
    {
        $rows = [
            ['code' => 'H', 'name' => 'Hadir', 'color' => '#16a34a'],
            ['code' => 'A', 'name' => 'Alpa', 'color' => '#dc2626'],
            ['code' => 'I', 'name' => 'Izin', 'color' => '#f59e0b'],
            ['code' => 'S', 'name' => 'Sakit', 'color' => '#0ea5e9'],
            ['code' => 'T', 'name' => 'Terlambat', 'color' => '#a855f7'],
        ];

        foreach ($rows as $row) {
            AttendanceStatus::firstOrCreate(['code' => $row['code']], $row);
        }
    }

    private function statusId(string $code): int
    {
        return (int) AttendanceStatus::query()->where('code', $code)->value('id');
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
        foreach ([['N001', 'Alya'], ['N002', 'Bima']] as [$nis, $name]) {
            $student = Student::create(['nis' => $nis, 'full_name' => $name, 'is_active' => true]);
            ClassStudent::create([
                'school_class_id' => $class->id, 'student_id' => $student->id,
                'academic_year_id' => $year->id, 'status' => 'aktif',
            ]);
            $students[] = $student;
        }

        return compact('class', 'semester', 'students', 'subject');
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

    /** Wali berakun yang tertaut ke siswa lewat `users.userable`; email akun sengaja dibedakan. */
    private function guardianUserFor(Student $student): User
    {
        $guardian = Guardian::create([
            'full_name' => 'Wali '.$student->full_name,
            'email' => 'wali-'.$student->id.'@syntaf.test',
        ]);

        StudentGuardian::create([
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'is_primary' => true,
        ]);

        return User::factory()->create([
            'email' => 'akun-wali-'.$student->id.'@syntaf.test',
            'is_active' => true,
            'userable_type' => (new Guardian)->getMorphClass(),
            'userable_id' => $guardian->id,
        ]);
    }

    private function publishCardFor(array $world, int $studentId): void
    {
        $this->actingAsRole();
        $this->postJson('/api/v1/report-cards/generate', [
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'type' => ReportCard::TYPE_SEMESTER,
        ])->assertOk();

        $card = ReportCard::query()->where('student_id', $studentId)->firstOrFail();

        $this->postJson("/api/v1/report-cards/{$card->id}/ready")->assertOk();
        $this->postJson("/api/v1/report-cards/{$card->id}/approve")->assertOk();
        $this->postJson("/api/v1/report-cards/{$card->id}/publish")->assertOk();
    }

    public function test_wali_menerima_notifikasi_saat_rapor_diterbitkan(): void
    {
        $world = $this->world();
        $this->finalizedAssessment($world);
        $student = $world['students'][0];
        $guardian = $this->guardianUserFor($student);

        $this->publishCardFor($world, (int) $student->id);

        $this->assertSame(1, $guardian->notifications()->count());
        $notification = $guardian->notifications()->first();
        $this->assertSame('REPORT_CARD_PUBLISHED', $notification->data['type']);
        $this->assertSame((int) $student->id, (int) $notification->data['student_id']);
    }

    public function test_notifikasi_rapor_menandai_mapel_di_bawah_kktp(): void
    {
        $world = $this->world();
        $this->finalizedAssessment($world);
        $student = $world['students'][0];
        $guardian = $this->guardianUserFor($student);

        // Nilai Alya 90; KKTP 95 → di bawah batas.
        PassingGradeRule::create([
            'subject_id' => $world['subject']->id,
            'education_level_id' => null,
            'academic_year_id' => null,
            'min_score' => 95,
        ]);

        $this->publishCardFor($world, (int) $student->id);

        $notification = $guardian->notifications()->firstOrFail();
        $this->assertContains('Matematika', $notification->data['meta']['below_kktp']);
        $this->assertStringContainsString('KKTP', (string) $notification->data['body']);
    }

    public function test_tidak_menandai_kktp_bila_nilai_tuntas(): void
    {
        $world = $this->world();
        $this->finalizedAssessment($world);
        $student = $world['students'][0];
        $guardian = $this->guardianUserFor($student);

        PassingGradeRule::create([
            'subject_id' => $world['subject']->id,
            'education_level_id' => null,
            'academic_year_id' => null,
            'min_score' => 50,
        ]);

        $this->publishCardFor($world, (int) $student->id);

        $notification = $guardian->notifications()->firstOrFail();
        $this->assertSame([], $notification->data['meta']['below_kktp']);
        $this->assertStringNotContainsString('KKTP', (string) $notification->data['body']);
    }

    public function test_wali_dinotifikasi_setelah_alpa_tiga_hari_berturut(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $guardian = $this->guardianUserFor($student);

        $dates = ['2026-09-01', '2026-09-02', '2026-09-03'];
        foreach ($dates as $date) {
            EffectiveDay::create([
                'semester_id' => $world['semester']->id,
                'date' => $date,
                'is_school_day' => true,
            ]);
        }

        $this->actingAsRole();

        foreach ($dates as $index => $date) {
            $this->postJson('/api/v1/student-attendances/bulk', [
                'class_id' => $world['class']->id,
                'semester_id' => $world['semester']->id,
                'date' => $date,
                'entries' => [
                    ['student_id' => $student->id, 'attendance_status_id' => $this->statusId('A')],
                ],
            ])->assertOk();

            // Notifikasi baru dikirim tepat saat rentetan mencapai 3 hari.
            $expected = $index === 2 ? 1 : 0;
            $this->assertSame($expected, $guardian->notifications()->count(), "notifikasi pada hari ke-".($index + 1));
        }

        $notification = $guardian->notifications()->first();
        $this->assertSame('ATTENDANCE_CONSECUTIVE_ABSENCE', $notification->data['type']);
        $this->assertSame(3, (int) $notification->data['meta']['streak']);
    }
}
