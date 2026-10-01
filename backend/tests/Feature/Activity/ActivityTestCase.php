<?php

namespace Tests\Feature\Activity;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Modules\Activity\Database\Seeders\ActivityMasterSeeder;
use App\Modules\Activity\Database\Seeders\ActivityPermissionSeeder;
use App\Modules\Activity\Models\ActivityCategory;
use App\Modules\Activity\Models\ActivityPeriod;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivitySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dasar pengujian Modul Student Activity.
 *
 * Menyiapkan dunia kecil yang menyerupai sekolah sebenarnya: satu tahun ajaran,
 * dua kelas, dua siswa dengan wali masing-masing, satu pembina per program,
 * satu lapangan, dan satu periode aktivitas berjalan.
 *
 * Dua siswa sengaja dibuat agar setiap uji cakupan baris benar-benar menguji
 * "pelatih A vs anak B" dan "orang tua A vs anak B" — bukan sekadar peran
 * tanpa tetangga.
 */
abstract class ActivityTestCase extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    protected const ROLES = [
        'super-admin', 'ERP_ADMIN', 'admin-sekolah', 'kepala-sekolah', 'guru', 'wali-kelas', 'staf',
        'siswa', 'wali-murid', 'bendahara', 'petugas-inventaris', 'petugas-sarpras', 'auditor',
        'ACTIVITY_ADMIN', 'ACTIVITY_MANAGER', 'ACTIVITY_COORDINATOR', 'ACTIVITY_ADVISOR',
        'COACH', 'MENTOR', 'ACTIVITY_JUDGE',
    ];

    protected AcademicYear $year;

    protected Semester $semester;

    protected ActivityPeriod $period;

    protected Room $field;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->seed(ActivityPermissionSeeder::class);
        $this->seed(ActivityMasterSeeder::class);

        $this->year = AcademicYear::query()->create([
            'name' => '2026/2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        $this->semester = Semester::query()->create([
            'academic_year_id' => $this->year->id,
            'name' => 'Ganjil',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);

        // Seeder master membuat periode dari tahun ajaran aktif; pastikan ada.
        $this->period = ActivityPeriod::query()->firstOrCreate(
            ['code' => 'Ganjil'],
            [
                'name' => '2026/2027 Ganjil',
                'type' => 'SEMESTER',
                'academic_year_id' => $this->year->id,
                'semester_id' => $this->semester->id,
                'start_date' => '2026-07-01',
                'end_date' => '2026-12-31',
                'status' => 'ACTIVE',
                'is_current' => true,
            ],
        );

        $this->field = Room::query()->create([
            'code' => 'LAP-1',
            'name' => 'Lapangan Futsal',
            'type' => 'Lainnya', // master `rooms` membatasi tipe: Kelas/Lab/Perpustakaan/Kantor/Lainnya
            'capacity' => 22,
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------ pengguna

    protected function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function accountFor(string $role, object $entity, array $attributes = []): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'userable_type' => $entity->getMorphClass(),
            'userable_id' => $entity->id,
            ...$attributes,
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    protected function actAs(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array{user: User, employee: Employee} */
    protected function makeCoach(string $name = 'Coach'): array
    {
        $employee = Employee::query()->create([
            'full_name' => $name.' '.random_int(100, 999),
            'employee_type' => 'guru',
            'is_teacher' => true,
            'is_active' => true,
        ]);

        $user = $this->accountFor('COACH', $employee);

        return ['user' => $user, 'employee' => $employee];
    }

    // ---------------------------------------------------------------- siswa

    protected function makeClass(string $name = 'VII A'): SchoolClass
    {
        return SchoolClass::query()->create([
            'academic_year_id' => $this->year->id,
            'name' => $name,
            'code' => 'K'.random_int(10000, 99999),
            'is_active' => true,
        ]);
    }

    protected function makeStudent(string $name, ?SchoolClass $class = null): Student
    {
        $student = Student::query()->create([
            'nis' => 'NIS'.random_int(100000, 999999),
            'full_name' => $name,
            'is_active' => true,
        ]);

        if ($class !== null) {
            ClassStudent::query()->create([
                'school_class_id' => $class->id,
                'student_id' => $student->id,
                'academic_year_id' => $class->academic_year_id,
                'status' => 'aktif',
            ]);
        }

        return $student;
    }

    /**
     * Siswa + wali + akun keduanya.
     *
     * @return array{student: Student, guardian: Guardian, guardian_user: User, student_user: ?User}
     */
    protected function makeStudentWithGuardian(string $name, ?SchoolClass $class = null, bool $withStudentAccount = false): array
    {
        $student = $this->makeStudent($name, $class ?? $this->makeClass());
        $guardian = Guardian::query()->create([
            'full_name' => 'Wali '.$name,
            'phone' => '0812'.random_int(10000000, 99999999),
        ]);

        StudentGuardian::query()->create([
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'is_primary' => true,
        ]);

        return [
            'student' => $student,
            'guardian' => $guardian,
            'guardian_user' => $this->accountFor('wali-murid', $guardian),
            'student_user' => $withStudentAccount ? $this->accountFor('siswa', $student) : null,
        ];
    }

    // -------------------------------------------------------------- program

    protected function makeProgram(array $attributes = []): ActivityProgram
    {
        $category = ActivityCategory::query()->where('code', $attributes['category_code'] ?? 'SPORT')->firstOrFail();

        return ActivityProgram::query()->create(array_merge([
            'code' => 'PRG-'.random_int(1000, 9999),
            'name' => 'Futsal SMP',
            'category_id' => $category->id,
            'period_id' => $this->period->id,
            'type' => 'EXTRACURRICULAR',
            'delivery_type' => 'RECURRING',
            'status' => 'PUBLISHED',
            'selection_method' => 'OPEN',
            'minimum_capacity' => 5,
            'maximum_capacity' => 20,
            'requires_parent_consent' => false,
            'requires_payment' => false,
            'published_at' => now(),
            'starts_on' => '2026-07-15',
            'ends_on' => '2026-12-15',
        ], $attributes));
    }

    protected function makeSchedule(ActivityProgram $program, array $attributes = []): ActivitySchedule
    {
        return ActivitySchedule::query()->create(array_merge([
            'program_id' => $program->id,
            'period_id' => $program->period_id,
            'day_of_week' => 1,
            'start_time' => '15:00',
            'end_time' => '17:00',
            'status' => 'ACTIVE',
            'is_recurring' => true,
            'effective_start' => $program->starts_on?->toDateString(),
            'effective_end' => $program->ends_on?->toDateString(),
        ], $attributes));
    }

    protected function seedAcademicSchedule(int $classId, string $day, string $start, string $end): void
    {
        $dayId = DB::table('days')->insertGetId(['name' => $day]);
        $subjectId = DB::table('subjects')->insertGetId([
            'code' => 'MTK'.random_int(100, 999),
            'name' => 'Matematika '.random_int(1, 999),
            'is_active' => true,
        ]);

        DB::table('schedules')->insert([
            'school_class_id' => $classId,
            'subject_id' => $subjectId,
            'day_id' => $dayId,
            'start_time' => $start,
            'end_time' => $end,
            'semester_id' => $this->semester->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function seedBoardingObligation(int $dayOfWeek, string $start, string $end, string $name): void
    {
        $activityId = DB::table('boarding_activities')->insertGetId([
            'code' => 'BAR'.random_int(100, 999),
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('boarding_activity_schedules')->insert([
            'activity_id' => $activityId,
            'day_of_week' => $dayOfWeek,
            'start_time' => $start,
            'end_time' => $end,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
