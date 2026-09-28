<?php

namespace Tests\Feature\Lms;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\Guardian;
use App\Models\PpdbParentAccount;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Modules\LMS\Database\Seeders\LmsPermissionSeeder;
use App\Modules\LMS\Models\LmsCategory;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLearningItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dasar pengujian modul LMS (§44).
 *
 * Menyediakan dunia SIAKAD minimum (tahun ajaran, rombel, mapel, guru, siswa,
 * wali) plus helper untuk berpindah peran — sehingga setiap berkas tes cukup
 * menulis skenario yang ingin diuji.
 */
abstract class LmsTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(LmsPermissionSeeder::class);
    }

    /** Akun ERP dengan peran tertentu, langsung terautentikasi. */
    protected function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Akun siswa yang tertaut ke data `students`.
     *
     * Bila akun siswa sudah dibuat `world()` (dan sudah punya enrollment), akun
     * itu dipakai ulang — bukan membuat pengguna baru — supaya tes benar-benar
     * menguji "siswa yang sama".
     */
    protected function actingAsStudent(Student $student, ?User $user = null, string $role = 'siswa'): User
    {
        $user ??= User::factory()->create(['is_active' => true]);
        $user->forceFill(['userable_type' => Student::class, 'userable_id' => $student->id])->save();
        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Akun guru yang tertaut ke data `employees`. */
    protected function actingAsTeacher(Employee $employee, string $role = 'guru'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['userable_type' => Employee::class, 'userable_id' => $employee->id])->save();
        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Akun wali murid yang tertaut ke seorang `guardians`. */
    protected function actingAsParent(Guardian $guardian, string $role = 'wali-murid'): User
    {
        $user = User::factory()->create(['is_active' => true, 'email' => 'wali'.uniqid().'@syntaf.sch.id']);
        $user->syncRoles([$role]);

        PpdbParentAccount::create([
            'user_id' => $user->id,
            'guardian_id' => $guardian->id,
            'account_status' => 'active',
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Dunia SIAKAD minimum: 1 rombel, 1 mapel, 1 guru pengampu, 3 siswa berakun.
     *
     * @return array{
     *     year: AcademicYear, semester: Semester, class: SchoolClass, subject: Subject,
     *     teacher: Employee, students: list<Student>, studentUsers: list<User>
     * }
     */
    protected function world(int $studentCount = 3): array
    {
        $year = AcademicYear::create([
            'name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        $semester = Semester::create([
            'academic_year_id' => $year->id, 'name' => 'Ganjil',
            'start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'is_active' => true,
        ]);

        $class = SchoolClass::create([
            'academic_year_id' => $year->id, 'name' => 'X IPA 1', 'code' => 'X-IPA-1',
            'capacity' => 32, 'is_active' => true,
        ]);

        $subject = Subject::create([
            'code' => 'MAT101', 'name' => 'Matematika Dasar', 'category' => 'Wajib', 'credit' => 4, 'is_active' => true,
        ]);

        $teacher = Employee::create([
            'nip' => '198001012026011001', 'full_name' => 'Ahmad Fauzi, S.Pd.',
            'is_teacher' => true, 'is_active' => true, 'email' => 'ahmad@syntaf.sch.id',
        ]);

        ClassSubject::create([
            'school_class_id' => $class->id, 'subject_id' => $subject->id,
            'employee_id' => $teacher->id, 'academic_year_id' => $year->id,
        ]);

        $students = [];
        $studentUsers = [];

        for ($i = 1; $i <= $studentCount; $i++) {
            $student = Student::create([
                'nis' => '2026'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'full_name' => 'Siswa '.$i,
                'is_active' => true,
            ]);

            ClassStudent::create([
                'school_class_id' => $class->id, 'student_id' => $student->id,
                'academic_year_id' => $year->id, 'status' => 'aktif',
            ]);

            $user = User::factory()->create(['is_active' => true, 'name' => 'Siswa '.$i]);
            $user->forceFill(['userable_type' => Student::class, 'userable_id' => $student->id])->save();
            $user->syncRoles(['siswa']);

            $students[] = $student;
            $studentUsers[] = $user;
        }

        return compact('year', 'semester', 'class', 'subject', 'teacher', 'students', 'studentUsers');
    }

    protected function makeCategory(array $attributes = []): LmsCategory
    {
        return LmsCategory::create([
            'name' => $attributes['name'] ?? 'Matematika',
            'slug' => $attributes['slug'] ?? 'matematika-'.uniqid(),
            'status' => 'ACTIVE',
            'visibility' => 'PUBLIC',
            ...$attributes,
        ]);
    }

    /**
     * Course siap belajar: punya section, satu materi halaman, dan pengajar.
     */
    protected function makeCourse(array $world, array $attributes = []): LmsCourse
    {
        return LmsCourse::create([
            'name' => $attributes['name'] ?? 'Matematika Dasar',
            'code' => $attributes['code'] ?? 'MAT101-XIPA1-'.strtoupper(uniqid()),
            'slug' => $attributes['slug'] ?? 'matematika-dasar-'.uniqid(),
            'course_type' => 'ACADEMIC',
            'status' => 'DRAFT',
            'visibility' => 'ENROLLED_ONLY',
            'academic_course_id' => $world['subject']->id,
            'class_id' => $world['class']->id,
            'academic_year_id' => $world['year']->id,
            'semester_id' => $world['semester']->id,
            'teacher_id' => $world['teacher']->id,
            ...$attributes,
        ]);
    }

    protected function makeSection(LmsCourse $course, array $attributes = []): LmsCourseSection
    {
        return $course->sections()->create([
            'title' => $attributes['title'] ?? 'Pengenalan Aljabar',
            'sort_order' => $attributes['sort_order'] ?? 1,
            'visibility' => 'VISIBLE',
            ...$attributes,
        ]);
    }

    protected function makePageItem(LmsCourseSection $section, array $attributes = []): LmsLearningItem
    {
        $item = $section->items()->create([
            'course_id' => $section->course_id,
            'title' => $attributes['title'] ?? 'Materi Pengenalan',
            'item_type' => 'RESOURCE',
            'resource_type' => 'PAGE',
            'completion_type' => 'MANUAL',
            'is_required' => true,
            'visibility' => 'VISIBLE',
            'sort_order' => $attributes['sort_order'] ?? 1,
            ...$attributes,
        ]);

        $item->resource()->create(['content' => '<p>Isi materi</p>']);

        return $item->fresh(['resource']);
    }

    protected function enroll(LmsCourse $course, User $user, array $attributes = []): LmsEnrollment
    {
        return LmsEnrollment::create([
            'course_id' => $course->id,
            'user_id' => $user->id,
            'role' => 'STUDENT',
            'status' => 'ACTIVE',
            'enrollment_method' => 'MANUAL',
            'enrolled_at' => now(),
            ...$attributes,
        ]);
    }

    /** Publikasikan course lengkap agar alur belajar dapat diuji. */
    protected function publishCourse(LmsCourse $course, array $world): LmsCourse
    {
        $section = $this->makeSection($course);
        $this->makePageItem($section);

        $course->teachers()->create([
            'employee_id' => $world['teacher']->id,
            'name' => $world['teacher']->full_name,
            'role' => 'TEACHER',
            'is_primary' => true,
        ]);

        $course->forceFill([
            'status' => 'PUBLISHED',
            'published_at' => now(),
            'short_description' => 'Course matematika dasar untuk kelas X',
        ])->save();

        return $course->fresh();
    }
}
