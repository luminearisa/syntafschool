<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Domain\Contracts\AcademicCourseProviderInterface;
use App\Modules\LMS\Domain\Contracts\ClassProviderInterface;
use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Contracts\TeacherProviderInterface;
use App\Modules\LMS\Domain\Contracts\UserProviderInterface;
use App\Modules\LMS\Infrastructure\Providers\ErpAcademicCourseProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpClassProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpStudentProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpTeacherProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpUserProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneAcademicCourseProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneClassProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneStudentProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneTeacherProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneUserProvider;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsLearner;
use App\Modules\LMS\Models\LmsUser;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * §44 Standalone Mode: kontrak domain dipenuhi implementasi mandiri sehingga
 * LMS tetap berjalan tanpa SIAKAD (§2).
 */
class LmsStandaloneModeTest extends LmsTestCase
{
    /** Mengganti binding kontrak ke implementasi standalone + config mode. */
    protected function useStandaloneMode(): void
    {
        config(['lms.standalone' => true]);

        foreach ([
            UserProviderInterface::class,
            StudentProviderInterface::class,
            TeacherProviderInterface::class,
            ClassProviderInterface::class,
            AcademicCourseProviderInterface::class,
        ] as $contract) {
            $this->app->forgetInstance($contract);
        }
    }

    public function test_binding_kontrak_berpindah_mengikuti_lms_standalone(): void
    {
        $this->assertInstanceOf(ErpUserProvider::class, $this->app->make(UserProviderInterface::class));
        $this->assertInstanceOf(ErpStudentProvider::class, $this->app->make(StudentProviderInterface::class));
        $this->assertInstanceOf(ErpTeacherProvider::class, $this->app->make(TeacherProviderInterface::class));
        $this->assertInstanceOf(ErpClassProvider::class, $this->app->make(ClassProviderInterface::class));
        $this->assertInstanceOf(ErpAcademicCourseProvider::class, $this->app->make(AcademicCourseProviderInterface::class));

        $this->useStandaloneMode();

        $this->assertInstanceOf(StandaloneUserProvider::class, $this->app->make(UserProviderInterface::class));
        $this->assertInstanceOf(StandaloneStudentProvider::class, $this->app->make(StudentProviderInterface::class));
        $this->assertInstanceOf(StandaloneTeacherProvider::class, $this->app->make(TeacherProviderInterface::class));
        $this->assertInstanceOf(StandaloneClassProvider::class, $this->app->make(ClassProviderInterface::class));
        $this->assertInstanceOf(StandaloneAcademicCourseProvider::class, $this->app->make(AcademicCourseProviderInterface::class));
    }

    public function test_status_mode_standalone_dilaporkan_dan_pendaftaran_mandiri_dibuka(): void
    {
        $this->useStandaloneMode();

        $this->getJson('/api/v1/lms/auth/session')
            ->assertOk()
            ->assertJsonPath('data.mode', 'standalone')
            ->assertJsonPath('data.authenticated', false);

        $this->postJson('/api/v1/lms/auth/register', [
            'name' => 'Guru Mandiri',
            'email' => 'guru@lms.test',
            'username' => 'gurumandiri',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'role' => 'TEACHER',
        ])->assertCreated()
            ->assertJsonPath('data.actor.role', 'TEACHER')
            ->assertJsonPath('data.actor.mode', 'standalone');

        $this->assertDatabaseHas('lms_users', ['email' => 'guru@lms.test', 'role' => 'TEACHER']);
    }

    public function test_login_mandiri_mengembalikan_token_sanctum(): void
    {
        $this->useStandaloneMode();

        LmsUser::create([
            'name' => 'Admin LMS', 'email' => 'admin@lms.test', 'username' => 'adminlms',
            'password' => 'rahasia123', 'role' => 'ADMIN', 'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/lms/auth/login', [
            'login' => 'admin@lms.test', 'password' => 'rahasia123',
        ])->assertOk()
            ->assertJsonPath('data.actor.role', 'ADMIN')
            ->assertJsonPath('data.actor.mode', 'standalone');

        $token = (string) $response->json('data.token');

        // Token yang diterbitkan benar-benar tersimpan sebagai token Sanctum
        // milik `lms_users` — bukan pengguna ERP.
        $issued = PersonalAccessToken::findToken($token);
        $this->assertNotNull($issued);
        $this->assertSame(LmsUser::class, $issued->tokenable_type);

        // Permintaan terautentikasi berikutnya memakai identitas itu
        // (di suite ini via actingAs; token di atas sudah diverifikasi).
        Sanctum::actingAs($issued->tokenable, ['*']);

        $this->getJson('/api/v1/lms/auth/me')
            ->assertOk()
            ->assertJsonPath('data.actor.email', 'admin@lms.test')
            ->assertJsonPath('data.mode', 'standalone');
    }

    public function test_alur_belajar_lengkap_berjalan_hanya_dengan_tabel_lms(): void
    {
        $this->useStandaloneMode();

        $admin = LmsUser::create([
            'name' => 'Admin LMS', 'email' => 'admin@lms.test', 'password' => 'rahasia123',
            'role' => 'ADMIN', 'is_active' => true,
        ]);
        $teacher = LmsUser::create([
            'name' => 'Guru Mandiri', 'email' => 'guru@lms.test', 'password' => 'rahasia123',
            'role' => 'TEACHER', 'is_active' => true,
        ]);
        $teacherProfile = LmsLearner::create([
            'lms_user_id' => $teacher->id, 'learner_type' => 'TEACHER',
            'code' => 'G-001', 'name' => 'Guru Mandiri', 'is_active' => true,
        ]);
        $student = LmsUser::create([
            'name' => 'Siswa Mandiri', 'email' => 'siswa@lms.test', 'password' => 'rahasia123',
            'role' => 'STUDENT', 'is_active' => true,
        ]);
        $studentProfile = LmsLearner::create([
            'lms_user_id' => $student->id, 'learner_type' => 'STUDENT',
            'code' => 'S-001', 'name' => 'Siswa Mandiri', 'class_label' => 'Kelas A', 'is_active' => true,
        ]);

        // Admin membuat kategori & course (tanpa mapel SIAKAD).
        Sanctum::actingAs($admin, ['*']);

        $category = $this->postJson('/api/v1/lms/categories', ['name' => 'Bahasa Inggris'])
            ->assertCreated()->json('data.id');

        $courseId = (int) $this->postJson('/api/v1/lms/courses', [
            'name' => 'English for Beginners',
            'short_description' => 'Kursus bahasa Inggris untuk pemula',
            'category_id' => $category,
            'course_type' => 'GENERAL',
            'visibility' => 'INSTITUTION',
            'teachers' => [['teacher_id' => $teacherProfile->id, 'is_primary' => true]],
        ])->assertCreated()->json('data.id');

        $sectionId = (int) $this->postJson("/api/v1/lms/courses/{$courseId}/sections", [
            'title' => 'Introduction',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/lms/sections/{$sectionId}/items", [
            'title' => 'Greetings', 'item_type' => 'RESOURCE', 'resource_type' => 'PAGE',
            'completion_type' => 'VIEW', 'content' => '<p>Hello!</p>',
        ])->assertCreated();

        $this->postJson("/api/v1/lms/courses/{$courseId}/publish")->assertOk();

        // Guru otomatis terdaftar sebagai pengajar (profile → user).
        $this->assertDatabaseHas('lms_enrollments', [
            'course_id' => $courseId, 'user_id' => $teacher->id, 'role' => 'TEACHER',
        ]);

        // Siswa didaftarkan manual dari direktori `lms_learners`.
        $this->postJson("/api/v1/lms/courses/{$courseId}/enrollments", [
            'student_id' => $studentProfile->id, 'role' => 'STUDENT',
        ])->assertCreated();

        $course = LmsCourse::query()->findOrFail($courseId);
        $item = $course->items()->firstOrFail();

        // Siswa belajar: struktur, buka materi, tandai selesai.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($student, ['*']);

        $this->getJson("/api/v1/lms/courses/{$courseId}/learn")
            ->assertOk()
            ->assertJsonPath('data.progress.enrolled', true);

        $this->postJson("/api/v1/lms/courses/{$courseId}/items/{$item->id}/view")
            ->assertOk()
            ->assertJsonPath('data.auto_completed', true);

        $this->getJson("/api/v1/lms/courses/{$courseId}/progress")
            ->assertOk()
            ->assertJsonPath('data.progress_percent', 100);

        // Integrasi akademik tidak menuntut tabel ERP: opsi kelas berasal dari
        // label kelas pada profil peserta mandiri, dan "course akademik"
        // dilayani LMS sendiri (mapel ERP tidak dipanggil sama sekali).
        $options = $this->app->make(ClassProviderInterface::class)->options();
        $this->assertSame('Kelas A', $options[0]['name']);

        $academics = $this->app->make(AcademicCourseProviderInterface::class)->forStudent($studentProfile->id);
        $this->assertSame('English for Beginners', $academics[0]['academic_course_name']);
        $this->assertNull($this->app->make(AcademicCourseProviderInterface::class)->summary(null, null, null));
    }

    public function test_guest_tetap_hanya_melihat_course_publik_pada_mode_mandiri(): void
    {
        $this->useStandaloneMode();

        $admin = LmsUser::create([
            'name' => 'Admin LMS', 'email' => 'admin@lms.test', 'password' => 'rahasia123',
            'role' => 'ADMIN', 'is_active' => true,
        ]);

        $teacherUser = LmsUser::create([
            'name' => 'Guru Publik', 'email' => 'gurupublik@lms.test', 'password' => 'rahasia123',
            'role' => 'TEACHER', 'is_active' => true,
        ]);
        $teacherProfile = LmsLearner::create([
            'lms_user_id' => $teacherUser->id, 'learner_type' => 'TEACHER',
            'code' => 'G-900', 'name' => 'Guru Publik', 'is_active' => true,
        ]);

        Sanctum::actingAs($admin, ['*']);
        $publicId = (int) $this->postJson('/api/v1/lms/courses', [
            'name' => 'Kursus Publik', 'short_description' => 'Kursus terbuka untuk umum',
            'course_type' => 'GENERAL', 'visibility' => 'PUBLIC',
            'teachers' => [['teacher_id' => $teacherProfile->id, 'is_primary' => true]],
        ])->assertCreated()->json('data.id');

        // Course selalu lahir sebagai DRAFT (§7): terbitkan lebih dulu.
        $sectionId = (int) $this->postJson("/api/v1/lms/courses/{$publicId}/sections", [
            'title' => 'Bab Pembuka',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/lms/sections/{$sectionId}/items", [
            'title' => 'Sapaan', 'item_type' => 'RESOURCE', 'resource_type' => 'PAGE', 'content' => '<p>Hi</p>',
        ])->assertCreated();

        $this->postJson("/api/v1/lms/courses/{$publicId}/publish")->assertOk();

        Sanctum::actingAs($admin, ['*']);
        $privateId = (int) $this->postJson('/api/v1/lms/courses', [
            'name' => 'Kursus Internal', 'course_type' => 'GENERAL', 'visibility' => 'ENROLLED_ONLY',
        ])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/lms/public/courses')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Kursus Publik'])
            ->assertJsonMissing(['name' => 'Kursus Internal']);

        $this->assertSame(0, DB::table('lms_courses')->where('id', $privateId)->where('visibility', 'PUBLIC')->count());
    }
}
