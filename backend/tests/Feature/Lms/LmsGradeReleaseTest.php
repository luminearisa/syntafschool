<?php

namespace Tests\Feature\Lms;

use App\Models\Guardian;
use App\Models\StudentGuardian;
use App\Modules\LMS\Application\Services\AssessmentResultService;
use App\Modules\LMS\Application\Services\GradebookService;
use App\Modules\LMS\Application\Services\GradeCorrectionService;
use App\Modules\LMS\Application\Services\GradeReleaseService;
use App\Modules\LMS\Domain\Enums\AssessmentResultStatus;
use App\Modules\LMS\Domain\Enums\WeightPolicy;
use App\Modules\LMS\Models\LmsGradeHistory;
use App\Modules\LMS\Models\LmsGradeRelease;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Rilis, kunci, koreksi nilai, riwayat audit, dan ekspor (§16–§19, §38, §42).
 */
class LmsGradeReleaseTest extends LmsTestCase
{
    /** @return array<string, mixed> */
    private function scenario(int $studentCount = 2): array
    {
        $world = $this->world($studentCount);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $item = $this->makeManualItem($scenario['gradebook'], ['name' => 'Ujian Akhir', 'passing_grade' => 70]);

        return [
            'world' => $world,
            'course' => $course,
            'gradebook' => $scenario['gradebook'],
            'item' => $item,
            'students' => $scenario['user_ids'],
        ];
    }

    public function test_nilai_belum_dirilis_tidak_terlihat_siswa(): void
    {
        $ctx = $this->scenario(1);
        $this->recordGrade($ctx['item'], $ctx['students'][0], 88);

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);
        $before = $this->getJson('/api/v1/lms/my/grades')->assertOk();

        $this->assertFalse($before->json('data.courses.0.is_released'));
        $this->assertNull($before->json('data.courses.0.current_grade'));
        $this->assertSame('Belum dirilis', $before->json('data.courses.0.display.text'));
        $this->assertNull($before->json('data.courses.0.items.0.score'));
    }

    public function test_rilis_individu_membuat_snapshot_dan_memberi_akses_siswa(): void
    {
        $ctx = $this->scenario(1);
        $this->recordGrade($ctx['item'], $ctx['students'][0], 88);

        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $release = app(GradeReleaseService::class)->releaseStudent(
            $ctx['gradebook'],
            $ctx['students'][0],
            $this->actorFor($teacher),
        );

        $this->assertSame('RELEASED', $release['status']);
        $this->assertEqualsWithDelta(88.0, (float) $release['normalized_score'], 0.01);
        $this->assertNotEmpty($release['components'], 'Snapshot perhitungan wajib tersimpan (§16).');

        $stored = LmsGradeRelease::query()->firstOrFail();
        $this->assertNotNull($stored->calculation_snapshot);
        $this->assertSame($ctx['gradebook']->configHash(), $stored->config_hash);

        // Hasil per item ikut berubah menjadi RELEASED (§17).
        $this->assertSame(
            AssessmentResultStatus::RELEASED,
            $ctx['item']->resultFor($ctx['students'][0])?->status,
        );

        $this->assertDatabaseHas('lms_grade_histories', [
            'gradebook_id' => $ctx['gradebook']->id,
            'user_id' => $ctx['students'][0],
            'action' => 'RELEASED',
        ]);

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);
        $after = $this->getJson('/api/v1/lms/my/grades')->assertOk();

        $this->assertTrue($after->json('data.courses.0.is_released'));
        $this->assertEqualsWithDelta(88.0, (float) $after->json('data.courses.0.current_grade'), 0.01);
        $this->assertSame('B', $after->json('data.courses.0.letter'));
        $this->assertEqualsWithDelta(88.0, (float) $after->json('data.courses.0.items.0.score'), 0.01);
    }

    public function test_rilis_sebagian_melompati_siswa_tanpa_nilai(): void
    {
        $ctx = $this->scenario(2);
        $this->recordGrade($ctx['item'], $ctx['students'][0], 90);

        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $result = app(GradeReleaseService::class)->releaseSelected(
            $ctx['gradebook'],
            $ctx['students'],
            $this->actorFor($teacher),
        );

        $this->assertCount(1, $result['released']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame('Belum ada nilai yang dapat dirilis.', $result['skipped'][0]['reason']);
        $this->assertDatabaseCount('lms_grade_releases', 1);
    }

    public function test_rilis_semua_melalui_api(): void
    {
        $ctx = $this->scenario(2);
        $this->recordGrade($ctx['item'], $ctx['students'][0], 90);
        $this->recordGrade($ctx['item'], $ctx['students'][1], 60);

        $this->actingAsTeacher($ctx['world']['teacher']);

        $this->postJson('/api/v1/lms/courses/'.$ctx['course']->id.'/gradebook/release', ['scope' => 'all'])
            ->assertOk()
            ->assertJsonPath('data.skipped', []);

        $this->assertDatabaseCount('lms_grade_releases', 2);
        $this->assertSame(1, LmsGradeRelease::query()->where('passed', true)->count());
        $this->assertSame(1, LmsGradeRelease::query()->where('passed', false)->count());
    }

    public function test_rilis_dapat_dibatalkan(): void
    {
        $ctx = $this->scenario(1);
        $this->recordGrade($ctx['item'], $ctx['students'][0], 85);

        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);
        $releaseService = app(GradeReleaseService::class);
        $releaseService->releaseStudent($ctx['gradebook'], $ctx['students'][0], $actor);

        $this->postJson('/api/v1/lms/grade-releases/'.LmsGradeRelease::query()->firstOrFail()->id.'/revoke', [
            'reason' => 'Salah input nilai',
        ])->assertOk()->assertJsonPath('data.status', 'REVOKED');

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);
        $response = $this->getJson('/api/v1/lms/my/grades')->assertOk();

        $this->assertFalse($response->json('data.courses.0.is_released'));
        $this->assertDatabaseHas('lms_grade_histories', ['action' => 'REVOKED', 'reason' => 'Salah input nilai']);
    }

    public function test_nilai_terkunci_hanya_dapat_diubah_dengan_izin_koreksi(): void
    {
        $ctx = $this->scenario(1);
        $this->recordGrade($ctx['item'], $ctx['students'][0], 80);

        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);
        $releaseService = app(GradeReleaseService::class);
        $releaseService->releaseStudent($ctx['gradebook'], $ctx['students'][0], $actor);
        $releaseService->lock(LmsGradeRelease::query()->firstOrFail(), $actor, 'Setelah sinkronisasi');

        $result = $ctx['item']->resultFor($ctx['students'][0]);
        $this->assertSame(AssessmentResultStatus::LOCKED, $result?->status);

        // Guru tanpa izin koreksi ditolak: entah 403 (kebijakan) atau 422 (validasi domain).
        $attempt = $this->putJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grades/'.$ctx['students'][0], [
            'score' => 95,
        ]);

        $this->assertContains(
            $attempt->status(),
            [403, 422],
            'Nilai terkunci seharusnya menolak perubahan tanpa izin koreksi.',
        );

        $this->assertEqualsWithDelta(80.0, (float) $ctx['item']->resultFor($ctx['students'][0])?->final_score, 0.01);
    }

    public function test_koreksi_nilai_menyimpan_riwayat_lama_dan_baru(): void
    {
        $ctx = $this->scenario(1);
        $this->recordGrade($ctx['item'], $ctx['students'][0], 85);

        $admin = $this->actingAsRole('admin-sekolah');
        $result = $ctx['item']->resultFor($ctx['students'][0]);

        $correction = app(GradeCorrectionService::class)->correct(
            $result,
            90.0,
            'Salah hitung poin esai',
            $this->actorFor($admin),
        );

        $this->assertSame(85.0, $correction['previous']);
        $this->assertSame(90.0, $correction['corrected']);
        $this->assertEqualsWithDelta(90.0, (float) $correction['result']->final_score, 0.01);

        $history = LmsGradeHistory::query()
            ->where('assessment_result_id', $result->id)
            ->orderBy('created_at')
            ->get();

        $this->assertTrue($history->contains('action', \App\Modules\LMS\Domain\Enums\GradeHistoryAction::CORRECTED));
        $corrected = $history->firstWhere('action', \App\Modules\LMS\Domain\Enums\GradeHistoryAction::CORRECTED);
        $this->assertSame(85.0, (float) $corrected->previous_value['final_score']);
        $this->assertSame(90.0, (float) $corrected->new_value['final_score']);
        $this->assertSame('Salah hitung poin esai', $corrected->reason);
    }

    public function test_riwayat_nilai_bersifat_immutable(): void
    {
        $ctx = $this->scenario(1);
        $this->recordGrade($ctx['item'], $ctx['students'][0], 70);

        $history = LmsGradeHistory::query()->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $history->update(['reason' => 'diubah diam-diam']);
    }

    public function test_rilis_ditolak_bila_kebijakan_bobot_wajib_100_belum_terpenuhi(): void
    {
        $ctx = $this->scenario(1);
        $this->recordGrade($ctx['item'], $ctx['students'][0], 70);
        $this->makeGradeCategory($ctx['gradebook'], 'Tugas', 40);

        $ctx['gradebook']->forceFill(['weight_policy' => WeightPolicy::STRICT->value])->save();

        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);

        $this->expectException(ValidationException::class);

        app(GradeReleaseService::class)->releaseStudent(
            $ctx['gradebook']->refresh(),
            $ctx['students'][0],
            $this->actorFor($teacher),
        );
    }

    public function test_gradebook_dibekukan_menolak_rilis(): void
    {
        $ctx = $this->scenario(1);
        $this->recordGrade($ctx['item'], $ctx['students'][0], 70);

        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        app(GradebookService::class)->freeze($ctx['gradebook'], $this->actorFor($teacher));

        $this->expectException(HttpException::class);

        app(GradeReleaseService::class)->releaseStudent(
            $ctx['gradebook']->refresh(),
            $ctx['students'][0],
            $this->actorFor($teacher),
        );
    }

    public function test_ekspor_csv_mengikuti_filter_dan_memuat_rincian(): void
    {
        $ctx = $this->scenario(2);
        $this->recordGrade($ctx['item'], $ctx['students'][0], 92, ['feedback' => 'Sangat baik']);
        $this->recordGrade($ctx['item'], $ctx['students'][1], 55);

        $this->actingAsTeacher($ctx['world']['teacher']);

        $response = $this->get('/api/v1/lms/courses/'.$ctx['course']->id.'/gradebook/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));

        $csv = (string) $response->getContent();

        $this->assertStringContainsString('Siswa', $csv);
        $this->assertStringContainsString('Ujian Akhir', $csv);
        $this->assertStringContainsString('Nilai akhir', $csv);
        $this->assertStringContainsString('92', $csv);
        $this->assertStringContainsString('55', $csv);
    }

    public function test_ekspor_format_belum_tersedia_ditolak_dengan_jelas(): void
    {
        $ctx = $this->scenario(1);
        $this->actingAsTeacher($ctx['world']['teacher']);

        $this->getJson('/api/v1/lms/courses/'.$ctx['course']->id.'/gradebook/export?format=xlsx')
            ->assertStatus(422);
    }

    public function test_status_rilis_dan_integrasi_dapat_dipantau(): void
    {
        $ctx = $this->scenario(1);
        $this->recordGrade($ctx['item'], $ctx['students'][0], 77);

        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        app(GradeReleaseService::class)->releaseStudent($ctx['gradebook'], $ctx['students'][0], $this->actorFor($teacher));

        $this->getJson('/api/v1/lms/courses/'.$ctx['course']->id.'/gradebook/releases')
            ->assertOk()
            ->assertJsonPath('data.sync.integration.standalone', false)
            ->assertJsonStructure(['data' => ['releases', 'sync' => ['total', 'synced', 'failed', 'rows'], 'integration' => ['provider', 'available']]]);
    }

    public function test_hasil_penilaian_dapat_dikembalikan_dengan_umpan_balik(): void
    {
        $ctx = $this->scenario(1);
        $this->recordGrade($ctx['item'], $ctx['students'][0], 82, [
            'feedback' => 'Analisis kuat, kesimpulan perlu diperdalam.',
            'feedback_meta' => ['what_went_well' => 'Analisis', 'what_to_improve' => 'Kesimpulan'],
        ]);

        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);

        $this->postJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/grades/return', [
            'user_ids' => $ctx['students'],
        ])->assertOk()->assertJsonPath('data.returned', 1);

        $result = $ctx['item']->resultFor($ctx['students'][0]);
        $this->assertSame(AssessmentResultStatus::RETURNED, $result?->status);
        $this->assertTrue($result?->isVisibleToStudent());
        $this->assertSame('Analisis kuat, kesimpulan perlu diperdalam.', $result?->feedback);

        $service = app(AssessmentResultService::class);
        $this->assertNotEmpty($service->historyFor($result));
    }
}
