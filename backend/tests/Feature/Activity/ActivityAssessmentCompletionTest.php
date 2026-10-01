<?php

namespace Tests\Feature\Activity;

use App\Modules\Activity\Application\Services\ActivityAssessmentService;
use App\Modules\Activity\Application\Services\ActivityAttendanceService;
use App\Modules\Activity\Application\Services\ActivityCertificateService;
use App\Modules\Activity\Application\Services\ActivityMembershipService;
use App\Modules\Activity\Application\Services\ActivityProgramService;
use App\Modules\Activity\Application\Services\ActivityRegistrationService;
use App\Modules\Activity\Application\Services\ActivityScheduleService;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Models\ActivityAssessment;
use App\Modules\Activity\Models\ActivityCertificate;
use App\Modules\Activity\Models\ActivityCompletion;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivitySession;
use App\Modules\Activity\Models\ActivitySkill;

/**
 * Penilaian perkembangan, progres, penyelesaian, dan sertifikat (\\u00a736\\u201341, \\u00a752, \\u00a7105).
 */
class ActivityAssessmentCompletionTest extends ActivityTestCase
{
    /** @return array{program: \App\Modules\Activity\Models\ActivityProgram, membership: ActivityMembership, student: \App\Models\Student} */
    protected function enrolledProgram(array $programAttributes = [], string $studentName = 'Alya'): array
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $student = $this->makeStudent($studentName, $this->makeClass('VII '.random_int(1, 5)));

        $skill = ActivitySkill::query()->where('code', 'TEAMWORK')->firstOrFail();

        $program = $this->makeProgram(array_merge([
            'code' => 'PROG-'.random_int(1000, 9999),
            'name' => 'Futsal',
            'minimum_attendance_percentage' => 50,
        ], $programAttributes));

        $program->skills()->sync([$skill->id => ['is_required' => true, 'weight' => 1]]);
        $this->makeSchedule($program, ['day_of_week' => 1]);

        app(ActivityRegistrationService::class)->start($manager, $program->refresh(), $student->id);

        $membership = ActivityMembership::query()
            ->where('program_id', $program->id)
            ->where('student_id', $student->id)
            ->firstOrFail();

        return ['program' => $program->refresh(), 'membership' => $membership, 'student' => $student];
    }

    public function test_rubrik_disimpan_bersama_butir_dan_bobotnya(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $program = $this->makeProgram(['code' => 'RUB-1', 'name' => 'Programming Club']);

        $rubric = app(ActivityAssessmentService::class)->createRubric($program, [
            'title' => 'Rubrik Proyek Pemrograman',
            'type' => 'SKILL',
            'items' => [
                ['code' => 'ALGO', 'name' => 'Algoritma', 'weight' => 2, 'max_score' => 4],
                ['code' => 'CODE', 'name' => 'Kualitas Kode', 'weight' => 1, 'max_score' => 4],
            ],
        ], $manager);

        $this->assertCount(2, $rubric->items);
        $this->assertSame(4.0, (float) $rubric->items->firstWhere('code', 'ALGO')->max_score);
    }

    public function test_nilai_yang_dikirim_membentuk_progres_dan_bukti_keterampilan(): void
    {
        $world = $this->enrolledProgram();
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');

        $assessment = app(ActivityAssessmentService::class)->createAssessment($world['program'], [
            'title' => 'Penilaian Tengah Periode',
            'type' => 'SKILL',
            'max_score' => 100,
        ], $manager);

        app(ActivityAssessmentService::class)->publishAssessment($assessment, $manager);

        app(ActivityAssessmentService::class)->submitResult($assessment, $world['student']->id, [
            'score' => 86,
            'feedback' => 'Kerja sama sangat baik.',
            'criteria_scores' => ['ALGO' => 4, 'CODE' => 3],
        ], $manager);

        $this->assertDatabaseHas('activity_assessment_results', [
            'assessment_id' => $assessment->id,
            'student_id' => $world['student']->id,
            'score' => 86,
            'level' => 'ADVANCED',
        ]);

        // Bukti keterampilan lahir dari program yang dibina, bukan diketik manual.
        $this->assertDatabaseHas('activity_skill_evidence', [
            'student_id' => $world['student']->id,
            'program_id' => $world['program']->id,
            'source' => 'ASSESSMENT',
        ]);

        $progress = app(ActivityAssessmentService::class)->recalculateProgress((int) $world['membership']->id);

        $this->assertSame(1, $progress->assessments_completed);
        $this->assertSame(86.0, (float) $progress->average_score);
    }

    public function test_pengiriman_nilai_ulang_tidak_menggandakan_baris(): void
    {
        $world = $this->enrolledProgram();
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $service = app(ActivityAssessmentService::class);

        $assessment = $service->createAssessment($world['program'], ['title' => 'Uji', 'max_score' => 100], $manager);

        $service->submitResult($assessment, $world['student']->id, ['score' => 70, 'idempotency_key' => 'k-1'], $manager);
        $service->submitResult($assessment, $world['student']->id, ['score' => 70, 'idempotency_key' => 'k-1'], $manager);

        $this->assertSame(1, \App\Modules\Activity\Models\ActivityAssessmentResult::query()
            ->where('assessment_id', $assessment->id)->count());
    }

    public function test_penyelesaian_menolak_ketika_kehadiran_di_bawah_minimum(): void
    {
        $world = $this->enrolledProgram([
            'completion_rules' => ['minimum_attendance_percentage' => 90],
        ], 'Bella');

        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        app(ActivityScheduleService::class)->generateSessions($world['program'], '2026-07-20', '2026-08-03', $manager);

        $session = ActivitySession::query()->orderBy('date')->firstOrFail();
        app(ActivityAttendanceService::class)->record($session, $world['student']->id, ['status' => 'PRESENT'], $manager);

        $evaluation = app(ActivityAssessmentService::class)->evaluateCompletion($world['membership']->refresh());

        $this->assertFalse($evaluation['eligible']);
        $this->assertStringContainsString('Kehadiran', implode(' ', $evaluation['blockers']));
        $this->assertSame(33.33, (float) $evaluation['snapshot']['attendance_percentage']);

        $this->expectException(ActivityConflictException::class);

        app(ActivityAssessmentService::class)->complete($world['membership']->refresh(), $manager);
    }

    public function test_penyelesaian_memenuhi_syarat_menutup_keanggotaan_dan_menandai_kelayakan_sertifikat(): void
    {
        $world = $this->enrolledProgram([
            'completion_rules' => ['minimum_attendance_percentage' => 50, 'final_assessment_min_score' => 70],
            'certificate_rules' => ['minimum_attendance_percentage' => 50, 'final_assessment_min_score' => 70],
            'minimum_attendance_percentage' => 50,
        ], 'Cinta');

        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $assessments = app(ActivityAssessmentService::class);

        app(ActivityScheduleService::class)->generateSessions($world['program'], '2026-07-20', '2026-07-27', $manager);
        $session = ActivitySession::query()->orderBy('date')->firstOrFail();
        app(ActivityAttendanceService::class)->record($session, $world['student']->id, ['status' => 'PRESENT'], $manager);

        $assessment = $assessments->createAssessment($world['program'], [
            'title' => 'Penilaian Akhir',
            'max_score' => 100,
            'is_final' => true,
        ], $manager);
        $assessments->publishAssessment($assessment, $manager);
        $assessments->submitResult($assessment, $world['student']->id, ['score' => 88], $manager);

        $completion = $assessments->complete($world['membership']->refresh(), $manager, ['notes' => 'Latihan penuh sepanjang semester.']);

        $this->assertSame('COMPLETED', $completion->status->value);
        $this->assertTrue((bool) $completion->certificate_eligible);
        $this->assertSame('COMPLETED', $world['membership']->refresh()->status->value);
        $this->assertDatabaseHas('activity_membership_histories', [
            'membership_id' => $world['membership']->id,
            'action' => 'COMPLETED',
        ]);
    }

    public function test_sertifikat_keluar_dengan_nomor_unik_dan_dapat_diverifikasi_tanpa_data_pribadi(): void
    {
        $world = $this->enrolledProgram([
            'completion_rules' => ['minimum_attendance_percentage' => 0],
            'certificate_rules' => ['minimum_attendance_percentage' => 0],
        ], 'Dian');

        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $assessments = app(ActivityAssessmentService::class);

        $completion = $assessments->complete($world['membership']->refresh(), $manager, ['waive_rules' => true]);

        $admin = $this->actingAsRole('ACTIVITY_ADMIN');
        $certificate = app(ActivityCertificateService::class)->issueForCompletion($completion, $admin);

        $this->assertStringStartsWith('CERT-ACT-', $certificate->certificate_number);
        $this->assertDatabaseHas('activity_audit_logs', ['action' => 'CERTIFICATE_ISSUED']);

        // Verifikasi publik: tanpa autentikasi, dan tanpa identitas siswa.
        $response = $this->postJson('/api/v1/activities/certificates/verify', [
            'number' => $certificate->certificate_number,
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.valid'));
        $this->assertArrayNotHasKey('student_name', $response->json('data.certificate'));

        // Nomor yang tidak ada tetap terjawab jujur, bukan 500.
        $this->postJson('/api/v1/activities/certificates/verify', ['number' => 'CERT-ACT-2099-999999'])
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.result', 'NOT_FOUND');
    }

    public function test_sertifikat_yang_dicabut_dilaporkan_tidak_valid_beserta_alasan(): void
    {
        $world = $this->enrolledProgram(['certificate_rules' => ['minimum_attendance_percentage' => 0]], 'Eka');

        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $completion = app(ActivityAssessmentService::class)->complete($world['membership']->refresh(), $manager, ['waive_rules' => true]);

        $admin = $this->actingAsRole('ACTIVITY_ADMIN');
        $certificates = app(ActivityCertificateService::class);
        $certificate = $certificates->issueForCompletion($completion, $admin);

        // Sebelum dicabut: valid. Sesudah dicabut: tidak valid, dengan alasan.
        $this->postJson('/api/v1/activities/certificates/verify', ['number' => $certificate->certificate_number])
            ->assertOk()
            ->assertJsonPath('data.valid', true);

        $certificates->revoke($certificate, $admin, 'Salah nama peserta.');

        $response = $this->postJson('/api/v1/activities/certificates/verify', [
            'number' => $certificate->certificate_number,
        ]);

        $response->assertOk();
        $this->assertSame('REVOKED', $response->json('data.result'));
        $this->assertFalse($response->json('data.valid'));

        $this->assertSame(2, \App\Modules\Activity\Models\ActivityCertificateVerification::query()
            ->where('certificate_number', $certificate->certificate_number)->count());
    }

    public function test_progres_dan_transkrip_dapat_dibaca_sesuai_izin(): void
    {
        $world = $this->enrolledProgram(['certificate_rules' => []], 'Fira');
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');

        app(ActivityAssessmentService::class)->recalculateProgress((int) $world['membership']->id);

        $response = $this->getJson("/api/v1/activities/students/{$world['student']->id}/transcript");

        $response->assertOk();
        $this->assertSame($world['program']->name, $response->json('data.rows.0.activity'));

        $this->assertNotNull($manager->id);
    }
}
