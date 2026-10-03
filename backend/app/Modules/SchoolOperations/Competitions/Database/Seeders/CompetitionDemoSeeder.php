<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Database\Seeders;

use App\Models\Employee;
use App\Models\Student;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\StudentProviderInterface;
use App\Modules\SchoolOperations\Competitions\Models\Competition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCategory;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEdition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionJudge;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionParticipant;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRound;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRubric;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRubricCriterion;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionType;
use Illuminate\Database\Seeder;

/**
 * Optional local/testing demo data. References existing Student/HRD records;
 * it never creates copies of student or employee masters and is not run by default.
 */
class CompetitionDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        app(CompetitionMasterSeeder::class)->run();

        $specs = [
            ['MATH-DEMO', 'Mathematics Olympiad', 'OLYMPIAD', 'INDIVIDUAL', ['SD', 'SMP', 'SMA'], 'QUALIFICATION_FINAL', 'NUMERIC', [
                ['Accuracy', 60], ['Problem solving', 40],
            ]],
            ['DEBATE-DEMO', 'Debate Competition', 'DEBATE', 'TEAM', ['Junior', 'Senior'], 'KNOCKOUT', 'WEIGHTED', [
                ['Argument', 35], ['Evidence', 25], ['Rebuttal', 25], ['Delivery', 15],
            ]],
            ['BASKETBALL-DEMO', 'Basketball Tournament', 'SPORTS', 'TEAM', ['Putra', 'Putri'], 'ROUND_ROBIN', 'POINTS', [
                ['Score', 70], ['Sportsmanship', 30],
            ]],
            ['TAHFIDZ-DEMO', 'Tahfidz Competition', 'RELIGIOUS', 'INDIVIDUAL', ['Juz 30', 'Juz 1–5', 'Muratall'], 'SINGLE_ROUND', 'WEIGHTED', [
                ['Memorization', 50], ['Tajwid', 25], ['Fluency', 25],
            ]],
            ['SPEECH-DEMO', 'Speech Competition', 'SPEECH', 'INDIVIDUAL', ['Bahasa Indonesia', 'English'], 'SINGLE_ROUND', 'WEIGHTED', [
                ['Content', 30], ['Technique', 20], ['Accuracy', 20], ['Creativity', 15], ['Presentation', 15],
            ]],
            ['CODING-DEMO', 'Coding Competition', 'CODING', 'MIXED', ['Web Development', 'Algorithms', 'Robotics'], 'SUBMISSION_JUDGING', 'WEIGHTED', [
                ['Correctness', 40], ['Design', 20], ['Code quality', 20], ['Presentation', 20],
            ]],
        ];
        $studentProvider = app(StudentProviderInterface::class);
        $studentIds = Student::query()->where('is_active', true)->orderBy('id')->limit(8)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $students = [];
        foreach ($studentIds as $studentId) {
            $snapshot = $studentProvider->snapshot($studentId);
            if ($snapshot !== null) {
                $students[$studentId] = $snapshot;
            }
        }
        $employees = Employee::query()->where('is_active', true)->orderBy('id')->limit(3)->get(['id', 'full_name']);

        foreach ($specs as [$code,$name,$typeCode,$mode,$categoryNames,$format,$scoring,$criteria]) {
            $type = CompetitionType::query()->where('code', $typeCode)->firstOrFail();
            $competition = Competition::query()->updateOrCreate(['code' => $code], [
                'competition_type_id' => $type->id,
                'competition_domain_id' => $type->competition_domain_id,
                'name' => $name,
                'slug' => strtolower(str_replace('_', '-', $code)),
                'description' => 'Demo '.$name.'; konfigurasi dapat diubah per edition.',
                'organizer_name' => 'Unit Sekolah',
                'is_internal' => true,
                'default_visibility' => 'INTERNAL',
                'default_participant_mode' => $mode,
                'status' => 'PLANNING',
            ]);
            $edition = CompetitionEdition::query()->updateOrCreate(['code' => $code.'-2026'], [
                'competition_id' => $competition->id,
                'year' => 2026,
                'name' => $name.' 2026',
                'slug' => strtolower(str_replace('_', '-', $code.'-2026')),
                'starts_on' => now()->addMonths(2)->toDateString(),
                'ends_on' => now()->addMonths(2)->addDay()->toDateString(),
                'registration_opens' => now()->toDateTimeString(),
                'registration_closes' => now()->addMonth()->toDateTimeString(),
                'status' => 'DRAFT',
                'allows_internal' => true,
                'requires_eligibility' => true,
                'timezone' => 'Asia/Jakarta',
                'public_visibility' => 'INTERNAL',
            ]);
            foreach ($categoryNames as $index => $categoryName) {
                $category = CompetitionCategory::query()->updateOrCreate(
                    ['edition_id' => $edition->id, 'code' => 'CAT-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)],
                    [
                        'name' => $categoryName,
                        'participant_mode' => $mode,
                        'min_team_size' => $mode === 'TEAM' ? 2 : null,
                        'max_team_size' => $mode === 'TEAM' ? 8 : null,
                        'capacity' => 32,
                        'scoring_model' => $scoring,
                        'ranking_model' => $typeCode === 'SPORTS' ? 'HIGHER_BETTER' : 'HIGHER_BETTER',
                        'aggregation_method' => 'AVERAGE',
                        'round_format' => $format,
                        'requires_judge' => true,
                        'is_active' => true,
                        'sort_order' => $index,
                    ],
                );
                $rubric = CompetitionRubric::query()->updateOrCreate(
                    ['edition_id' => $edition->id, 'category_id' => $category->id, 'code' => 'DEFAULT'],
                    ['name' => $name.' · '.$categoryName, 'version' => 1, 'total_weight' => 100, 'scoring_type' => $scoring, 'is_default' => true, 'is_active' => true, 'status' => 'PUBLISHED', 'published_at' => now()],
                );
                foreach ($criteria as $criterionIndex => [$criterionName,$weight]) {
                    CompetitionRubricCriterion::query()->updateOrCreate(
                        ['rubric_id' => $rubric->id, 'code' => 'CRIT-'.($criterionIndex + 1)],
                        ['name' => $criterionName, 'weight' => $weight, 'min_score' => 0, 'max_score' => 100, 'scoring_type' => 'NUMERIC', 'is_required' => true, 'sort_order' => $criterionIndex],
                    );
                }
                CompetitionRound::query()->updateOrCreate(
                    ['edition_id' => $edition->id, 'category_id' => $category->id, 'sequence' => 1],
                    ['code' => 'ROUND-01', 'name' => $format === 'KNOCKOUT' ? 'Preliminary' : 'Qualification', 'round_type' => 'QUALIFICATION', 'format' => $format, 'status' => 'DRAFT'],
                );
                if ($mode !== 'TEAM') {
                    foreach (array_slice($studentIds, 0, 2) as $studentId) {
                        $snapshot = $students[$studentId] ?? null;
                        CompetitionParticipant::query()->updateOrCreate(
                            ['edition_id' => $edition->id, 'category_id' => $category->id, 'student_id' => $studentId],
                            ['participant_type' => 'STUDENT', 'display_name' => $snapshot['name'] ?? $snapshot['full_name'] ?? 'Student '.$studentId, 'status' => 'ACTIVE', 'eligibility_status' => 'ELIGIBLE', 'qualification_status' => 'PENDING', 'is_active' => true],
                        );
                    }
                }
            }
        }

        foreach ($employees as $employee) {
            CompetitionJudge::query()->updateOrCreate(
                ['employee_id' => $employee->id],
                ['display_name' => $employee->full_name, 'expertise' => 'Academic / general', 'status' => 'ACTIVE', 'is_active' => true],
            );
        }
    }
}
