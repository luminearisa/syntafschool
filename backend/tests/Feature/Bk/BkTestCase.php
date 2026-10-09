<?php

namespace Tests\Feature\Bk;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\Employee;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Application\Services\BkAssignmentService;
use App\Modules\Bk\Application\Services\BkCaseService;
use App\Modules\Bk\Application\Services\BkReferralService;
use App\Modules\Bk\Database\Seeders\BkMasterSeeder;
use App\Modules\Bk\Database\Seeders\BkPermissionSeeder;
use App\Modules\Bk\Domain\Enums\CaseType;
use App\Modules\Bk\Domain\Enums\Priority;
use App\Modules\Bk\Domain\Enums\ReferralCategory;
use App\Modules\Bk\Domain\Enums\ReferralSource;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCounselor;
use App\Modules\Bk\Models\BkReferral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dasar pengujian Modul BK.
 *
 * Dunia kecil yang realistis: dua siswa beserta wali berakun, satu kelas dengan
 * wali kelas, seorang koordinator BK, dua konselor (satu penuh, satu lapang),
 * seorang guru mata pelajaran, dan seorang siswa berakun. Dua siswa sengaja
 * dibuat agar setiap uji privasi benar-benar menguji "wali A vs anak B".
 */
abstract class BkTestCase extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    protected const ROLES = [
        'super-admin', 'admin-sekolah', 'kepala-sekolah', 'guru', 'wali-kelas', 'staf',
        'siswa', 'wali-murid', 'auditor', 'pengasuh-asrama', 'petugas-kesehatan',
        'BK_ADMIN', 'BK_COORDINATOR', 'COUNSELOR', 'BOARDING_STAFF',
    ];

    protected AcademicYear $year;

    protected Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->seed(BkPermissionSeeder::class);
        $this->seed(BkMasterSeeder::class);

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
    }

    // ------------------------------------------------------------ pengguna

    protected function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    protected function actAs(User $user): User
    {
        Sanctum::actingAs($user, ['*']);

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

    /** Konselor: akun + baris `bk_counselors` (dibutuhkan penugasan & kapasitas). */
    protected function makeCounselor(string $role = 'COUNSELOR', int $maxCases = 25): User
    {
        $employee = Employee::query()->create([
            'full_name' => 'Konselor '.random_int(100, 999),
            'employee_type' => 'staf',
            'is_teacher' => false,
            'is_active' => true,
        ]);

        $user = $this->accountFor($role, $employee, ['name' => $employee->full_name]);

        BkCounselor::query()->create([
            'user_id' => $user->id,
            'level' => $role === 'BK_COORDINATOR' ? 'COORDINATOR' : 'COUNSELOR',
            'max_active_cases' => $maxCases,
            'status' => 'ACTIVE',
        ]);

        return $user;
    }

    // -------------------------------------------------------------- siswa

    protected function makeClass(?Employee $homeroom = null): SchoolClass
    {
        return SchoolClass::query()->create([
            'academic_year_id' => $this->year->id,
            'name' => 'VIII '.random_int(1, 9),
            'code' => 'K'.random_int(10000, 99999),
            'homeroom_teacher_id' => $homeroom?->id,
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

    /** @return array{student: Student, class: SchoolClass, guardian: Guardian, guardian_user: User, student_user: User} */
    protected function makeStudentWithFamily(string $name, ?SchoolClass $class = null): array
    {
        $class ??= $this->makeClass();
        $student = $this->makeStudent($name, $class);

        $guardian = Guardian::query()->create([
            'full_name' => 'Wali '.$name,
            'phone' => '0812'.random_int(10000000, 99999999),
        ]);

        \Illuminate\Support\Facades\DB::table('student_guardians')->insert([
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'student' => $student,
            'class' => $class,
            'guardian' => $guardian,
            'guardian_user' => $this->accountFor('wali-murid', $guardian),
            'student_user' => $this->accountFor('siswa', $student),
        ];
    }

    // -------------------------------------------------------------- kasus

    protected function makeReferral(User $actor, Student $student, array $attributes = []): BkReferral
    {
        return app(BkReferralService::class)->create($actor, array_merge([
            'student_id' => $student->id,
            'source_type' => ReferralSource::HOMEROOM->value,
            'category' => ReferralCategory::ACADEMIC->value,
            'priority' => Priority::NORMAL->value,
            'description' => 'Nilai menurun sejak dua bulan terakhir dan sering tidak mengumpulkan tugas.',
        ], $attributes));
    }

    protected function makeCase(User $actor, Student $student, array $attributes = []): BkCase
    {
        $referral = $this->makeReferral($actor, $student);

        $case = app(BkReferralService::class)->openCase($actor, $referral, array_merge([
            'case_type' => CaseType::INDIVIDUAL_COUNSELING->value,
        ], $attributes));

        // Konselor yang membuka kasus langsung menjadi penanggung jawabnya.
        if (BkCounselor::query()->where('user_id', $actor->id)->exists()) {
            $case = app(BkAssignmentService::class)->assign($actor, $case, (int) $actor->id);
        }

        return $case;
    }

    /** Tugaskan kasus kepada konselor melalui layanan penugasan. */
    protected function assignCase(User $actor, BkCase $case, User $counselor): BkCase
    {
        return app(BkAssignmentService::class)->assign($actor, $case, (int) $counselor->id);
    }

    protected function caseService(): BkCaseService
    {
        return app(BkCaseService::class);
    }

    protected function referralService(): BkReferralService
    {
        return app(BkReferralService::class);
    }
}
