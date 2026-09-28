<?php

namespace Tests\Feature\Lms;

use App\Models\Guardian;
use App\Models\StudentGuardian;

/** §44 Parent: pemilihan anak, isolasi anak, akses tidak sah. */
class LmsParentTest extends LmsTestCase
{
    /** @return array{guardian: Guardian, user: \App\Models\User} */
    protected function family(array $world, int $childIndex = 0): array
    {
        $guardian = Guardian::create(['full_name' => 'Bapak Wali']);
        StudentGuardian::create([
            'student_id' => $world['students'][$childIndex]->id,
            'guardian_id' => $guardian->id,
            'is_primary' => true,
        ]);

        return ['guardian' => $guardian, 'user' => $this->actingAsParent($guardian)];
    }

    public function test_wali_melihat_daftar_anak_dan_progress_belajarnya(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $item = $course->items()->firstOrFail();
        $this->enroll($course, $world['studentUsers'][0], ['student_id' => $world['students'][0]->id]);

        // Anak menyelesaikan satu materi lebih dulu.
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);
        $this->postJson("/api/v1/lms/courses/{$course->id}/items/{$item->id}/complete")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->family($world);

        $this->getJson('/api/v1/lms/parent/children')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Siswa 1');

        $this->getJson('/api/v1/lms/parent/children/'.$world['students'][0]->id)
            ->assertOk()
            ->assertJsonPath('data.child.name', 'Siswa 1')
            ->assertJsonPath('data.stats.courses', 1)
            ->assertJsonPath('data.stats.average_progress', 100)
            ->assertJsonPath('data.courses.0.progress_percent', 100);

        $this->getJson('/api/v1/lms/dashboard/parent')->assertOk()->assertJsonPath('data.role', 'PARENT');
    }

    public function test_wali_tidak_dapat_melihat_data_anak_orang_lain(): void
    {
        $world = $this->world();
        $this->family($world, 0);

        $this->getJson('/api/v1/lms/parent/children/'.$world['students'][2]->id)
            ->assertForbidden();
    }

    public function test_wali_tidak_dapat_mengubah_nilai_atau_menyelesaikan_tugas_anak(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $item = $course->items()->firstOrFail();
        $this->enroll($course, $world['studentUsers'][0], ['student_id' => $world['students'][0]->id]);

        $this->family($world);

        $this->postJson("/api/v1/lms/courses/{$course->id}/items/{$item->id}/complete")->assertForbidden();
        $this->deleteJson("/api/v1/lms/courses/{$course->id}/items/{$item->id}/complete")->assertForbidden();

        // Wali juga bukan pengelola enrollment.
        $enrollment = $course->enrollmentFor($world['studentUsers'][0]->id);
        $this->postJson("/api/v1/lms/enrollments/{$enrollment->id}/suspend")->assertForbidden();
    }

    public function test_wali_dari_dua_anak_dapat_berpindah_anak(): void
    {
        $world = $this->world();
        $guardian = Guardian::create(['full_name' => 'Ibu Wali']);

        foreach ([0, 1] as $index) {
            StudentGuardian::create([
                'student_id' => $world['students'][$index]->id,
                'guardian_id' => $guardian->id,
                'is_primary' => $index === 0,
            ]);
        }

        $this->actingAsParent($guardian);

        $children = $this->getJson('/api/v1/lms/parent/children')->assertOk();
        $this->assertCount(2, $children->json('data'));

        foreach ([0, 1] as $index) {
            $this->getJson('/api/v1/lms/parent/children/'.$world['students'][$index]->id)->assertOk();
        }
    }
}
