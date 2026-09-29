<?php

namespace Tests\Feature\Lms;

use App\Models\Assessment;
use App\Models\Score;
use App\Modules\LMS\Application\Services\AcademicIntegrationService;
use App\Modules\LMS\Application\Services\GradeReleaseService;
use App\Modules\LMS\Application\Services\GradeSyncService;
use App\Modules\LMS\Domain\Enums\GradeSyncStatus;
use App\Modules\LMS\Models\LmsGradeSync;
use Database\Seeders\AssessmentTypeSeeder;

/**
 * Integrasi nilai ke modul akademik (§39–§42, §63).
 *
 * LMS tidak pernah menulis KHS/Rapor: ia mengirim nilai akhir sebagai satu
 * penilaian akademik, dan bila integrasi tidak aktif LMS tetap menjadi sumber
 * nilai yang sah.
 */
class LmsAcademicIntegrationTest extends LmsTestCase
{
    /** @return array<string, mixed> */
    private function scenario(int $studentCount = 1): array
    {
        $this->seed(AssessmentTypeSeeder::class);

        $world = $this->world($studentCount);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $item = $this->makeManualItem($scenario['gradebook'], ['name' => 'Nilai Akhir Semester']);

        foreach ($scenario['user_ids'] as $index => $userId) {
            $this->recordGrade($item, $userId, 90.0 - $index * 10);
        }

        return [
            'world' => $world,
            'course' => $course,
            'gradebook' => $scenario['gradebook'],
            'item' => $item,
            'students' => $scenario['user_ids'],
        ];
    }

    public function test_mode_mandiri_menjadikan_lms_sumber_nilai_resmi(): void
    {
        // Data uji dibangun lebih dulu (mode Integrated), lalu mode mandiri
        // diaktifkan: kontrak yang sama harus tetap bekerja tanpa SIAKAD (§63).
        $ctx = $this->scenario();

        config(['lms.standalone' => true, 'lms.integration.push_grades' => false]);

        $integration = app(AcademicIntegrationService::class);

        $this->assertSame('standalone', $integration->provider()->key());
        $this->assertFalse($integration->status()['push_enabled']);
        $this->assertStringContainsString('mandiri', $integration->status()['message']);

        $payload = $integration->payload($ctx['gradebook'], $ctx['students'][0]);
        $response = $integration->provider()->push($payload);

        $this->assertFalse($response['accepted']);
        $this->assertStringContainsString('mandiri', $response['message']);

        // Tanpa SIAKAD: tidak ada yang ditulis ke modul akademik, nilai LMS tetap sah.
        $this->assertSame(0, Assessment::query()->count());
        $this->assertSame(0, Score::query()->count());
        $this->assertEqualsWithDelta(
            90.0,
            (float) $ctx['item']->resultFor($ctx['students'][0])?->final_score,
            0.01,
        );
    }

    public function test_integrasi_belum_diaktifkan_tidak_menggagalkan_nilai_lms(): void
    {
        config(['lms.standalone' => false, 'lms.integration.push_grades' => false]);

        $ctx = $this->scenario();
        $integration = app(AcademicIntegrationService::class);

        $this->assertSame('siakad', $integration->provider()->key());
        $this->assertFalse($integration->provider()->isAvailable());

        $admin = $this->actingAsRole('admin-sekolah');
        $result = app(GradeSyncService::class)->syncStudent($ctx['gradebook'], $ctx['students'][0], $this->actorFor($admin));

        $this->assertSame(GradeSyncStatus::SKIPPED->value, $result['status']);
        $this->assertStringContainsString('belum diaktifkan', (string) $result['error_message']);
        $this->assertEqualsWithDelta(
            90.0,
            (float) $ctx['item']->resultFor($ctx['students'][0])?->final_score,
            0.01,
            'Nilai LMS tetap utuh walau sinkronisasi tidak aktif.',
        );
    }

    public function test_sinkronisasi_integrated_menulis_penilaian_akademik_dan_mengunci_nilai(): void
    {
        config(['lms.standalone' => false, 'lms.integration.push_grades' => true]);

        $ctx = $this->scenario();
        // Rilis dilakukan guru; sinkronisasi ke SIAKAD adalah kewenangan admin (§52).
        $teacherActor = $this->actorFor($this->actingAsTeacher($ctx['world']['teacher']));
        app(GradeReleaseService::class)->releaseStudent($ctx['gradebook'], $ctx['students'][0], $teacherActor);

        $admin = $this->actingAsRole('admin-sekolah');
        $actor = $this->actorFor($admin);

        $response = $this->postJson('/api/v1/lms/courses/'.$ctx['course']->id.'/grades/sync', [
            'user_ids' => [$ctx['students'][0]],
        ])->assertOk();

        $this->assertSame(1, $response->json('data.synced'));
        $this->assertSame(0, $response->json('data.failed'));

        $assessment = Assessment::query()->firstOrFail();
        $this->assertSame('draft', $assessment->status);
        $this->assertSame($ctx['course']->academic_course_id, (int) $assessment->subject_id);
        $this->assertSame($ctx['gradebook']->id, (int) $assessment->extra['lms_gradebook_id']);

        $score = Score::query()->firstOrFail();
        $this->assertEqualsWithDelta(90.0, (float) $score->score, 0.01);
        $this->assertSame('A', $score->grade);
        $this->assertStringContainsString('Rincian LMS', (string) $score->notes);

        // §42: setelah terkirim, nilai dikunci.
        $sync = LmsGradeSync::query()->firstOrFail();
        $this->assertSame(GradeSyncStatus::SYNCED, $sync->status);
        $this->assertTrue($sync->isLocked());
        $this->assertDatabaseHas('lms_grade_releases', ['user_id' => $ctx['students'][0], 'status' => 'LOCKED']);

        // §39: LMS tidak membuat rapor kedua.
        $this->assertDatabaseCount('report_cards', 0);
    }

    public function test_sinkronisasi_bersifat_idempotent(): void
    {
        config(['lms.standalone' => false, 'lms.integration.push_grades' => true]);

        $ctx = $this->scenario();
        $admin = $this->actingAsRole('admin-sekolah');
        $actor = $this->actorFor($admin);
        $sync = app(GradeSyncService::class);

        app(GradeReleaseService::class)->releaseStudent($ctx['gradebook'], $ctx['students'][0], $actor);

        $first = $sync->syncStudent($ctx['gradebook'], $ctx['students'][0], $actor);
        $this->assertFalse($first['unchanged']);

        // Nilai terkunci setelah sinkronisasi pertama; pembukaan kunci berizin
        // lalu kirim ulang tidak boleh membuat penilaian baru (hash payload sama).
        $stored = LmsGradeSync::query()->firstOrFail();
        $sync->unlock($stored, $actor, 'Pengujian idempotensi');

        $second = app(GradeSyncService::class)->syncStudent($ctx['gradebook'], $ctx['students'][0], $actor);

        $this->assertSame(1, Assessment::query()->count(), 'Sinkronisasi ulang tidak boleh membuat penilaian kedua.');
        $this->assertSame(1, Score::query()->count());
        $this->assertSame(GradeSyncStatus::SYNCED->value, $second['status']);
    }

    public function test_payload_kontrak_akademik_lengkap(): void
    {
        config(['lms.standalone' => false, 'lms.integration.push_grades' => true]);

        $ctx = $this->scenario();
        $this->actingAsTeacher($ctx['world']['teacher']);

        $response = $this->getJson('/api/v1/lms/courses/'.$ctx['course']->id.'/grades/payload/'.$ctx['students'][0])
            ->assertOk();

        $payload = $response->json('data.payload');

        $this->assertSame('academic_grade.v1', $payload['contract']);
        $this->assertSame($ctx['course']->id, $payload['course']['lms_course_id']);
        $this->assertSame($ctx['course']->academic_course_id, $payload['academic']['academic_course_id']);
        $this->assertSame($ctx['course']->class_id, $payload['academic']['class_id']);
        $this->assertSame($ctx['course']->semester_id, $payload['academic']['semester_id']);
        $this->assertSame($ctx['students'][0], $payload['student']['user_id']);
        $this->assertNotNull($payload['student']['student_id']);
        $this->assertEqualsWithDelta(90.0, (float) $payload['result']['final_grade'], 0.01);
        $this->assertSame('A', $payload['result']['letter']);
        $this->assertNotEmpty($payload['components']);
        $this->assertSame('siakad', $response->json('data.provider.key'));
    }

    public function test_course_tanpa_tautan_akademik_tidak_menggagalkan_sinkronisasi(): void
    {
        config(['lms.standalone' => false, 'lms.integration.push_grades' => true]);

        $ctx = $this->scenario();

        // Course tanpa kelas/mapel SIAKAD: nilai tetap sah di LMS (§63).
        $ctx['course']->forceFill([
            'academic_course_id' => null,
            'class_id' => null,
            'semester_id' => null,
        ])->save();

        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $payload = app(AcademicIntegrationService::class)->payload($ctx['gradebook']->refresh(), $ctx['students'][0]);
        $response = app(AcademicIntegrationService::class)->provider()->push($payload);

        $this->assertFalse($response['accepted']);
        $this->assertStringContainsString('belum terhubung', $response['message']);
        $this->assertSame(0, Assessment::query()->count());
    }

    public function test_siswa_tidak_dapat_menjalankan_sinkronisasi(): void
    {
        $ctx = $this->scenario();

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);

        $this->postJson('/api/v1/lms/courses/'.$ctx['course']->id.'/grades/sync', [
            'user_ids' => [$ctx['students'][0]],
        ])->assertForbidden();
    }

    public function test_status_sinkronisasi_merekam_jejak_audit(): void
    {
        config(['lms.standalone' => false, 'lms.integration.push_grades' => true]);

        $ctx = $this->scenario(2);
        $teacherActor = $this->actorFor($this->actingAsTeacher($ctx['world']['teacher']));

        app(GradeReleaseService::class)->releaseStudent($ctx['gradebook'], $ctx['students'][0], $teacherActor);
        app(GradeReleaseService::class)->releaseStudent($ctx['gradebook'], $ctx['students'][1], $teacherActor);

        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/lms/courses/'.$ctx['course']->id.'/grades/sync', [
            'user_ids' => $ctx['students'],
        ])->assertOk();

        $response = $this->getJson('/api/v1/lms/courses/'.$ctx['course']->id.'/gradebook/releases')->assertOk();

        $this->assertSame(2, $response->json('data.sync.synced'));
        $this->assertSame(0, $response->json('data.sync.failed'));
        $this->assertSame(2, $response->json('data.sync.locked'));
        $this->assertNotNull($response->json('data.sync.last_synced_at'));

        $this->assertDatabaseHas('lms_grade_histories', ['action' => 'SYNCED', 'source' => 'SYNC']);
        $this->assertSame(2, \App\Modules\LMS\Models\LmsGradeHistory::query()->where('action', 'SYNCED')->count());
    }
}
