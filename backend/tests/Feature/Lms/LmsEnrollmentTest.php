<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;

/** §44 Enrollment: manual, academic, bulk, suspend, cancel. */
class LmsEnrollmentTest extends LmsTestCase
{
    public function test_pendaftaran_manual_menambahkan_siswa_ke_course(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->makeCourse($world);

        $this->postJson("/api/v1/lms/courses/{$course->id}/enrollments", [
            'student_id' => $world['students'][0]->id,
            'role' => 'STUDENT',
        ])->assertCreated()
            ->assertJsonPath('data.role', 'STUDENT')
            ->assertJsonPath('data.status', 'ACTIVE');

        $this->assertDatabaseHas('lms_enrollments', [
            'course_id' => $course->id,
            'user_id' => $world['studentUsers'][0]->id,
            'student_id' => $world['students'][0]->id,
            'enrollment_method' => 'MANUAL',
        ]);

        // Progress awal dibuat 0% (belum ada item tuntas).
        $this->assertDatabaseHas('lms_course_progress', [
            'course_id' => $course->id,
            'user_id' => $world['studentUsers'][0]->id,
            'completed_items' => 0,
        ]);
    }

    public function test_pendaftaran_massal_melewati_siswa_tanpa_akun(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $withoutAccount = \App\Models\Student::create([
            'nis' => '20269999', 'full_name' => 'Siswa Tanpa Akun', 'is_active' => true,
        ]);

        $response = $this->postJson("/api/v1/lms/courses/{$course->id}/enrollments/bulk", [
            'student_ids' => [$world['students'][0]->id, $world['students'][1]->id, $withoutAccount->id],
        ])->assertOk();

        $this->assertSame(2, $response->json('data.created'));
        $this->assertCount(1, $response->json('data.skipped'));
        $this->assertSame(2, LmsEnrollment::query()->where('course_id', $course->id)->count());
    }

    public function test_penangguhan_dan_pembatalan_menjaga_riwayat_belajar(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $enrollment = $this->enroll($course, $world['studentUsers'][0], ['student_id' => $world['students'][0]->id]);

        $this->postJson("/api/v1/lms/enrollments/{$enrollment->id}/suspend", ['reason' => 'KRS dibatalkan'])
            ->assertOk()->assertJsonPath('data.status', 'SUSPENDED');

        $this->assertDatabaseHas('lms_enrollments', ['id' => $enrollment->id, 'status' => 'SUSPENDED']);
        $this->assertNull($enrollment->fresh()->deleted_at);

        $this->postJson("/api/v1/lms/enrollments/{$enrollment->id}/reactivate")
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');

        $this->postJson("/api/v1/lms/enrollments/{$enrollment->id}/cancel", ['reason' => 'Pindah kelas'])
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        // Riwayat tetap ada meski keikutsertaan dibatalkan (§17).
        $this->assertDatabaseHas('lms_enrollments', ['id' => $enrollment->id, 'status' => 'CANCELLED']);
    }

    public function test_siswa_dapat_mendaftar_sendiri_hanya_pada_course_non_akademik_publik(): void
    {
        $world = $this->world();
        $student = $world['students'][0];

        $open = $this->makeCourse($world, [
            'name' => 'Kelas Publik', 'course_type' => 'GENERAL', 'visibility' => 'PUBLIC', 'status' => 'PUBLISHED',
            'class_id' => null, 'academic_course_id' => null,
        ]);

        $this->actingAsStudent($student, $world['studentUsers'][0]);

        $this->postJson("/api/v1/lms/courses/{$open->id}/enroll")
            ->assertCreated()
            ->assertJsonPath('data.enrollment_method', 'SELF');

        // Course akademik tetap ditentukan SIAKAD.
        $academic = $this->makeCourse($world, ['name' => 'Akademik', 'visibility' => 'PUBLIC', 'status' => 'PUBLISHED']);
        $this->postJson("/api/v1/lms/courses/{$academic->id}/enroll")->assertStatus(422);
    }

    public function test_kuota_peserta_dihormati(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->makeCourse($world, ['max_students' => 1]);

        $this->postJson("/api/v1/lms/courses/{$course->id}/enrollments", ['student_id' => $world['students'][0]->id])
            ->assertCreated();

        $this->postJson("/api/v1/lms/courses/{$course->id}/enrollments", ['student_id' => $world['students'][1]->id])
            ->assertUnprocessable();
    }

    public function test_daftar_peserta_menampilkan_ringkasan_status(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);

        $this->getJson("/api/v1/lms/courses/{$course->id}/enrollments")
            ->assertOk()
            ->assertJsonPath('data.summary.students', 1)
            ->assertJsonPath('data.summary.active', 1);
    }
}
