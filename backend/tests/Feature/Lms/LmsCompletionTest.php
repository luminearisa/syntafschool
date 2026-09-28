<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Models\LmsLearningItem;

/** §44 Completion: manual, view, prerequisite, progress calculation. */
class LmsCompletionTest extends LmsTestCase
{
    public function test_menandai_selesai_manual_menaikkan_persentase_progress(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections->first();
        $first = $section->items->first();
        $second = $this->makePageItem($section, ['title' => 'Materi Kedua', 'sort_order' => 2]);

        $this->enroll($course, $world['studentUsers'][0], ['student_id' => $world['students'][0]->id]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->getJson("/api/v1/lms/courses/{$course->id}/progress")
            ->assertOk()
            ->assertJsonPath('data.progress_percent', 0)
            ->assertJsonPath('data.required_items', 2)
            ->assertJsonPath('data.next_item_id', $first->id);

        $this->postJson("/api/v1/lms/courses/{$course->id}/items/{$first->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.progress.progress_percent', 50)
            ->assertJsonPath('data.next.id', $second->id);

        $this->postJson("/api/v1/lms/courses/{$course->id}/items/{$second->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.progress.progress_percent', 100);

        // Course berstatus COMPLETED setelah seluruh item tuntas.
        $this->assertSame('COMPLETED', $course->enrollmentFor($world['studentUsers'][0]->id)->status->value);
    }

    public function test_materi_bertipe_view_otomatis_selesai_saat_dibuka(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections->first();
        $section->items()->update(['completion_type' => 'VIEW']);

        $item = $section->items()->firstOrFail();
        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->postJson("/api/v1/lms/courses/{$course->id}/items/{$item->id}/view")
            ->assertOk()
            ->assertJsonPath('data.auto_completed', true);

        $this->assertDatabaseHas('lms_activity_completions', [
            'enrollment_id' => $course->enrollmentFor($world['studentUsers'][0]->id)->id,
            'learning_item_id' => $item->id,
            'completion_type' => 'VIEW',
            'status' => 'COMPLETE',
        ]);

        $this->assertDatabaseHas('lms_learning_views', ['learning_item_id' => $item->id]);
    }

    public function test_materi_prasyarat_terkunci_sampai_item_sebelumnya_tuntas(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections->first();
        $first = $section->items->first();

        $second = $this->makePageItem($section, [
            'title' => 'Kuis Prasyarat',
            'sort_order' => 2,
            'availability' => ['require_previous_item' => true],
        ]);

        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        // Terkunci sebelum prasyarat tuntas, dengan alasan yang dapat dibaca (§39).
        $this->getJson("/api/v1/lms/courses/{$course->id}/items/{$second->id}")
            ->assertStatus(403);

        $this->getJson("/api/v1/lms/courses/{$course->id}/learn")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $second->id,
                'locked' => true,
                'lock_reason' => 'Selesaikan "'.$first->title.'" terlebih dahulu.',
            ]);

        // Setelah prasyarat tuntas, materi terbuka.
        $this->postJson("/api/v1/lms/courses/{$course->id}/items/{$first->id}/complete")->assertOk();

        $this->getJson("/api/v1/lms/courses/{$course->id}/items/{$second->id}")->assertOk();
    }

    public function test_prasyarat_berupa_nilai_minimum_dihormati(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections->first();
        $first = $section->items->first();

        $gated = $this->makePageItem($section, [
            'title' => 'Ujian Akhir',
            'sort_order' => 2,
            'availability' => ['required_grade' => 75],
        ]);

        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->postJson("/api/v1/lms/courses/{$course->id}/items/{$first->id}/complete", ['grade' => 60])->assertOk();

        $this->getJson("/api/v1/lms/courses/{$course->id}/items/{$gated->id}")->assertStatus(403);

        $this->postJson("/api/v1/lms/courses/{$course->id}/items/{$first->id}/complete", ['grade' => 90])->assertOk();

        $this->getJson("/api/v1/lms/courses/{$course->id}/items/{$gated->id}")->assertOk();
    }

    public function test_persentase_dihitung_dari_item_wajib_bukan_jumlah_halaman_dibuka(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections->first();

        $required = $section->items->first();
        $this->makePageItem($section, ['title' => 'Materi Opsional', 'sort_order' => 2, 'is_required' => false]);
        $this->makePageItem($section, ['title' => 'Hanya Info', 'sort_order' => 3, 'completion_type' => 'NONE']);

        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $progress = $this->getJson("/api/v1/lms/courses/{$course->id}/progress")->assertOk();

        // 3 item tersimpan, tetapi hanya 2 yang dihitung (satu bertipe NONE),
        // dan hanya 1 yang wajib.
        $this->assertSame(2, $progress->json('data.total_items'));
        $this->assertSame(1, $progress->json('data.required_items'));

        $this->postJson("/api/v1/lms/courses/{$course->id}/items/{$required->id}/complete")->assertOk();

        $this->getJson("/api/v1/lms/courses/{$course->id}/progress")
            ->assertJsonPath('data.progress_percent', 100);
    }

    public function test_tanda_selesai_dapat_dibatalkan_dan_progress_ikut_turun(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $item = $course->items()->firstOrFail();

        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->postJson("/api/v1/lms/courses/{$course->id}/items/{$item->id}/complete")->assertOk();
        $this->assertSame(100.0, (float) $this->getJson("/api/v1/lms/courses/{$course->id}/progress")->json('data.progress_percent'));

        $this->deleteJson("/api/v1/lms/courses/{$course->id}/items/{$item->id}/complete")->assertOk();

        $this->getJson("/api/v1/lms/courses/{$course->id}/progress")
            ->assertJsonPath('data.progress_percent', 0);
    }

    public function test_item_tersembunyi_tidak_dihitung_dan_tidak_dapat_dibuka_siswa(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections->first();

        $hidden = $this->makePageItem($section, ['title' => 'Materi Rahasia', 'sort_order' => 2, 'visibility' => 'HIDDEN']);

        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->getJson("/api/v1/lms/courses/{$course->id}/progress")
            ->assertJsonPath('data.required_items', 1);

        $this->getJson("/api/v1/lms/courses/{$course->id}/items/{$hidden->id}")->assertStatus(403);
    }
}
