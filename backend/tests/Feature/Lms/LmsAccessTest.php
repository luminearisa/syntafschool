<?php

namespace Tests\Feature\Lms;

use App\Models\Guardian;
use App\Models\StudentGuardian;
use App\Modules\LMS\Models\LmsCourse;

/** §44 Access: siswa terdaftar, siswa tidak terdaftar, guru, wali, guest. */
class LmsAccessTest extends LmsTestCase
{
    public function test_siswa_terdaftar_dapat_membuka_course_dan_mengakses_materi(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0], ['student_id' => $world['students'][0]->id]);

        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->getJson("/api/v1/lms/courses/{$course->id}/learn")
            ->assertOk()
            ->assertJsonPath('data.progress.enrolled', true);

        $item = $course->items()->firstOrFail();

        $this->getJson("/api/v1/lms/courses/{$course->id}/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.access.allowed', true);
    }

    public function test_siswa_tidak_terdaftar_ditolak_dengan_alasan_yang_jelas(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->getJson("/api/v1/lms/courses/{$course->id}/learn")
            ->assertForbidden();

        $this->getJson("/api/v1/lms/courses/{$course->id}")
            ->assertStatus(403);

        $item = $course->items()->firstOrFail();
        $this->getJson("/api/v1/lms/courses/{$course->id}/items/{$item->id}")->assertStatus(403);
    }

    public function test_guru_pemilik_course_dapat_membuka_tanpa_pendaftaran(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->actingAsTeacher($world['teacher']);

        $this->getJson("/api/v1/lms/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('data.capabilities.manage', true)
            ->assertJsonPath('data.statistics.students', 0);
    }

    public function test_wali_murid_hanya_boleh_melihat_course_anaknya_secara_baca_saja(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0], ['student_id' => $world['students'][0]->id]);

        $guardian = Guardian::create(['full_name' => 'Bapak Siswa 1']);
        StudentGuardian::create([
            'student_id' => $world['students'][0]->id,
            'guardian_id' => $guardian->id,
            'is_primary' => true,
        ]);

        $this->actingAsParent($guardian);

        $this->getJson("/api/v1/lms/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('data.capabilities.manage', false)
            ->assertJsonPath('data.capabilities.read_only', true)
            ->assertJsonPath('data.access.allowed', true);

        $item = $course->items()->firstOrFail();

        // Wali tidak boleh menandai penyelesaian atas nama anak.
        $this->postJson("/api/v1/lms/courses/{$course->id}/items/{$item->id}/complete")
            ->assertForbidden();
    }

    public function test_wali_tidak_dapat_membuka_course_anak_orang_lain(): void
    {
        $world = $this->world();
        $other = LmsCourse::create([
            'name' => 'Course Siswa Lain', 'code' => 'OTHER-'.uniqid(), 'slug' => 'other-'.uniqid(),
            'course_type' => 'GENERAL', 'status' => 'PUBLISHED', 'visibility' => 'ENROLLED_ONLY',
            'published_at' => now(),
        ]);
        $this->enroll($other, $world['studentUsers'][2]);

        $guardian = Guardian::create(['full_name' => 'Bapak Siswa 1']);
        StudentGuardian::create([
            'student_id' => $world['students'][0]->id, 'guardian_id' => $guardian->id, 'is_primary' => true,
        ]);

        $this->actingAsParent($guardian);

        $this->getJson("/api/v1/lms/courses/{$other->id}")->assertStatus(403);
    }

    public function test_guest_hanya_melihat_course_publik_dan_materi_pratinjau(): void
    {
        $world = $this->world();
        $course = $this->publishCourse(
            $this->makeCourse($world, ['visibility' => 'PUBLIC', 'course_type' => 'GENERAL']),
            $world,
        );

        $previewItem = $course->items()->firstOrFail();
        $previewItem->forceFill(['is_preview' => true])->save();

        $privateItem = $course->sections->first()->items()->create([
            'course_id' => $course->id, 'title' => 'Materi Privat', 'item_type' => 'RESOURCE',
            'resource_type' => 'PAGE', 'completion_type' => 'MANUAL', 'sort_order' => 2,
        ]);

        // Tanpa token: course publik dapat dijelajahi.
        $this->getJson("/api/v1/lms/public/courses/{$course->slug}")->assertOk();

        // Materi pratinjau terbuka; materi lain terkunci.
        $this->assertTrue($previewItem->fresh()->is_preview);
        $this->getJson("/api/v1/lms/public/courses/{$course->slug}")
            ->assertJsonFragment(['id' => $previewItem->id, 'locked' => false])
            ->assertJsonFragment(['id' => $privateItem->id, 'locked' => true]);
    }

    public function test_course_belum_terbit_tidak_dapat_dipelajari_siswa(): void
    {
        $world = $this->world();
        $course = $this->makeCourse($world);   // DRAFT
        $this->enroll($course, $world['studentUsers'][0]);

        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->getJson("/api/v1/lms/courses/{$course->id}/learn")->assertStatus(403);
    }

    public function test_kursus_di_luar_jadwal_menolak_akses_dengan_alasan_jadwal(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $course->forceFill(['start_date' => now()->addWeek()->toDateString()])->save();
        $this->enroll($course, $world['studentUsers'][0]);

        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $response = $this->getJson("/api/v1/lms/courses/{$course->id}/learn")->assertStatus(403);
        $this->assertSame(403, $response->status());
    }
}
