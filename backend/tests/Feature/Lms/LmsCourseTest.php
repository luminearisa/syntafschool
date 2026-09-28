<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Models\LmsCourse;

/** §44 Course: create, update, publish, archive, visibility. */
class LmsCourseTest extends LmsTestCase
{
    public function test_guru_dapat_membuat_course_sebagai_draf_yang_tertaut_siakad(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);
        $category = $this->makeCategory();

        $response = $this->postJson('/api/v1/lms/courses', [
            'name' => 'Matematika Dasar X IPA 1',
            'category_id' => $category->id,
            'academic_course_id' => $world['subject']->id,
            'class_id' => $world['class']->id,
            'academic_year_id' => $world['year']->id,
            'short_description' => 'Aljabar dan persamaan linear',
            'course_type' => 'ACADEMIC',
            'teacher_id' => $world['teacher']->id,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.academic_course_id', $world['subject']->id);

        $course = LmsCourse::query()->findOrFail($response->json('data.id'));

        // Kode mengikuti pola SIAKAD: MAT101-XIPA1-2026/2027 (§6).
        $this->assertStringContainsString('MAT101', $course->code);
        $this->assertStringContainsString('XIPA1', $course->code);
        $this->assertStringContainsString('20262027', $course->code);

        // Pengajar otomatis tercatat sebagai anggota course.
        $this->assertDatabaseHas('lms_course_teachers', [
            'course_id' => $course->id,
            'employee_id' => $world['teacher']->id,
        ]);

        $this->assertTrue(
            (bool) $course->teachers()->where('employee_id', $world['teacher']->id)->value('is_primary'),
        );
    }

    public function test_course_akademik_tanpa_mapel_siakad_ditolak(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);

        $this->postJson('/api/v1/lms/courses', [
            'name' => 'Course Tanpa Mapel',
            'course_type' => 'ACADEMIC',
        ])->assertUnprocessable()->assertJsonValidationErrors('academic_course_id');
    }

    public function test_siswa_tidak_boleh_membuat_course(): void
    {
        $world = $this->world();
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->postJson('/api/v1/lms/courses', ['name' => 'Course Nakal'])
            ->assertForbidden();
    }

    public function test_guru_hanya_dapat_mengubah_course_yang_menjadi_tanggung_jawabnya(): void
    {
        $world = $this->world();
        $course = $this->makeCourse($world, ['created_by' => null]);
        $owner = $this->actingAsTeacher($world['teacher']);

        $this->putJson("/api/v1/lms/courses/{$course->id}", ['name' => 'Nama Baru'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama Baru');

        // Guru lain tanpa keterkaitan course ditolak (§35).
        $other = \App\Models\Employee::create([
            'nip' => '199001012026011002', 'full_name' => 'Guru Lain',
            'is_teacher' => true, 'is_active' => true,
        ]);
        $this->actingAsTeacher($other);

        $this->putJson("/api/v1/lms/courses/{$course->id}", ['name' => 'Diubah Orang Lain'])
            ->assertForbidden();

        $this->assertSame('Nama Baru', $course->fresh()->name);
        $this->assertNotNull($owner);
    }

    public function test_admin_dapat_mengubah_course_milik_guru_lain(): void
    {
        $world = $this->world();
        $course = $this->makeCourse($world);
        $this->actingAsRole('admin-sekolah');

        $this->putJson("/api/v1/lms/courses/{$course->id}", ['visibility' => 'PUBLIC'])
            ->assertOk()
            ->assertJsonPath('data.visibility', 'PUBLIC');
    }

    public function test_penerbitan_menolak_course_tanpa_konten_dan_lulus_setelah_dilengkapi(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->makeCourse($world);

        $readiness = $this->getJson("/api/v1/lms/courses/{$course->id}/readiness")->assertOk();
        $this->assertFalse($readiness->json('data.ready'));

        $this->postJson("/api/v1/lms/courses/{$course->id}/publish")->assertUnprocessable();

        // Lengkapi konten & pengajar, lalu terbitkan.
        $this->publishCourse($course, $world);

        $this->postJson("/api/v1/lms/courses/{$course->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');

        $this->assertDatabaseHas('lms_courses', [
            'id' => $course->id, 'status' => 'PUBLISHED',
        ]);
    }

    public function test_arsip_dan_pemulihan_course(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $this->postJson("/api/v1/lms/courses/{$course->id}/archive")
            ->assertOk()->assertJsonPath('data.status', 'ARCHIVED');

        $this->postJson("/api/v1/lms/courses/{$course->id}/restore")
            ->assertOk()->assertJsonPath('data.status', 'DRAFT');

        $this->postJson("/api/v1/lms/courses/{$course->id}/unpublish")
            ->assertOk()->assertJsonPath('data.status', 'DRAFT');
    }

    public function test_duplikasi_course_menyalin_struktur_tanpa_status_terbit(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $copy = $this->postJson("/api/v1/lms/courses/{$course->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT');

        $newId = (int) $copy->json('data.id');
        $this->assertNotSame($course->id, $newId);
        $this->assertSame(1, LmsCourse::query()->find($newId)->sections()->count());
        $this->assertSame(1, LmsCourse::query()->find($newId)->items()->count());
    }

    public function test_visibilitas_mengatur_akses_guest_pada_daftar_course(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');

        $private = $this->publishCourse($this->makeCourse($world, ['name' => 'Privat', 'visibility' => 'PRIVATE']), $world);
        $public = $this->publishCourse($this->makeCourse($world, ['name' => 'Publik', 'visibility' => 'PUBLIC']), $world);

        $this->getJson('/api/v1/lms/public/courses')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Publik'])
            ->assertJsonMissing(['name' => 'Privat']);

        $this->getJson('/api/v1/lms/public/courses/'.$private->slug)->assertNotFound();
        $this->getJson('/api/v1/lms/public/courses/'.$public->slug)->assertOk();
    }
}
