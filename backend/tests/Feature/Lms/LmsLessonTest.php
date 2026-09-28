<?php

namespace Tests\Feature\Lms;

/**
 * Lesson: halaman, cabang jawaban, dan penyelesaian (§37, §40).
 */
class LmsLessonTest extends LmsTestCase
{
    /** @return array{0: \App\Modules\LMS\Models\LmsActivity, 1: array<string, mixed>} */
    protected function lessonWorld(): array
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, [
            'activity_type' => 'LESSON',
            'title' => 'Pelajaran Aljabar',
        ]);

        return [$activity, $world];
    }

    /** Navigasi halaman berurutan dan penyelesaian setelah halaman wajib (§37, §40). */
    public function test_navigasi_halaman_dan_penyelesaian(): void
    {
        [$activity, $world] = $this->lessonWorld();

        $this->actingAsTeacher($world['teacher']);

        $pageOne = $this->postJson("/api/v1/lms/activities/{$activity->id}/lesson/pages", [
            'title' => 'Pengantar',
            'content' => '<p>Selamat datang.</p>',
        ])->assertCreated()->json('data');

        $pageTwo = $this->postJson("/api/v1/lms/activities/{$activity->id}/lesson/pages", [
            'title' => 'Latihan',
            'content' => '<p>Kerjakan latihan.</p>',
        ])->assertCreated()->json('data');

        $this->publishActivity($activity);

        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $view = $this->getJson("/api/v1/lms/activities/{$activity->id}/lesson")->assertOk()->json('data');

        $this->assertSame($pageOne['id'], $view['current']['id']);
        $this->assertCount(2, $view['pages']);
        // Belum ada progres sebelum halaman pertama dibuka.
        $this->assertNull($view['progress']);
        $this->assertSame($pageTwo['id'], $view['nav']['next']['id']);

        $progress = $this->postJson("/api/v1/lms/activities/{$activity->id}/lesson/progress", [
            'page_id' => $pageOne['id'],
        ])->assertOk()->json('data');

        $this->assertSame(50.0, (float) $progress['progress']['percent']);
        $this->assertSame($pageTwo['id'], $progress['next_page']['id']);
        $this->assertFalse($progress['progress']['completed']);

        $final = $this->postJson("/api/v1/lms/activities/{$activity->id}/lesson/progress", [
            'page_id' => $pageTwo['id'],
        ])->assertOk()->json('data');

        $this->assertSame(100.0, (float) $final['progress']['percent']);
        $this->assertTrue($final['progress']['completed']);

        // Penyelesaian tercatat pada mesin penyelesaian Phase 1 (§40).
        $this->assertDatabaseHas('lms_activity_completions', [
            'user_id' => $studentUser->id,
            'learning_item_id' => $activity->learning_item_id,
            'status' => 'COMPLETE',
        ]);
    }

    /** Halaman pertanyaan bercabang mengarahkan ke halaman berbeda (§37). */
    public function test_halaman_bercabang_mengarahkan_jawaban(): void
    {
        [$activity, $world] = $this->lessonWorld();

        $this->actingAsTeacher($world['teacher']);

        $correct = $this->postJson("/api/v1/lms/activities/{$activity->id}/lesson/pages", [
            'title' => 'Halaman penguatan',
            'content' => '<p>Jawaban benar.</p>',
        ])->assertCreated()->json('data');

        $retry = $this->postJson("/api/v1/lms/activities/{$activity->id}/lesson/pages", [
            'title' => 'Halaman ulangi',
            'content' => '<p>Coba lagi.</p>',
        ])->assertCreated()->json('data');

        $question = $this->postJson("/api/v1/lms/activities/{$activity->id}/lesson/pages", [
            'title' => 'Pertanyaan cabang',
            'content' => '<p>Berapa hasil 2 + 2?</p>',
            'page_type' => 'QUESTION',
            'branches' => [
                ['label' => 'Benar', 'answer_key' => '4', 'is_correct' => true, 'next_page_id' => $correct['id']],
                ['label' => 'Salah', 'answer_key' => '5', 'is_correct' => false, 'next_page_id' => $retry['id']],
            ],
        ])->assertCreated()->json('data');

        $this->publishActivity($activity);

        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        // Jawaban benar → halaman penguatan.
        $right = $this->postJson("/api/v1/lms/activities/{$activity->id}/lesson/progress", [
            'page_id' => $question['id'],
            'answer_key' => '4',
        ])->assertOk()->json('data');

        $this->assertTrue($right['answer_correct']);
        $this->assertSame($correct['id'], $right['next_page']['id']);

        // Jawaban salah → halaman ulangi.
        $wrong = $this->postJson("/api/v1/lms/activities/{$activity->id}/lesson/progress", [
            'page_id' => $question['id'],
            'answer_key' => '5',
        ])->assertOk()->json('data');

        $this->assertFalse($wrong['answer_correct']);
        $this->assertSame($retry['id'], $wrong['next_page']['id']);

        // Kunci jawaban tidak dikirim pada halaman siswa (§28 padanan).
        $view = $this->getJson("/api/v1/lms/activities/{$activity->id}/lesson?page_id={$question['id']}")
            ->assertOk()->json('data');

        $this->assertTrue($view['current']['branches'][0]['is_correct']);
        $this->assertNull($view['current']['question']['options'][0]['is_correct'] ?? null);
    }

    /** Guru tidak dapat menghapus halaman terakhir lesson (§37). */
    public function test_halaman_terakhir_tidak_dapat_dihapus(): void
    {
        [$activity, $world] = $this->lessonWorld();

        $this->actingAsTeacher($world['teacher']);

        $page = $this->postJson("/api/v1/lms/activities/{$activity->id}/lesson/pages", [
            'title' => 'Satu-satunya halaman',
        ])->assertCreated()->json('data');

        $this->deleteJson("/api/v1/lms/lesson-pages/{$page['id']}")->assertStatus(422);
    }

    /** Statistik lesson untuk guru (§37). */
    public function test_statistik_lesson(): void
    {
        [$activity, $world] = $this->lessonWorld();

        $this->actingAsTeacher($world['teacher']);

        $this->postJson("/api/v1/lms/activities/{$activity->id}/lesson/pages", ['title' => 'Halaman 1'])->assertCreated();
        $this->publishActivity($activity);

        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $view = $this->getJson("/api/v1/lms/activities/{$activity->id}/lesson")->assertOk()->json('data');
        $this->postJson("/api/v1/lms/activities/{$activity->id}/lesson/progress", ['page_id' => $view['current']['id']])
            ->assertOk();

        $this->actingAsTeacher($world['teacher']);

        $statistics = $this->getJson("/api/v1/lms/activities/{$activity->id}/lesson/statistics")
            ->assertOk()->json('data');

        $this->assertSame(1, $statistics['started']);
        $this->assertSame(1, $statistics['completed']);
        $this->assertSame(1, $statistics['participants'], 'Hanya satu siswa yang terdaftar pada course ini.');
    }
}
