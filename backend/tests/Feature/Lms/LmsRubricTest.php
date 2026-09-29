<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Application\Services\RubricAssessmentService;
use App\Modules\LMS\Application\Services\RubricService;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsRubric;

/**
 * Rubric: pembuatan, penilaian, perhitungan, umpan balik, dan pemakaian ulang
 * (§22–§25, §58).
 */
class LmsRubricTest extends LmsTestCase
{
    /** @param array<string, mixed> $overrides */
    private function rubricPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rubric Presentasi',
            'description' => 'Penilaian presentasi kelompok',
            'grading_method' => 'POINTS',
            'maximum_score' => 16,
            'criteria' => [
                [
                    'name' => 'Content',
                    'weight' => 1,
                    'levels' => [
                        ['name' => 'Excellent', 'score' => 4],
                        ['name' => 'Good', 'score' => 3],
                        ['name' => 'Fair', 'score' => 2],
                        ['name' => 'Poor', 'score' => 1],
                    ],
                ],
                [
                    'name' => 'Structure',
                    'weight' => 1,
                    'levels' => [
                        ['name' => 'Excellent', 'score' => 4],
                        ['name' => 'Good', 'score' => 3],
                        ['name' => 'Fair', 'score' => 2],
                        ['name' => 'Poor', 'score' => 1],
                    ],
                ],
                [
                    'name' => 'Presentation',
                    'weight' => 1,
                    'levels' => [
                        ['name' => 'Excellent', 'score' => 4],
                        ['name' => 'Good', 'score' => 3],
                        ['name' => 'Fair', 'score' => 2],
                        ['name' => 'Poor', 'score' => 1],
                    ],
                ],
                [
                    'name' => 'Originality',
                    'weight' => 1,
                    'levels' => [
                        ['name' => 'Excellent', 'score' => 4],
                        ['name' => 'Good', 'score' => 3],
                        ['name' => 'Fair', 'score' => 2],
                        ['name' => 'Poor', 'score' => 1],
                    ],
                ],
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function scenario(): array
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $item = $this->makeManualItem($scenario['gradebook'], ['name' => 'Presentasi Kelompok', 'maximum_grade' => 100]);

        return [
            'world' => $world,
            'course' => $course,
            'gradebook' => $scenario['gradebook'],
            'item' => $item,
            'student' => $scenario['user_ids'][0],
        ];
    }

    public function test_rubric_dibuat_beserta_kriteria_dan_level(): void
    {
        $ctx = $this->scenario();
        $this->actingAsTeacher($ctx['world']['teacher']);

        $response = $this->postJson('/api/v1/lms/rubrics', $this->rubricPayload([
            'course_id' => $ctx['course']->id,
        ]))->assertCreated();

        $rubricId = (int) $response->json('data.id');

        $this->assertDatabaseHas('lms_rubrics', ['id' => $rubricId, 'name' => 'Rubric Presentasi']);
        $this->assertSame(4, \App\Modules\LMS\Models\LmsRubricCriterion::query()->where('rubric_id', $rubricId)->count());
        $this->assertSame(16, \App\Modules\LMS\Models\LmsRubricLevel::query()->count());
        $this->assertEqualsWithDelta(16.0, (float) $response->json('data.computed_maximum'), 0.01);
    }

    public function test_rubric_tanpa_kriteria_ditolak(): void
    {
        $ctx = $this->scenario();
        $this->actingAsTeacher($ctx['world']['teacher']);

        $this->postJson('/api/v1/lms/rubrics', $this->rubricPayload(['criteria' => [], 'course_id' => $ctx['course']->id]))
            ->assertStatus(422);
    }

    public function test_penilaian_rubric_menghitung_total_dan_mengirim_ke_gradebook(): void
    {
        $ctx = $this->scenario();
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);

        $rubric = app(RubricService::class)->create($this->rubricPayload([
            'course_id' => $ctx['course']->id,
        ]), $actor);

        $rubric->load('criteria.levels');

        // Content 4/4, Structure 3/4, Presentation 3/4, Originality 4/4 = 14/16.
        $selections = [];

        foreach ($rubric->criteria as $criterion) {
            $levels = $criterion->levels->sortByDesc('score')->values();
            $index = match ($criterion->name) {
                'Content', 'Originality' => 0,
                default => 1,
            };

            $selections[$criterion->id] = ['level_id' => (int) $levels[$index]->id, 'comment' => 'Baik'];
        }

        $assessment = app(RubricAssessmentService::class)->start($rubric, $ctx['student'], $ctx['item'], null, $actor);

        $result = app(RubricAssessmentService::class)->assess(
            $assessment,
            $selections,
            $actor,
            true,
            'Presentasi rapi dan terstruktur.',
        );

        $this->assertEqualsWithDelta(14.0, $result['total'], 0.01);
        $this->assertEqualsWithDelta(16.0, $result['maximum'], 0.01);
        $this->assertEqualsWithDelta(87.5, $result['normalized'], 0.01);

        // 14/16 dinormalisasi ke nilai maksimum item (100) → 87.5 (§24).
        $this->assertNotNull($result['grade_result']);
        $this->assertEqualsWithDelta(87.5, (float) $result['grade_result']->final_score, 0.01);
        $this->assertSame('RELEASED', $result['grade_result']->status->value);

        $breakdown = collect($result['breakdown'])->keyBy('criterion');
        $this->assertEqualsWithDelta(4.0, (float) $breakdown['Content']['score'], 0.01);
        $this->assertSame('Excellent', $breakdown['Content']['level']);
        $this->assertEqualsWithDelta(3.0, (float) $breakdown['Structure']['score'], 0.01);

        // Nilai rubric menjadi sumber nilai akhir item tersebut (§61).
        $gradebookResult = $ctx['item']->resultFor($ctx['student']);
        $this->assertEqualsWithDelta(87.5, (float) $gradebookResult?->final_score, 0.01);
        $this->assertSame($rubric->id, (int) $gradebookResult?->metadata['rubric_id']);
    }

    public function test_level_dari_kriteria_lain_ditolak(): void
    {
        $ctx = $this->scenario();
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);

        $rubric = app(RubricService::class)->create($this->rubricPayload(), $actor)->load('criteria.levels');

        $first = $rubric->criteria[0];
        $otherLevel = $rubric->criteria[1]->levels->first();

        $selection = [];

        foreach ($rubric->criteria as $criterion) {
            $selection[$criterion->id] = ['level_id' => (int) $criterion->levels->first()->id];
        }

        // Satu kriteria diberi level milik kriteria lain → harus ditolak.
        $selection[$first->id] = ['level_id' => (int) $otherLevel->id];

        $assessment = app(RubricAssessmentService::class)->start($rubric, $ctx['student'], $ctx['item'], null, $actor);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(RubricAssessmentService::class)->assess($assessment, $selection, $actor);
    }

    public function test_rubric_berbobot_menghitung_sesuai_weight(): void
    {
        $ctx = $this->scenario();
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);

        $rubric = app(RubricService::class)->create([
            'name' => 'Rubric Berbobot',
            'grading_method' => 'WEIGHTED',
            'maximum_score' => 100,
            'criteria' => [
                [
                    'name' => 'Isi',
                    'weight' => 3,
                    'levels' => [['name' => 'Baik', 'score' => 4], ['name' => 'Kurang', 'score' => 2]],
                ],
                [
                    'name' => 'Format',
                    'weight' => 1,
                    'levels' => [['name' => 'Baik', 'score' => 4], ['name' => 'Kurang', 'score' => 2]],
                ],
            ],
        ], $actor)->load('criteria.levels');

        $selections = [];

        foreach ($rubric->criteria as $criterion) {
            $selections[$criterion->id] = ['level_id' => (int) $criterion->levels->firstWhere('name', 'Baik')->id];
        }

        $assessment = app(RubricAssessmentService::class)->start($rubric, $ctx['student'], null, null, $actor);
        $result = app(RubricAssessmentService::class)->assess($assessment, $selections, $actor);

        // (4×3 + 4×1) / (4 × 4) = 100%.
        $this->assertEqualsWithDelta(16.0, $result['total'], 0.01);
        $this->assertEqualsWithDelta(16.0, $result['maximum'], 0.01);
        $this->assertEqualsWithDelta(100.0, $result['normalized'], 0.01);
    }

    public function test_rubric_template_dapat_dipakai_ulang_dan_disalin(): void
    {
        $ctx = $this->scenario();
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);
        $service = app(RubricService::class);

        $template = $service->create($this->rubricPayload(['name' => 'Essay Rubric', 'is_template' => true]), $actor);

        $available = $service->availableFor($ctx['course']);
        $this->assertContains('Essay Rubric', array_column($available, 'name'));

        $this->postJson('/api/v1/lms/rubrics/'.$template->id.'/duplicate', [
            'course_id' => $ctx['course']->id,
            'name' => 'Rubric Essay Kelas X',
        ])->assertCreated()->assertJsonPath('data.name', 'Rubric Essay Kelas X');

        $this->assertSame(2, LmsRubric::query()->count());
        $this->assertSame(8, \App\Modules\LMS\Models\LmsRubricCriterion::query()->count());
    }

    public function test_rubric_diarsipkan_tidak_dapat_dipakai_menilai(): void
    {
        $ctx = $this->scenario();
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);

        $rubric = app(RubricService::class)->create($this->rubricPayload(), $actor)->load('criteria.levels');
        app(RubricService::class)->archive($rubric, $actor);

        $assessment = app(RubricAssessmentService::class)->start($rubric->refresh(), $ctx['student'], $ctx['item'], null, $actor);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(RubricAssessmentService::class)->assess($assessment, [
            $rubric->criteria[0]->id => ['level_id' => (int) $rubric->criteria[0]->levels->first()->id],
        ], $actor);
    }

    public function test_rubric_dapat_dipasang_pada_grade_item_dan_dilihat_siswa(): void
    {
        $ctx = $this->scenario();
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);

        $rubric = app(RubricService::class)->create($this->rubricPayload(), $actor)->load('criteria.levels');

        app(RubricService::class)->attachToGradeItem($ctx['item'], $rubric, $actor);

        $selections = [];

        foreach ($rubric->criteria as $criterion) {
            $selections[$criterion->id] = ['level_id' => (int) $criterion->levels->sortByDesc('score')->first()->id];
        }

        app(RubricAssessmentService::class)->assess(
            app(RubricAssessmentService::class)->start($rubric, $ctx['student'], $ctx['item'], null, $actor),
            $selections,
            $actor,
            true,
            'Sempurna.',
        );

        $this->actingAsTeacher($ctx['world']['teacher']);

        $response = $this->getJson('/api/v1/lms/grade-items/'.$ctx['item']->id.'/rubric/'.$ctx['student'])
            ->assertOk()
            ->assertJsonPath('data.rubric.name', 'Rubric Presentasi');

        $criteria = $response->json('data.assessment.criteria');
        $this->assertCount(4, $criteria);
        $this->assertSame('Excellent', $criteria[0]['level']);
        $this->assertEqualsWithDelta(100.0, (float) $response->json('data.assessment.normalized_score'), 0.01);
    }

    public function test_rubric_yang_sudah_dipakai_tidak_dapat_dihapus(): void
    {
        $ctx = $this->scenario();
        $teacher = $this->actingAsTeacher($ctx['world']['teacher']);
        $actor = $this->actorFor($teacher);

        $rubric = app(RubricService::class)->create($this->rubricPayload(), $actor)->load('criteria.levels');

        $selections = [];

        foreach ($rubric->criteria as $criterion) {
            $selections[$criterion->id] = ['level_id' => (int) $criterion->levels->first()->id];
        }

        app(RubricAssessmentService::class)->assess(
            app(RubricAssessmentService::class)->start($rubric, $ctx['student'], $ctx['item'], null, $actor),
            $selections,
            $actor,
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(RubricService::class)->delete($rubric, $actor);
    }
}
