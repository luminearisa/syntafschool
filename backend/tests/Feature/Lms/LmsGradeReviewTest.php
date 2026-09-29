<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Application\Services\GradeReleaseService;
use App\Modules\LMS\Domain\Enums\GradeReviewStatus;
use App\Modules\LMS\Models\LmsGradeReview;
use App\Modules\LMS\Models\LmsGradeReviewEvent;

/**
 * Banding/review nilai (§20, §59).
 */
class LmsGradeReviewTest extends LmsTestCase
{
    /** @return array<string, mixed> */
    private function scenario(): array
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $item = $this->makeManualItem($scenario['gradebook'], ['name' => 'Esai Literasi', 'passing_grade' => 70]);

        return [
            'world' => $world,
            'course' => $course,
            'gradebook' => $scenario['gradebook'],
            'item' => $item,
            'student' => $scenario['user_ids'][0],
        ];
    }

    private function release(array $ctx, float $score = 78.0): void
    {
        $this->recordGrade($ctx['item'], $ctx['student'], $score);

        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        app(GradeReleaseService::class)->releaseStudent($ctx['gradebook'], $ctx['student'], $this->actorFor($teacher));
    }

    public function test_siswa_dapat_mengajukan_banding_untuk_nilai_yang_sudah_dirilis(): void
    {
        $ctx = $this->scenario();
        $this->release($ctx);

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);

        $response = $this->postJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grade-reviews', [
            'reason' => 'Jawaban nomor 3 menurut saya sudah benar.',
            'requested_score' => 84,
        ])->assertCreated();

        $this->assertSame('PENDING', $response->json('data.status'));
        $this->assertEqualsWithDelta(78.0, (float) $response->json('data.current_score'), 0.01);

        $review = LmsGradeReview::query()->firstOrFail();
        $this->assertTrue($review->isOpen());
        $this->assertSame(1, LmsGradeReviewEvent::query()->where('action', 'REQUESTED')->count());
        $this->assertDatabaseHas('lms_grade_histories', ['user_id' => $ctx['student']]);
    }

    public function test_banding_untuk_nilai_belum_dirilis_ditolak(): void
    {
        $ctx = $this->scenario();
        $this->recordGrade($ctx['item'], $ctx['student'], 78);

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);

        $this->postJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grade-reviews', [
            'reason' => 'Nilai belum keluar tapi saya ingin menanyakan.',
        ])->assertStatus(422);
    }

    public function test_pengajuan_ganda_untuk_nilai_yang_sama_ditolak(): void
    {
        $ctx = $this->scenario();
        $this->release($ctx);

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);
        $url = '/api/v1/lms/grade-items/'.$ctx['item']->id.'/grade-reviews';

        $this->postJson($url, ['reason' => 'Mohon ditinjau ulang.'])->assertCreated();
        $this->postJson($url, ['reason' => 'Saya ajukan lagi.'])->assertStatus(422);
    }

    public function test_guru_meninjau_dan_menyetujui_banding_sehingga_nilai_dikoreksi(): void
    {
        $ctx = $this->scenario();
        $this->release($ctx);

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);
        $reviewId = (int) $this->postJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grade-reviews', [
            'reason' => 'Silakan ditinjau ulang bagian kesimpulan.',
            'requested_score' => 84,
        ])->assertCreated()->json('data.id');

        $this->actingAsTeacher($ctx['world']['teacher']);

        $this->postJson('/api/v1/lms/grade-reviews/'.$reviewId.'/start')
            ->assertOk()
            ->assertJsonPath('data.status', 'UNDER_REVIEW');

        $response = $this->postJson('/api/v1/lms/grade-reviews/'.$reviewId.'/approve', [
            'score' => 84,
            'note' => 'Bagian kesimpulan memang belum terhitung.',
        ])->assertOk();

        $this->assertSame('APPROVED', $response->json('data.review.status'));
        $this->assertEqualsWithDelta(78.0, (float) $response->json('data.previous'), 0.01);
        $this->assertEqualsWithDelta(84.0, (float) $response->json('data.corrected'), 0.01);

        $this->assertEqualsWithDelta(84.0, (float) $ctx['item']->resultFor($ctx['student'])?->final_score, 0.01);

        $this->assertDatabaseHas('lms_grade_review_events', ['action' => 'ADJUSTED']);
        $this->assertDatabaseHas('lms_grade_histories', ['action' => 'CORRECTED', 'reason' => 'Banding disetujui: Bagian kesimpulan memang belum terhitung.']);
    }

    public function test_banding_dapat_ditolak_tanpa_mengubah_nilai(): void
    {
        $ctx = $this->scenario();
        $this->release($ctx, 65.0);

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);
        $reviewId = (int) $this->postJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grade-reviews', [
            'reason' => 'Mohon dicek kembali.',
        ])->assertCreated()->json('data.id');

        $this->actingAsTeacher($ctx['world']['teacher']);

        $this->postJson('/api/v1/lms/grade-reviews/'.$reviewId.'/reject', [
            'note' => 'Jawaban belum sesuai rubrik penilaian.',
        ])->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $this->assertEqualsWithDelta(65.0, (float) $ctx['item']->resultFor($ctx['student'])?->final_score, 0.01);
        $this->assertSame(GradeReviewStatus::REJECTED, LmsGradeReview::query()->firstOrFail()->status);
    }

    public function test_siswa_tidak_dapat_memutuskan_bandingnya_sendiri(): void
    {
        $ctx = $this->scenario();
        $this->release($ctx);

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);
        $reviewId = (int) $this->postJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grade-reviews', [
            'reason' => 'Mohon ditinjau.',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/lms/grade-reviews/'.$reviewId.'/approve', [
            'score' => 100,
            'note' => 'Saya setujui sendiri.',
        ])->assertForbidden();
    }

    public function test_guru_dapat_menambahkan_catatan_selama_proses(): void
    {
        $ctx = $this->scenario();
        $this->release($ctx);

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);
        $reviewId = (int) $this->postJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grade-reviews', [
            'reason' => 'Mohon ditinjau.',
        ])->assertCreated()->json('data.id');

        $this->actingAsTeacher($ctx['world']['teacher']);
        $this->postJson('/api/v1/lms/grade-reviews/'.$reviewId.'/comments', [
            'note' => 'Sedang saya periksa rubriknya.',
        ])->assertOk();

        $this->assertDatabaseHas('lms_grade_review_events', ['action' => 'COMMENTED', 'note' => 'Sedang saya periksa rubriknya.']);
    }

    public function test_wali_melihat_status_banding_anak_tanpa_catatan_privat_guru(): void
    {
        $ctx = $this->scenario();
        $this->release($ctx);

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);
        $reviewId = (int) $this->postJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grade-reviews', [
            'reason' => 'Mohon ditinjau.',
        ])->assertCreated()->json('data.id');

        $this->actingAsTeacher($ctx['world']['teacher']);
        $this->postJson('/api/v1/lms/grade-reviews/'.$reviewId.'/reject', ['note' => 'Catatan internal guru.'])->assertOk();

        $guardian = \App\Models\Guardian::create([
            'full_name' => 'Wali Siswa',
            'phone' => '08123456789',
            'is_active' => true,
        ]);

        \App\Models\StudentGuardian::create([
            'student_id' => $ctx['world']['students'][0]->id,
            'guardian_id' => $guardian->id,
            'relation' => 'ayah',
            'is_primary' => true,
        ]);

        $this->actingAsParent($guardian);

        $response = $this->getJson('/api/v1/lms/children/'.$ctx['student'].'/grades')->assertOk();

        $reviews = $response->json('data.reviews');
        $this->assertCount(1, $reviews);
        $this->assertSame('REJECTED', $reviews[0]['status']);
        $this->assertNull($reviews[0]['teacher_note'], 'Catatan privat guru tidak boleh terlihat wali (§33).');
    }
}
