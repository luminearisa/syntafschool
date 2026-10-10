<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceStatus;
use App\Models\ClassStudent;
use App\Models\EducationLevel;
use App\Models\EffectiveDay;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LeaveType;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A6 — Presensi siswa, presensi pegawai, dan koreksi presensi.
 *
 * Setiap aturan bisnis punya test tersendiri: presensi ganda, rentang semester,
 * hari efektif, keanggotaan kelas, kewajiban jenis izin, penguncian presensi,
 * rekap berbasis hari efektif, dan alur approval koreksi.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seedDays();
        $this->seedAttendanceStatuses();
        $this->seedLeaveTypes();
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    private function seedDays(): void
    {
        $names = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

        foreach ($names as $id => $name) {
            DB::table('days')->updateOrInsert(
                ['id' => $id],
                ['name' => $name, 'created_at' => now(), 'updated_at' => now()],
            );
        }
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

    private function seedLeaveTypes(): void
    {
        foreach (['Sakit', 'Izin', 'Keperluan Keluarga', 'Dispensasi'] as $name) {
            LeaveType::firstOrCreate(['name' => $name]);
        }
    }

    private function statusId(string $code): int
    {
        return (int) AttendanceStatus::where('code', $code)->value('id');
    }

    private function actingAsRole(string $role = 'admin-sekolah'): User
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

    private function makeClass(Semester $semester, string $code = 'X-IPA-1'): SchoolClass
    {
        return SchoolClass::create([
            'academic_year_id' => $semester->academic_year_id,
            'name' => $code,
            'code' => $code,
            'capacity' => 32,
            'is_active' => true,
        ]);
    }

    private function makeStudent(Semester $semester, string $nis, string $name = 'Adi Nugroho'): Student
    {
        return Student::create([
            'nis' => $nis,
            'nisn' => '00'.$nis,
            'full_name' => $name,
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

    private function makeSubject(): Subject
    {
        return Subject::create([
            'code' => 'MTK',
            'name' => 'Matematika',
            'category' => 'Wajib',
            'credit' => 4,
            'is_active' => true,
        ]);
    }

    /**
     * Dunia minimal: satu kelas berisi dua siswa, satu guru, satu mapel.
     *
     * @return array{semester: Semester, class: SchoolClass, students: list<Student>, teacher: Employee, subject: Subject}
     */
    private function world(): array
    {
        $semester = $this->makeSemester();
        $class = $this->makeClass($semester);
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();

        $students = [
            $this->makeStudent($semester, '20250001', 'Adi Nugroho'),
            $this->makeStudent($semester, '20250002', 'Bunga Lestari'),
        ];

        foreach ($students as $student) {
            ClassStudent::create([
                'student_id' => $student->id,
                'school_class_id' => $class->id,
                'academic_year_id' => $semester->academic_year_id,
                'status' => 'aktif',
            ]);
        }

        return compact('semester', 'class', 'students', 'teacher', 'subject');
    }

    /**
     * Tandai beberapa tanggal sebagai hari efektif pada satu semester.
     *
     * @param  list<string>  $schoolDays
     * @param  list<string>  $holidays
     */
    private function seedEffectiveDays(Semester $semester, array $schoolDays, array $holidays = []): void
    {
        foreach ($schoolDays as $date) {
            EffectiveDay::create([
                'semester_id' => $semester->id,
                'date' => $date,
                'is_school_day' => true,
            ]);
        }

        foreach ($holidays as $date) {
            EffectiveDay::create([
                'semester_id' => $semester->id,
                'date' => $date,
                'is_school_day' => false,
                'note' => 'Libur',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $world, array $overrides = []): array
    {
        return array_merge([
            'student_id' => $world['students'][0]->id,
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('H'),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bulkPayload(array $world, array $overrides = []): array
    {
        return array_merge([
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'entries' => [
                ['student_id' => $world['students'][0]->id, 'attendance_status_id' => $this->statusId('H')],
                ['student_id' => $world['students'][1]->id, 'attendance_status_id' => $this->statusId('H')],
            ],
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // Input presensi
    // -----------------------------------------------------------------

    public function test_admin_can_record_single_attendance(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $this->postJson('/api/v1/student-attendances', $this->payload($world))
            ->assertCreated()
            ->assertJsonPath('data.status_code', 'H')
            ->assertJsonPath('data.date', '2026-09-10');

        $this->assertDatabaseHas('student_attendances', [
            'student_id' => $world['students'][0]->id,
            'date' => '2026-09-10 00:00:00',
        ]);
    }

    public function test_bulk_attendance_creates_rows_for_every_student(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $this->postJson('/api/v1/student-attendances/bulk', $this->bulkPayload($world))
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.updated', 0);

        $this->assertDatabaseCount('student_attendances', 2);
    }

    public function test_bulk_attendance_is_idempotent(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $this->postJson('/api/v1/student-attendances/bulk', $this->bulkPayload($world))->assertOk();

        // Simpan ulang dengan status berbeda — harus memperbarui, bukan menambah.
        $second = $this->bulkPayload($world, [
            'entries' => [
                ['student_id' => $world['students'][0]->id, 'attendance_status_id' => $this->statusId('A')],
                ['student_id' => $world['students'][1]->id, 'attendance_status_id' => $this->statusId('H')],
            ],
        ]);

        $this->postJson('/api/v1/student-attendances/bulk', $second)
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 2);

        $this->assertDatabaseCount('student_attendances', 2);
        $this->assertDatabaseHas('student_attendances', [
            'student_id' => $world['students'][0]->id,
            'attendance_status_id' => $this->statusId('A'),
        ]);
    }

    public function test_duplicate_single_attendance_is_rejected(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $this->postJson('/api/v1/student-attendances', $this->payload($world))->assertCreated();

        $this->postJson('/api/v1/student-attendances', $this->payload($world))
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_id');
    }

    public function test_attendance_date_must_be_inside_semester(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $this->postJson('/api/v1/student-attendances', $this->payload($world, ['date' => '2027-03-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }

    public function test_attendance_on_non_school_day_is_rejected(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $this->seedEffectiveDays($world['semester'], ['2026-09-09'], ['2026-09-10']);

        $this->postJson('/api/v1/student-attendances', $this->payload($world))
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }

    public function test_attendance_on_school_day_is_accepted(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $this->seedEffectiveDays($world['semester'], ['2026-09-10']);

        $this->postJson('/api/v1/student-attendances', $this->payload($world))->assertCreated();
    }

    public function test_semester_without_effective_days_is_not_blocked(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        // Tanpa satu pun baris effective_days, presensi tetap boleh dicatat.
        $this->assertDatabaseCount('effective_days', 0);

        $this->postJson('/api/v1/student-attendances', $this->payload($world))->assertCreated();
    }

    public function test_student_outside_class_is_rejected(): void
    {
        $this->actingAsRole();
        $world = $this->world();
        $outsider = $this->makeStudent($world['semester'], '20250999', 'Citra Ayu');

        $this->postJson('/api/v1/student-attendances', $this->payload($world, ['student_id' => $outsider->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_id');
    }

    public function test_bulk_rejects_student_outside_class(): void
    {
        $this->actingAsRole();
        $world = $this->world();
        $outsider = $this->makeStudent($world['semester'], '20250999', 'Citra Ayu');

        $payload = $this->bulkPayload($world, [
            'entries' => [
                ['student_id' => $outsider->id, 'attendance_status_id' => $this->statusId('H')],
            ],
        ]);

        $this->postJson('/api/v1/student-attendances/bulk', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('entries.0.student_id');
    }

    public function test_leave_type_is_required_for_sick_status(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $this->postJson('/api/v1/student-attendances', $this->payload($world, [
            'attendance_status_id' => $this->statusId('S'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('leave_type_id');
    }

    public function test_sick_status_with_leave_type_is_accepted(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $leaveTypeId = (int) LeaveType::where('name', 'Sakit')->value('id');

        $this->postJson('/api/v1/student-attendances', $this->payload($world, [
            'attendance_status_id' => $this->statusId('S'),
            'leave_type_id' => $leaveTypeId,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status_code', 'S');
    }

    public function test_bulk_requires_leave_type_for_permitted_status(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $payload = $this->bulkPayload($world, [
            'entries' => [
                ['student_id' => $world['students'][0]->id, 'attendance_status_id' => $this->statusId('I')],
            ],
        ]);

        $this->postJson('/api/v1/student-attendances/bulk', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('entries.0.leave_type_id');
    }

    public function test_attendance_can_be_updated(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('H'),
        ]);

        $this->putJson("/api/v1/student-attendances/{$attendance->id}", $this->payload($world, [
            'attendance_status_id' => $this->statusId('T'),
            'time_in' => '07:15',
        ]))
            ->assertOk()
            ->assertJsonPath('data.status_code', 'T')
            ->assertJsonPath('data.time_in', '07:15');
    }

    public function test_locked_attendance_cannot_be_updated(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('H'),
            'status' => StudentAttendance::STATUS_LOCKED,
        ]);

        $this->putJson("/api/v1/student-attendances/{$attendance->id}", $this->payload($world))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_locked_attendance_cannot_be_deleted(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('H'),
            'status' => StudentAttendance::STATUS_LOCKED,
        ]);

        $this->deleteJson("/api/v1/student-attendances/{$attendance->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_attendance_can_be_deleted(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('H'),
        ]);

        $this->deleteJson("/api/v1/student-attendances/{$attendance->id}")->assertOk();

        $this->assertDatabaseCount('student_attendances', 0);
    }

    public function test_sheet_lists_every_student_with_recorded_flag(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('H'),
        ]);

        $this->getJson('/api/v1/student-attendances/sheet?'.http_build_query([
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
        ]))
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.recorded', 1)
            ->assertJsonPath('data.items.0.recorded', true)
            ->assertJsonPath('data.items.1.recorded', false);
    }

    // -----------------------------------------------------------------
    // Rekap
    // -----------------------------------------------------------------

    public function test_student_recap_counts_each_status(): void
    {
        $this->actingAsRole();
        $world = $this->world();
        $student = $world['students'][0];

        $this->seedEffectiveDays($world['semester'], [
            '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10',
        ]);

        $rows = [
            ['2026-09-07', 'H'],
            ['2026-09-08', 'H'],
            ['2026-09-09', 'T'],
            ['2026-09-10', 'A'],
        ];

        foreach ($rows as [$date, $code]) {
            StudentAttendance::create([
                'student_id' => $student->id,
                'school_class_id' => $world['class']->id,
                'semester_id' => $world['semester']->id,
                'date' => $date,
                'attendance_status_id' => $this->statusId($code),
            ]);
        }

        $this->getJson("/api/v1/attendance-recap/student/{$student->id}?semester_id={$world['semester']->id}")
            ->assertOk()
            ->assertJsonPath('data.present', 2)
            ->assertJsonPath('data.late', 1)
            ->assertJsonPath('data.absent', 1)
            ->assertJsonPath('data.effective_days', 4)
            ->assertJsonPath('data.basis', 'effective_days')
            // Hadir + terlambat = 3 dari 4 hari efektif.
            // JSON menuliskan 75.0 sebagai 75, jadi dibandingkan sebagai angka.
            ->assertJsonPath('data.percentage', 75);
    }

    public function test_student_recap_falls_back_to_recorded_days(): void
    {
        $this->actingAsRole();
        $world = $this->world();
        $student = $world['students'][0];

        StudentAttendance::create([
            'student_id' => $student->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('H'),
        ]);

        $this->getJson("/api/v1/attendance-recap/student/{$student->id}?semester_id={$world['semester']->id}")
            ->assertOk()
            ->assertJsonPath('data.basis', 'recorded_days')
            ->assertJsonPath('data.effective_days', null)
            ->assertJsonPath('data.recorded_days', 1)
            ->assertJsonPath('data.percentage', 100);
    }

    public function test_student_recap_uses_its_own_education_level_effective_days(): void
    {
        $this->actingAsRole();
        $world = $this->world();
        $student = $world['students'][0];

        $levelA = EducationLevel::create(['name' => 'Kelas 10', 'jenjang' => 'SMA', 'level_number' => 10, 'is_active' => true]);
        $levelB = EducationLevel::create(['name' => 'Kelas 11', 'jenjang' => 'SMA', 'level_number' => 11, 'is_active' => true]);

        $world['class']->update(['education_level_id' => $levelA->id]);

        foreach (['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10'] as $date) {
            EffectiveDay::create([
                'semester_id' => $world['semester']->id,
                'education_level_id' => $levelA->id,
                'date' => $date,
                'is_school_day' => true,
            ]);
        }

        for ($i = 1; $i <= 10; $i++) {
            EffectiveDay::create([
                'semester_id' => $world['semester']->id,
                'education_level_id' => $levelB->id,
                'date' => '2026-10-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'is_school_day' => true,
            ]);
        }

        $this->getJson("/api/v1/attendance-recap/student/{$student->id}?semester_id={$world['semester']->id}")
            ->assertOk()
            ->assertJsonPath('data.effective_days', 4)
            ->assertJsonPath('data.basis', 'effective_days');
    }

    public function test_class_recap_aggregates_every_student(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $this->seedEffectiveDays($world['semester'], ['2026-09-10']);

        StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('H'),
        ]);

        StudentAttendance::create([
            'student_id' => $world['students'][1]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('A'),
        ]);

        $this->getJson("/api/v1/attendance-recap/class/{$world['class']->id}?semester_id={$world['semester']->id}")
            ->assertOk()
            ->assertJsonPath('data.student_count', 2)
            ->assertJsonPath('data.totals.present', 1)
            ->assertJsonPath('data.totals.absent', 1)
            ->assertJsonPath('data.totals.average_percentage', 50);
    }

    public function test_recap_requires_active_semester_when_not_given(): void
    {
        $this->actingAsRole();
        $world = $this->world();
        $student = $world['students'][0];

        Semester::query()->update(['is_active' => false]);

        $this->getJson("/api/v1/attendance-recap/student/{$student->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('semester_id');
    }

    // -----------------------------------------------------------------
    // Presensi pegawai
    // -----------------------------------------------------------------

    public function test_employee_attendance_is_upserted(): void
    {
        $this->actingAsRole();
        $teacher = $this->makeTeacher();

        $payload = [
            'employee_id' => $teacher->id,
            'date' => '2026-09-10',
            'check_in' => '07:00',
            'attendance_status_id' => $this->statusId('H'),
        ];

        $this->postJson('/api/v1/employee-attendances', $payload)->assertCreated();

        $this->postJson('/api/v1/employee-attendances', array_merge($payload, ['check_out' => '15:30']))
            ->assertOk()
            ->assertJsonPath('data.check_out', '15:30');

        $this->assertDatabaseCount('employee_attendances', 1);
    }

    public function test_employee_recap_counts_statuses(): void
    {
        $this->actingAsRole();
        $teacher = $this->makeTeacher();

        EmployeeAttendance::create([
            'employee_id' => $teacher->id,
            'date' => '2026-09-08',
            'attendance_status_id' => $this->statusId('H'),
        ]);
        EmployeeAttendance::create([
            'employee_id' => $teacher->id,
            'date' => '2026-09-09',
            'attendance_status_id' => $this->statusId('T'),
        ]);

        $this->getJson('/api/v1/employee-attendances/recap?'.http_build_query([
            'employee_id' => $teacher->id,
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.present', 1)
            ->assertJsonPath('data.items.0.late', 1)
            ->assertJsonPath('data.items.0.total_days', 2);
    }

    // -----------------------------------------------------------------
    // Koreksi presensi
    // -----------------------------------------------------------------

    public function test_correction_request_creates_pending_row(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('A'),
        ]);

        $this->postJson('/api/v1/attendance-corrections', [
            'student_attendance_id' => $attendance->id,
            'new_status_id' => $this->statusId('H'),
            'reason' => 'Siswa sebenarnya hadir, salah tandai.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', AttendanceCorrection::STATUS_PENDING)
            ->assertJsonPath('data.old_status_id', $this->statusId('A'));
    }

    public function test_correction_requires_reason(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('A'),
        ]);

        $this->postJson('/api/v1/attendance-corrections', [
            'student_attendance_id' => $attendance->id,
            'new_status_id' => $this->statusId('H'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_second_pending_correction_is_rejected(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('A'),
        ]);

        $body = [
            'student_attendance_id' => $attendance->id,
            'new_status_id' => $this->statusId('H'),
            'reason' => 'Salah tandai.',
        ];

        $this->postJson('/api/v1/attendance-corrections', $body)->assertCreated();

        $this->postJson('/api/v1/attendance-corrections', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_attendance_id');
    }

    public function test_correction_with_same_status_is_rejected(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('H'),
        ]);

        $this->postJson('/api/v1/attendance-corrections', [
            'student_attendance_id' => $attendance->id,
            'new_status_id' => $this->statusId('H'),
            'reason' => 'Tidak ada perubahan.',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('new_status_id');
    }

    public function test_approving_correction_applies_new_status(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('A'),
        ]);

        $correction = AttendanceCorrection::create([
            'student_attendance_id' => $attendance->id,
            'old_status_id' => $this->statusId('A'),
            'new_status_id' => $this->statusId('H'),
            'reason' => 'Siswa hadir.',
            'status' => AttendanceCorrection::STATUS_PENDING,
        ]);

        $this->postJson("/api/v1/attendance-corrections/{$correction->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', AttendanceCorrection::STATUS_APPROVED);

        $this->assertDatabaseHas('student_attendances', [
            'id' => $attendance->id,
            'attendance_status_id' => $this->statusId('H'),
        ]);
    }

    public function test_rejecting_correction_leaves_attendance_unchanged(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('A'),
        ]);

        $correction = AttendanceCorrection::create([
            'student_attendance_id' => $attendance->id,
            'old_status_id' => $this->statusId('A'),
            'new_status_id' => $this->statusId('H'),
            'reason' => 'Siswa hadir.',
            'status' => AttendanceCorrection::STATUS_PENDING,
        ]);

        $this->postJson("/api/v1/attendance-corrections/{$correction->id}/reject", [
            'reason' => 'Bukti tidak cukup.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', AttendanceCorrection::STATUS_REJECTED);

        $this->assertDatabaseHas('student_attendances', [
            'id' => $attendance->id,
            'attendance_status_id' => $this->statusId('A'),
        ]);
    }

    public function test_reject_requires_reason(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('A'),
        ]);

        $correction = AttendanceCorrection::create([
            'student_attendance_id' => $attendance->id,
            'old_status_id' => $this->statusId('A'),
            'new_status_id' => $this->statusId('H'),
            'reason' => 'Siswa hadir.',
            'status' => AttendanceCorrection::STATUS_PENDING,
        ]);

        $this->postJson("/api/v1/attendance-corrections/{$correction->id}/reject")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_processed_correction_cannot_be_processed_again(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('A'),
        ]);

        $correction = AttendanceCorrection::create([
            'student_attendance_id' => $attendance->id,
            'old_status_id' => $this->statusId('A'),
            'new_status_id' => $this->statusId('H'),
            'reason' => 'Siswa hadir.',
            'status' => AttendanceCorrection::STATUS_APPROVED,
        ]);

        $this->postJson("/api/v1/attendance-corrections/{$correction->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_correction_list_can_be_filtered_by_status(): void
    {
        $this->actingAsRole();
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('A'),
        ]);

        AttendanceCorrection::create([
            'student_attendance_id' => $attendance->id,
            'old_status_id' => $this->statusId('A'),
            'new_status_id' => $this->statusId('H'),
            'reason' => 'Siswa hadir.',
            'status' => AttendanceCorrection::STATUS_PENDING,
        ]);

        $this->getJson('/api/v1/attendance-corrections?filter[status]=pending')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/attendance-corrections?filter[status]=approved')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    // -----------------------------------------------------------------
    // RBAC
    // -----------------------------------------------------------------

    public function test_guru_can_record_attendance(): void
    {
        $this->actingAsRole('guru');
        $world = $this->world();

        $this->postJson('/api/v1/student-attendances', $this->payload($world))->assertCreated();
    }

    public function test_guru_cannot_approve_correction(): void
    {
        $this->actingAsRole('guru');
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('A'),
        ]);

        $correction = AttendanceCorrection::create([
            'student_attendance_id' => $attendance->id,
            'old_status_id' => $this->statusId('A'),
            'new_status_id' => $this->statusId('H'),
            'reason' => 'Siswa hadir.',
            'status' => AttendanceCorrection::STATUS_PENDING,
        ]);

        $this->postJson("/api/v1/attendance-corrections/{$correction->id}/approve")->assertForbidden();
    }

    public function test_wali_kelas_can_approve_correction(): void
    {
        $this->actingAsRole('wali-kelas');
        $world = $this->world();

        $attendance = StudentAttendance::create([
            'student_id' => $world['students'][0]->id,
            'school_class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'attendance_status_id' => $this->statusId('A'),
        ]);

        $correction = AttendanceCorrection::create([
            'student_attendance_id' => $attendance->id,
            'old_status_id' => $this->statusId('A'),
            'new_status_id' => $this->statusId('H'),
            'reason' => 'Siswa hadir.',
            'status' => AttendanceCorrection::STATUS_PENDING,
        ]);

        $this->postJson("/api/v1/attendance-corrections/{$correction->id}/approve")->assertOk();
    }

    public function test_siswa_cannot_record_attendance(): void
    {
        $this->actingAsRole('siswa');
        $world = $this->world();

        $this->postJson('/api/v1/student-attendances', $this->payload($world))->assertForbidden();
    }

    public function test_siswa_can_view_recap(): void
    {
        $world = $this->world();

        // Akun siswa harus tertaut ke data siswa agar pagar kepemilikan
        // (RestrictFamilyStudentAccess) mengenalinya sebagai dirinya sendiri.
        $siswa = $this->actingAsRole('siswa');
        $siswa->update([
            'userable_type' => $world['students'][0]->getMorphClass(),
            'userable_id' => $world['students'][0]->id,
        ]);

        $this->getJson("/api/v1/attendance-recap/student/{$world['students'][0]->id}?semester_id={$world['semester']->id}")
            ->assertOk();
    }

    public function test_guest_cannot_access_attendance(): void
    {
        $this->getJson('/api/v1/student-attendances')->assertUnauthorized();
        $this->postJson('/api/v1/student-attendances/bulk', [])->assertUnauthorized();
        $this->getJson('/api/v1/attendance-corrections')->assertUnauthorized();
    }
}
