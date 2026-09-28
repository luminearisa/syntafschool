<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Models\LmsChoiceResponse;
use App\Modules\LMS\Models\LmsSurveyResponse;

/**
 * Polling (§38) dan survei (§39) — termasuk mode anonim dan visibilitas hasil.
 */
class LmsChoiceSurveyTest extends LmsTestCase
{
    /** Polling satu pilihan: hasil tampil setelah menjawab (§38). */
    public function test_polling_satu_pilihan_dan_visibilitas_hasil(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, [
            'activity_type' => 'CHOICE',
            'title' => 'Metode belajar favorit',
            'allow_multiple' => false,
            'limit_responses' => 1,
            'results_visibility' => 'AFTER_RESPONSE',
            'options' => [
                ['text' => 'Video'],
                ['text' => 'Buku'],
                ['text' => 'Diskusi'],
            ],
        ]);

        $this->publishActivity($activity);

        $studentUser = $world['studentUsers'][0];
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $before = $this->getJson("/api/v1/lms/activities/{$activity->id}/choice")->assertOk()->json('data');

        $this->assertFalse($before['has_responded']);
        $this->assertFalse($before['results_visible']);
        $this->assertNull($before['results'], 'Hasil belum boleh tampil sebelum siswa menjawab.');

        $optionId = $before['options'][1]['id'];

        $after = $this->postJson("/api/v1/lms/activities/{$activity->id}/choice/respond", [
            'option_ids' => [$optionId],
        ])->assertOk()->json('data');

        $this->assertTrue($after['has_responded']);
        $this->assertTrue($after['results_visible']);
        $this->assertSame(1, $after['results']['total_responses']);
        $this->assertSame(100.0, (float) $after['results']['options'][1]['percent']);

        // Batas pengisian 1 → menjawab lagi ditolak.
        $this->postJson("/api/v1/lms/activities/{$activity->id}/choice/respond", [
            'option_ids' => [$before['options'][0]['id']],
        ])->assertStatus(422);

        // Penyelesaian tercatat (§40).
        $this->assertDatabaseHas('lms_activity_completions', [
            'user_id' => $studentUser->id,
            'learning_item_id' => $activity->learning_item_id,
        ]);
    }

    /** Polling multi-pilihan menghormati batas pilihan (§38). */
    public function test_polling_multi_pilihan_dan_batasnya(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, [
            'activity_type' => 'CHOICE',
            'title' => 'Pilih dua kegiatan favorit',
            'allow_multiple' => true,
            'limit_responses' => 2,
            'results_visibility' => 'ALWAYS',
            'options' => [['text' => 'Membaca'], ['text' => 'Olahraga'], ['text' => 'Musik'], ['text' => 'Melukis']],
        ]);

        $this->publishActivity($activity);

        $studentUser = $world['studentUsers'][0];
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $view = $this->getJson("/api/v1/lms/activities/{$activity->id}/choice")->assertOk()->json('data');

        $ids = collect($view['options'])->pluck('id');

        // Tiga pilihan melebihi batas 2.
        $this->postJson("/api/v1/lms/activities/{$activity->id}/choice/respond", [
            'option_ids' => [$ids[0], $ids[1], $ids[2]],
        ])->assertStatus(422);

        $this->postJson("/api/v1/lms/activities/{$activity->id}/choice/respond", [
            'option_ids' => [$ids[0], $ids[1]],
        ])->assertOk();

        $this->assertSame(2, LmsChoiceResponse::query()->where('user_id', $studentUser->id)->count());

        // Hasil selalu terlihat pada konfigurasi ALWAYS.
        $view = $this->getJson("/api/v1/lms/activities/{$activity->id}/choice")->assertOk()->json('data');
        $this->assertTrue($view['results_visible']);
    }

    /** Guru melihat rekap polling lengkap dengan persentase (§38). */
    public function test_rekap_hasil_polling_untuk_guru(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, [
            'activity_type' => 'CHOICE',
            'title' => 'Polling kelas',
            'results_visibility' => 'NEVER',
            'options' => [['text' => 'Setuju'], ['text' => 'Tidak setuju']],
        ]);

        $this->publishActivity($activity);

        foreach ([0, 1] as $index) {
            $this->enroll($course, $world['studentUsers'][$index]);
            $this->actingAsStudent($world['students'][$index], $world['studentUsers'][$index]);

            $view = $this->getJson("/api/v1/lms/activities/{$activity->id}/choice")->assertOk()->json('data');
            $this->assertNull($view['results'], 'Hasil ditutup untuk siswa pada konfigurasi NEVER.');

            $this->postJson("/api/v1/lms/activities/{$activity->id}/choice/respond", [
                'option_ids' => [$view['options'][0]['id']],
            ])->assertOk();
        }

        $this->actingAsTeacher($world['teacher']);

        $results = $this->getJson("/api/v1/lms/activities/{$activity->id}/choice/results")->assertOk()->json('data');

        $this->assertSame(2, $results['total_responses']);
        $this->assertSame(2, $results['participants']);
        $this->assertSame(100.0, (float) $results['options'][0]['percent']);
        $this->assertSame(2, $results['participants']);
    }

    /** Survei: validasi wajib, agregasi jawaban, dan hasil untuk guru (§39). */
    public function test_survei_validasi_dan_agregasi_hasil(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, [
            'activity_type' => 'SURVEY',
            'title' => 'Umpan balik pembelajaran',
            'questions' => [
                ['question_type' => 'RATING', 'text' => 'Materi mudah dipahami?', 'rating_min' => 1, 'rating_max' => 5],
                ['question_type' => 'YES_NO', 'text' => 'Apakah tugas membantu?'],
                ['question_type' => 'TEXT', 'text' => 'Komentar tambahan', 'is_required' => false],
            ],
        ]);

        $this->publishActivity($activity);

        $studentUser = $world['studentUsers'][0];
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $view = $this->getJson("/api/v1/lms/activities/{$activity->id}/survey")->assertOk()->json('data');

        $this->assertCount(3, $view['questions']);

        // Pertanyaan wajib kosong → ditolak.
        $this->postJson("/api/v1/lms/activities/{$activity->id}/survey/submit", [
            'answers' => [['question_id' => $view['questions'][0]['id'], 'response_value' => null]],
        ])->assertStatus(422);

        $ratingId = $view['questions'][0]['id'];
        $yesNoId = $view['questions'][1]['id'];
        $textId = $view['questions'][2]['id'];

        $this->postJson("/api/v1/lms/activities/{$activity->id}/survey/submit", [
            'answers' => [
                ['question_id' => $ratingId, 'response_value' => 5],
                ['question_id' => $yesNoId, 'response_text' => 'Ya'],
                ['question_id' => $textId, 'response_text' => 'Pertahankan cara mengajar.'],
            ],
        ])->assertOk()->assertJsonPath('data.submitted', true);

        // Sudah mengisi → tidak boleh mengisi lagi (allow_multiple_submissions = false).
        $this->postJson("/api/v1/lms/activities/{$activity->id}/survey/submit", [
            'answers' => [['question_id' => $ratingId, 'response_value' => 3]],
        ])->assertStatus(422);

        // Identitas tersimpan karena survei tidak anonim: 3 jawaban, 1 pengisian.
        $this->assertSame(3, LmsSurveyResponse::query()->where('user_id', $studentUser->id)->count());
        $this->assertSame(1, LmsSurveyResponse::query()
            ->where('user_id', $studentUser->id)
            ->distinct()
            ->count('submission_key'), 'Satu pengisian survei memakai satu submission_key.');

        $this->actingAsTeacher($world['teacher']);

        $results = $this->getJson("/api/v1/lms/activities/{$activity->id}/survey/results")->assertOk()->json('data');

        $this->assertSame(1, $results['responses']);
        $this->assertSame(5.0, (float) $results['questions'][0]['average']);
        $this->assertSame(['Ya' => 1], $results['questions'][1]['distribution']);
        $this->assertSame(['Pertahankan cara mengajar.'], $results['questions'][2]['comments']);
    }

    /** Survei anonim tidak menyimpan identitas pengisi (§39). */
    public function test_survei_anonim_tidak_menyimpan_identitas(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, [
            'activity_type' => 'SURVEY',
            'title' => 'Survei anonim',
            'anonymous' => true,
            'questions' => [
                ['question_type' => 'RATING', 'text' => 'Seberapa nyaman belajarmu?'],
            ],
        ]);

        $this->publishActivity($activity);

        $studentUser = $world['studentUsers'][0];
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $view = $this->getJson("/api/v1/lms/activities/{$activity->id}/survey")->assertOk()->json('data');
        $this->assertTrue($view['survey']['anonymous']);

        $this->postJson("/api/v1/lms/activities/{$activity->id}/survey/submit", [
            'answers' => [['question_id' => $view['questions'][0]['id'], 'response_value' => 4]],
        ])->assertOk()->assertJsonPath('data.anonymous', true);

        $this->assertSame(0, LmsSurveyResponse::query()->whereNotNull('user_id')->count(), 'Survei anonim tidak boleh menyimpan user_id.');
        $this->assertSame(1, LmsSurveyResponse::query()->count());

        // Wali/observasi tetap dapat melihat rekap tanpa identitas.
        $this->actingAsTeacher($world['teacher']);

        $results = $this->getJson("/api/v1/lms/activities/{$activity->id}/survey/results")->assertOk()->json('data');

        $this->assertTrue($results['anonymous']);
        $this->assertSame(4.0, (float) $results['questions'][0]['average']);
    }
}
