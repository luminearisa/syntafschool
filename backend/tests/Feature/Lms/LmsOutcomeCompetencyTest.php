<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Application\Services\CompetencyAssessmentService;
use App\Modules\LMS\Application\Services\CompetencyService;
use App\Modules\LMS\Application\Services\LearningOutcomeService;
use App\Modules\LMS\Application\Services\OutcomeAssessmentService;
use App\Modules\LMS\Domain\Enums\CompetencyStatus;
use App\Modules\LMS\Domain\Enums\OutcomeAchievement;
use App\Modules\LMS\Models\LmsActivity;

/**
 * Learning outcome, capaiannya, dan kompetensi beserta buktinya
 * (§26–§31, §48).
 */
class LmsOutcomeCompetencyTest extends LmsTestCase
{
    /** @return array<string, mixed> */
    private function scenario(int $studentCount = 1): array
    {
        $world = $this->world($studentCount);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->firstOrFail();
        $scenario = $this->gradebookScenario($world, $course);

        $activity = $this->publishActivity($this->makeActivity($section, ['title' => 'Proyek Aljabar']));
        $item = \App\Modules\LMS\Models\LmsGradeItem::query()->where('activity_id', $activity->id)->firstOrFail();

        return [
            'world' => $world,
            'course' => $course,
            'section' => $section,
            'activity' => $activity,
            'item' => $item,
            'gradebook' => $scenario['gradebook'],
            'students' => $scenario['user_ids'],
        ];
    }

    public function test_learning_outcome_dibuat_dan_dipetakan_ke_aktivitas(): void
    {
        $ctx = $this->scenario();
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);

        $outcome = app(LearningOutcomeService::class)->create($ctx['course'], [
            'statement' => 'Memahami konsep aljabar.',
            'code' => 'LO1',
            'achievement_thresholds' => ['developing' => 60, 'achieved' => 75, 'mastered' => 90],
            'activity_ids' => [$ctx['activity']->id],
        ], $actor);

        $this->assertSame('LO1', $outcome->code);
        $this->assertSame(
            [$ctx['activity']->id],
            $outcome->mappings->pluck('activity_id')->map(fn ($id) => (int) $id)->all(),
        );

        $coverage = app(LearningOutcomeService::class)->coverage($ctx['course']);

        $this->assertSame(1, $coverage['outcome_count']);
        $this->assertSame(1, $coverage['mapped_activity_count']);
        $this->assertSame([], $coverage['unmapped_activities']);
    }

    public function test_pemetaan_menolak_aktivitas_dari_course_lain(): void
    {
        $ctx = $this->scenario();

        // Course kedua pada dunia uji yang sama → aktivitas milik course lain.
        $otherCourse = $this->publishCourse(
            $this->makeCourse($ctx['world'], ['name' => 'Course Lain']),
            $ctx['world'],
        );

        $foreignActivity = $this->publishActivity(
            $this->makeActivity($otherCourse->sections()->firstOrFail(), ['title' => 'Aktivitas Asing'])
        );

        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);

        $outcome = app(LearningOutcomeService::class)->create($ctx['course'], [
            'statement' => 'Menyelesaikan persamaan linear.',
        ], $actor);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(LearningOutcomeService::class)->mapActivities($outcome, [$foreignActivity->id], $actor);
    }

    public function test_capaian_outcome_mengikuti_ambang_batas_yang_dapat_dikonfigurasi(): void
    {
        $ctx = $this->scenario();
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);
        $student = $ctx['students'][0];

        $outcome = app(LearningOutcomeService::class)->create($ctx['course'], [
            'statement' => 'Menerapkan aljabar pada masalah nyata.',
            'achievement_thresholds' => ['developing' => 50, 'achieved' => 70, 'mastered' => 90],
            'activity_ids' => [$ctx['activity']->id],
        ], $actor);

        $cases = [
            40.0 => OutcomeAchievement::NOT_YET,
            55.0 => OutcomeAchievement::DEVELOPING,
            80.0 => OutcomeAchievement::ACHIEVED,
            95.0 => OutcomeAchievement::MASTERED,
        ];

        foreach ($cases as $score => $expected) {
            $this->recordGrade($ctx['item'], $student, $score);

            $rows = app(OutcomeAssessmentService::class)->recalculate($outcome, [$student], $actor);

            $this->assertSame($expected->value, $rows[0]['status'], 'Skor '.$score.' menghasilkan status yang salah.');
            $this->assertEqualsWithDelta($score, (float) $rows[0]['score'], 0.01);
        }

        $stored = \App\Modules\LMS\Models\LmsStudentOutcome::query()->firstOrFail();
        $this->assertSame(OutcomeAchievement::MASTERED, $stored->status);
        $this->assertNotNull($stored->calculation);
    }

    public function test_outcome_tanpa_nilai_belum_dinilai(): void
    {
        $ctx = $this->scenario();
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);

        $outcome = app(LearningOutcomeService::class)->create($ctx['course'], [
            'statement' => 'Memahami konsep aljabar.',
            'activity_ids' => [$ctx['activity']->id],
        ], $actor);

        $rows = app(OutcomeAssessmentService::class)->recalculate($outcome, [$ctx['students'][0]], $actor);

        $this->assertSame(OutcomeAchievement::NOT_ASSESSED->value, $rows[0]['status']);
        $this->assertNull($rows[0]['score']);
        $this->assertSame(0, $rows[0]['evidence_count']);
    }

    public function test_rekap_capaian_kelas_dihitung_per_outcome(): void
    {
        $ctx = $this->scenario(2);
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);

        $outcome = app(LearningOutcomeService::class)->create($ctx['course'], [
            'statement' => 'Memahami konsep aljabar.',
            'activity_ids' => [$ctx['activity']->id],
        ], $actor);

        $this->recordGrade($ctx['item'], $ctx['students'][0], 85);
        $this->recordGrade($ctx['item'], $ctx['students'][1], 40);

        app(OutcomeAssessmentService::class)->recalculate($outcome, $ctx['students'], $actor);

        $achievement = collect(app(OutcomeAssessmentService::class)->courseAchievement($ctx['course']))->first();

        $this->assertSame(2, $achievement['student_count']);
        $this->assertSame(2, $achievement['assessed_count']);
        $this->assertSame(1, $achievement['achieved_count']);
        $this->assertEqualsWithDelta(50.0, (float) $achievement['achievement_rate'], 0.01);
    }

    public function test_kerangka_kompetensi_dan_pemetaannya(): void
    {
        $ctx = $this->scenario();
        $admin = $this->actingAsRole('admin-sekolah');
        $actor = $this->actorFor($admin);
        $service = app(CompetencyService::class);

        $framework = $service->createFramework(['name' => 'Programming', 'code' => 'PROG'], $actor);
        $algorithm = $service->createCompetency($framework, ['name' => 'Algorithm', 'code' => 'ALG'], $actor);
        $service->createCompetency($framework, ['name' => 'Data Structure'], $actor);

        $mapped = $service->mapToCourse($ctx['course'], [$algorithm->id], $actor);
        $this->assertCount(1, $mapped);
        $this->assertSame('Algorithm', $mapped[0]['name']);

        $activityMapped = $service->mapToActivity($ctx['activity'], [$algorithm->id], $actor);
        $this->assertCount(1, $activityMapped);
        $this->assertSame($algorithm->id, $activityMapped[0]['competency_id']);

        $frameworks = $service->frameworks();
        $this->assertSame(2, $frameworks[0]['competency_count']);
        $this->assertSame('Programming', $frameworks[0]['name']);
    }

    public function test_status_kompetensi_dinilai_guru_beserta_bukti(): void
    {
        $ctx = $this->scenario();
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);
        $student = $ctx['students'][0];

        $framework = app(CompetencyService::class)->createFramework(['name' => 'Problem Solving'], $actor);
        $competency = app(CompetencyService::class)->createCompetency($framework, ['name' => 'Dekomposisi Masalah'], $actor);
        app(CompetencyService::class)->mapToCourse($ctx['course'], [$competency->id], $actor);

        $this->recordGrade($ctx['item'], $student, 90);

        $record = app(CompetencyAssessmentService::class)->assess(
            $competency,
            $student,
            CompetencyStatus::COMPETENT,
            $actor,
            $ctx['course'],
            ['note' => 'Mampu memecah masalah kompleks.'],
        );

        $this->assertSame(CompetencyStatus::COMPETENT, $record->status);

        $evidence = app(CompetencyAssessmentService::class)
            ->recordActivityEvidence($record, $ctx['activity'], $actor, 'Bukti dari proyek aljabar.');

        $this->assertSame('ACTIVITY', $evidence->evidence_type->value);
        $this->assertEqualsWithDelta(90.0, (float) $evidence->score, 0.01);
        $this->assertSame($actor->id, (int) $evidence->recorded_by);

        $payload = app(CompetencyAssessmentService::class)->forStudent($ctx['course'], $student);
        $this->assertCount(1, $payload);
        $this->assertTrue($payload[0]['status'] === 'COMPETENT');
        $this->assertSame(1, count($payload[0]['evidence']));
    }

    public function test_kompetensi_tidak_diturunkan_otomatis_dari_nilai(): void
    {
        $ctx = $this->scenario();
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);
        $student = $ctx['students'][0];

        $framework = app(CompetencyService::class)->createFramework(['name' => 'Programming'], $actor);
        $competency = app(CompetencyService::class)->createCompetency($framework, ['name' => 'Algorithm'], $actor);
        app(CompetencyService::class)->mapToCourse($ctx['course'], [$competency->id], $actor);

        // Nilai sempurna tidak otomatis membuat siswa "kompeten" (§30).
        $this->recordGrade($ctx['item'], $student, 100);

        $rows = app(CompetencyAssessmentService::class)->forStudent($ctx['course'], $student);

        $this->assertSame(CompetencyStatus::NOT_STARTED->value, $rows[0]['status']);
        $this->assertSame([], $rows[0]['evidence']);
    }

    public function test_rekap_kemajuan_kompetensi_kelas(): void
    {
        $ctx = $this->scenario(2);
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);

        $framework = app(CompetencyService::class)->createFramework(['name' => 'Programming'], $actor);
        $competency = app(CompetencyService::class)->createCompetency($framework, ['name' => 'Algorithm'], $actor);
        app(CompetencyService::class)->mapToCourse($ctx['course'], [$competency->id], $actor);

        app(CompetencyAssessmentService::class)->assess($competency, $ctx['students'][0], CompetencyStatus::MASTERED, $actor, $ctx['course']);

        $progress = collect(app(CompetencyAssessmentService::class)->courseProgress($ctx['course']))->first();

        $this->assertSame(2, $progress['student_count']);
        $this->assertSame(1, $progress['assessed_count']);
        $this->assertSame(1, $progress['achieved_count']);
        $this->assertSame(1, $progress['breakdown'][CompetencyStatus::NOT_STARTED->value]);
    }

    public function test_siswa_melihat_capaian_dan_kompetensinya_sendiri_lewat_api(): void
    {
        $ctx = $this->scenario();
        $teacherUser = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacherUser);
        $student = $ctx['students'][0];

        $outcome = app(LearningOutcomeService::class)->create($ctx['course'], [
            'statement' => 'Memahami konsep aljabar.',
            'activity_ids' => [$ctx['activity']->id],
        ], $actor);

        $framework = app(CompetencyService::class)->createFramework(['name' => 'Programming'], $actor);
        $competency = app(CompetencyService::class)->createCompetency($framework, ['name' => 'Algorithm'], $actor);
        app(CompetencyService::class)->mapToCourse($ctx['course'], [$competency->id], $actor);

        $this->recordGrade($ctx['item'], $student, 88);
        app(OutcomeAssessmentService::class)->recalculate($outcome, [$student], $actor);
        app(CompetencyAssessmentService::class)->assess($competency, $student, CompetencyStatus::DEVELOPING, $actor, $ctx['course']);

        $this->actingAsStudent($ctx['world']['students'][0], $ctx['world']['studentUsers'][0]);

        $this->getJson('/api/v1/lms/my/outcomes/')->assertOk();
        $this->getJson('/api/v1/lms/courses/'.$ctx['course']->id.'/outcomes/students/'.$student)->assertOk();
        $this->getJson('/api/v1/lms/courses/'.$ctx['course']->id.'/competencies/students/'.$student)->assertOk();
    }
}
