<?php

namespace Tests\Feature\Lms;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AssessmentType;

/** §44 + §26–§29: dashboard semua peran, kalender, bookmark, pencarian. */
class LmsDashboardTest extends LmsTestCase
{
    public function test_dashboard_siswa_menampilkan_lanjutkan_belajar_dan_course_saya(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $item = $course->items()->firstOrFail();
        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->postJson("/api/v1/lms/courses/{$course->id}/items/{$item->id}/view")->assertOk();

        $response = $this->getJson('/api/v1/lms/dashboard/student')->assertOk()
            ->assertJsonPath('data.role', 'STUDENT')
            ->assertJsonPath('data.stats.courses', 1)
            ->assertJsonPath('data.my_courses.0.course_id', $course->id)
            ->assertJsonPath('data.continue_learning.0.course_id', $course->id);

        $this->assertNotEmpty($response->json('data.greeting'));
        $this->assertCount(1, $response->json('data.recent_activity'));
    }

    public function test_dashboard_guru_menghitung_siswa_aktif_dan_menunggu_penilaian(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);

        $course = $this->publishCourse($this->makeCourse($world), $world);

        foreach ($world['studentUsers'] as $index => $user) {
            $this->enroll($course, $user, ['student_id' => $world['students'][$index]->id]);
        }

        // Tugas ERP dengan satu pengumpulan yang belum dinilai (§27).
        $type = AssessmentType::create([
            'code' => 'TGS', 'name' => 'Tugas', 'category' => 'pengetahuan', 'is_active' => true,
        ]);
        $assignment = Assignment::create([
            'class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'teacher_id' => $world['teacher']->id,
            'semester_id' => $world['semester']->id,
            'title' => 'Latihan Aljabar',
            'description' => 'Kerjakan halaman 10',
            'type' => 'tugas',
            'max_score' => 100,
            'assigned_date' => now()->toDateString(),
            'due_date' => now()->addDays(2)->toDateString(),
            'status' => 'published',
            'published_at' => now(),
        ]);
        AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $world['students'][0]->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/lms/dashboard/teacher')->assertOk()
            ->assertJsonPath('data.role', 'TEACHER')
            ->assertJsonPath('data.stats.courses', 1)
            ->assertJsonPath('data.stats.active_students', 3)
            ->assertJsonPath('data.stats.pending_grading', 1);

        $this->assertSame('Latihan Aljabar', $response->json('data.upcoming_deadlines.0.title'));
        $this->assertSame($world['students'][0]->full_name, $response->json('data.pending_grading.0.student_name'));
    }

    public function test_dashboard_admin_menyajikan_statistik_dan_tren(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);

        $this->actingAsRole('admin-sekolah');

        $response = $this->getJson('/api/v1/lms/dashboard/admin')->assertOk()
            ->assertJsonPath('data.role', 'ADMIN')
            ->assertJsonPath('data.stats.total_courses', 1)
            ->assertJsonPath('data.stats.active_courses', 1)
            ->assertJsonPath('data.stats.enrollments', 1);

        $this->assertCount(6, $response->json('data.enrollment_trend'));
        $this->assertCount(14, $response->json('data.activity_trend'));
        $this->assertSame('integrated', $response->json('data.integration.mode'));
    }

    public function test_kalender_menampilkan_tenggat_dan_pengumuman(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $this->postJson("/api/v1/lms/courses/{$course->id}/announcements", [
            'title' => 'Kuis Jumat',
            'content' => '<p>Kuis bab 1</p>',
        ])->assertCreated();

        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $response = $this->getJson('/api/v1/lms/calendar')->assertOk();

        $this->assertNotEmpty($response->json('data.today'));
        $this->assertSame('announcement', $response->json('data.today.0.type'));
    }

    public function test_bookmark_course_dan_materi_dapat_ditoggle(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $item = $course->items()->firstOrFail();
        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->postJson("/api/v1/lms/courses/{$course->id}/bookmark")
            ->assertOk()->assertJsonPath('data.bookmarked', true);

        $this->postJson("/api/v1/lms/courses/{$course->id}/bookmark", ['learning_item_id' => $item->id])
            ->assertOk()->assertJsonPath('data.bookmarked', true);

        $this->getJson('/api/v1/lms/bookmarks')->assertOk()->assertJsonCount(2, 'data');

        // Toggle kedua menghapus bookmark course.
        $this->postJson("/api/v1/lms/courses/{$course->id}/bookmark")
            ->assertOk()->assertJsonPath('data.bookmarked', false);

        $this->getJson('/api/v1/lms/bookmarks')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_pencarian_global_menemukan_course_dan_materi(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->publishCourse(
            $this->makeCourse($world, ['name' => 'Aljabar Terapan', 'visibility' => 'PUBLIC']),
            $world,
        );

        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->getJson('/api/v1/lms/search?q=Aljabar')
            ->assertOk()
            ->assertJsonPath('data.courses.0.name', 'Aljabar Terapan');

        $this->getJson('/api/v1/lms/search?q=Pengenalan')
            ->assertOk()
            ->assertJsonPath('data.items.0.title', 'Materi Pengenalan');

        $this->getJson('/api/v1/lms/search/suggestions?q=Aljabar')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Aljabar Terapan');
    }

    public function test_pengumuman_mengirim_notifikasi_database_ke_peserta(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $this->enroll($course, $world['studentUsers'][0]);

        $this->postJson("/api/v1/lms/courses/{$course->id}/announcements", [
            'title' => 'Jadwal Ujian',
            'content' => '<p>Ujian pekan depan</p>',
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $world['studentUsers'][0]->id,
            'notifiable_type' => $world['studentUsers'][0]::class,
        ]);
    }
}
