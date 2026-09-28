<?php

namespace Tests\Feature\Lms;

use App\Models\ClassStudent;
use App\Models\Student;
use App\Modules\LMS\Models\LmsEnrollment;

/** §44 Integration: SIAKAD → Course → Enrollment → LMS. */
class LmsIntegrationTest extends LmsTestCase
{
    public function test_sinkronisasi_akademik_mendaftarkan_seluruh_siswa_rombel_tanpa_input_satu_per_satu(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->makeCourse($world);

        $response = $this->postJson("/api/v1/lms/courses/{$course->id}/enrollments/sync-academic")
            ->assertOk()
            ->assertJsonPath('data.learners_found', 3)
            ->assertJsonPath('data.created', 3);

        $this->assertSame(3, LmsEnrollment::query()->where('course_id', $course->id)->where('role', 'STUDENT')->count());
        $this->assertSame(3, (int) $response->json('data.total_enrolled'));

        // Semua enrollment menyimpan asal-usulnya agar dapat diaudit (§17).
        $this->assertDatabaseHas('lms_enrollments', [
            'course_id' => $course->id,
            'user_id' => $world['studentUsers'][0]->id,
            'student_id' => $world['students'][0]->id,
            'enrollment_method' => 'ACADEMIC',
        ]);

        // Guru pengampu dari `class_subjects` ikut ditetapkan sebagai pengajar.
        $this->assertDatabaseHas('lms_course_teachers', [
            'course_id' => $course->id,
            'employee_id' => $world['teacher']->id,
        ]);
    }

    public function test_siswa_yang_keluar_dari_rombel_ditangguhkan_bukan_dihapus(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->makeCourse($world);

        $this->postJson("/api/v1/lms/courses/{$course->id}/enrollments/sync-academic")->assertOk();

        // Satu siswa dipindahkan keluar rombel (mis. KRS dibatalkan).
        ClassStudent::query()
            ->where('student_id', $world['students'][2]->id)
            ->delete();

        $this->postJson("/api/v1/lms/courses/{$course->id}/enrollments/sync-academic")->assertOk();

        $enrollment = LmsEnrollment::query()
            ->where('course_id', $course->id)
            ->where('user_id', $world['studentUsers'][2]->id)
            ->firstOrFail();

        $this->assertSame('SUSPENDED', $enrollment->status->value);
        $this->assertNotNull($enrollment->suspended_at);
        $this->assertNull($enrollment->deleted_at);
        $this->assertSame('Tidak lagi terdaftar pada rombel sumber SIAKAD.', $enrollment->metadata['suspended_reason']);
    }

    public function test_enrollment_manual_tidak_diturunkan_oleh_sinkronisasi_akademik(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->makeCourse($world);

        $manual = LmsEnrollment::create([
            'course_id' => $course->id,
            'user_id' => $world['studentUsers'][0]->id,
            'student_id' => $world['students'][0]->id,
            'role' => 'STUDENT',
            'status' => 'ACTIVE',
            'enrollment_method' => 'MANUAL',
            'enrolled_at' => now(),
        ]);

        $this->postJson("/api/v1/lms/courses/{$course->id}/enrollments/sync-academic")->assertOk();

        // Status manual tetap MANUAL: sinkronisasi tidak menurunkannya (§17).
        $this->assertSame('MANUAL', $manual->fresh()->enrollment_method->value);
        $this->assertSame('ACTIVE', $manual->fresh()->status->value);
    }

    public function test_guru_melihat_daftar_mapel_yang_belum_memiliki_course_lms(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);

        $before = $this->getJson('/api/v1/lms/dashboard/teacher')->assertOk();
        $this->assertFalse($before->json('data.academic_coverage.0.has_lms_course'));

        // Setelah course dibuat untuk kelas & mapel tersebut, cakupannya terpenuhi.
        $this->actingAsRole('admin-sekolah');
        $course = $this->makeCourse($world);

        $this->actingAsTeacher($world['teacher']);
        $after = $this->getJson('/api/v1/lms/dashboard/teacher')->assertOk();

        $this->assertTrue($after->json('data.academic_coverage.0.has_lms_course'));
        $this->assertSame($course->id, $after->json('data.academic_coverage.0.lms_course_id'));
    }

    public function test_siswa_tanpa_akun_dilewati_dengan_alasan_yang_dicatat(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->makeCourse($world);

        $orphan = Student::create(['nis' => '20269001', 'full_name' => 'Belum Berakun', 'is_active' => true]);
        ClassStudent::create([
            'school_class_id' => $world['class']->id,
            'student_id' => $orphan->id,
            'academic_year_id' => $world['year']->id,
            'status' => 'aktif',
        ]);

        $response = $this->postJson("/api/v1/lms/courses/{$course->id}/enrollments/sync-academic")->assertOk();

        $this->assertSame(3, $response->json('data.created'));
        $this->assertSame('Belum memiliki akun pengguna.', $response->json('data.skipped.0.reason'));
    }

    public function test_status_integrasi_dilaporkan_pada_pengaturan_lms(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->getJson('/api/v1/lms/settings')
            ->assertOk()
            ->assertJsonPath('data.integration.mode', 'integrated')
            ->assertJsonPath('data.integration.standalone', false)
            ->assertJsonPath('data.integration.academic_source', 'SIAKAD / ERP');
    }
}
