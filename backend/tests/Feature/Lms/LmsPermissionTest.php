<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Models\LmsCourse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** §35 Permission granular + §36 jejak audit. */
class LmsPermissionTest extends LmsTestCase
{
    public function test_permission_lms_dibuat_dan_dibagikan_ke_peran_erp(): void
    {
        foreach ([
            'lms.view', 'lms.manage',
            'lms.course.view', 'lms.course.create', 'lms.course.update', 'lms.course.delete', 'lms.course.publish',
            'lms.enrollment.view', 'lms.enrollment.create', 'lms.enrollment.update', 'lms.enrollment.delete',
            'lms.content.view', 'lms.content.create', 'lms.content.update', 'lms.content.delete',
            'lms.category.view', 'lms.category.create', 'lms.category.update', 'lms.category.delete',
            'lms.teacher.manage', 'lms.student.view', 'lms.parent.view',
            'lms.report.view', 'lms.settings.manage',
        ] as $permission) {
            $this->assertTrue(
                Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists(),
                "Permission {$permission} belum dibuat",
            );
        }

        $this->assertTrue(Role::findByName('guru')->hasPermissionTo('lms.course.create'));
        $this->assertTrue(Role::findByName('siswa')->hasPermissionTo('lms.content.view'));
        $this->assertFalse(Role::findByName('siswa')->hasPermissionTo('lms.course.create'));
        $this->assertTrue(Role::findByName('wali-murid')->hasPermissionTo('lms.parent.view'));
        $this->assertFalse(Role::findByName('wali-murid')->hasPermissionTo('lms.content.update'));
        $this->assertTrue(Role::findByName('super-admin')->hasPermissionTo('lms.settings.manage'));
    }

    public function test_peran_tanpa_permission_tulis_tidak_dapat_mengubah_course(): void
    {
        $world = $this->world();
        $course = $this->makeCourse($world);

        // `wali-kelas` hanya memantau: tanpa lms.course.create/update.
        $this->actingAsRole('wali-kelas');

        $this->postJson('/api/v1/lms/courses', ['name' => 'Course Baru'])->assertForbidden();
        $this->putJson("/api/v1/lms/courses/{$course->id}", ['name' => 'Diubah'])->assertForbidden();

        // Namun ia tetap boleh membaca daftar course.
        $this->getJson('/api/v1/lms/courses')->assertOk();
    }

    public function test_siswa_tidak_dapat_menambah_materi_atau_mengelola_enrollment(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections->first();

        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->postJson("/api/v1/lms/sections/{$section->id}/items", [
            'title' => 'Materi Nakal', 'item_type' => 'RESOURCE', 'resource_type' => 'PAGE',
        ])->assertForbidden();

        $this->postJson("/api/v1/lms/courses/{$course->id}/enrollments", [
            'student_id' => $world['students'][1]->id,
        ])->assertForbidden();
    }

    public function test_pengaturan_lms_hanya_untuk_pemegang_lms_settings_manage(): void
    {
        $this->actingAsRole('guru');
        $this->getJson('/api/v1/lms/settings')->assertForbidden();

        $this->actingAsRole('admin-sekolah');
        $this->getJson('/api/v1/lms/settings')->assertOk();
    }

    public function test_jejak_audit_mencatat_pembuatan_penerbitan_dan_penangguhan(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');

        // 1) Course dibuat → event model (LMS_COURSE_CREATED).
        $courseId = (int) $this->postJson('/api/v1/lms/courses', [
            'name' => 'Course Audit', 'short_description' => 'Untuk uji audit',
        ])->assertCreated()->json('data.id');

        $course = LmsCourse::query()->findOrFail($courseId);
        $section = $this->makeSection($course);
        $this->makePageItem($section);
        $course->teachers()->create(['employee_id' => $world['teacher']->id, 'name' => 'Guru', 'is_primary' => true]);

        // 2) Diterbitkan → aksi agregat LMS_COURSE_PUBLISHED (§36).
        $this->postJson("/api/v1/lms/courses/{$courseId}/publish")->assertOk();

        // 3) Peserta ditangguhkan → LMS_ENROLLMENT_SUSPENDED.
        $this->postJson("/api/v1/lms/courses/{$courseId}/enrollments", [
            'student_id' => $world['students'][0]->id,
        ])->assertCreated();

        $enrollmentId = (int) DB::table('lms_enrollments')->where('course_id', $courseId)->value('id');
        $this->postJson("/api/v1/lms/enrollments/{$enrollmentId}/suspend", ['reason' => 'Uji audit'])->assertOk();

        $events = DB::table('activity_log')->where('log_name', 'lms')->pluck('event')->all();

        $this->assertContains('LMS_COURSE_CREATED', $events);
        $this->assertContains('LMS_COURSE_PUBLISHED', $events);
        $this->assertContains('LMS_ENROLLMENT_CREATED', $events);
        $this->assertContains('LMS_ENROLLMENT_SUSPENDED', $events);

        // Subjek audit menunjuk course yang tepat, bukan sekadar nama event.
        $published = DB::table('activity_log')
            ->where('log_name', 'lms')->where('event', 'LMS_COURSE_PUBLISHED')->first();

        $this->assertSame(LmsCourse::class, $published->subject_type);
        $this->assertSame($courseId, (int) $published->subject_id);
    }
}
