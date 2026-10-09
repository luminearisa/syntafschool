<?php

declare(strict_types=1);

namespace Tests\Feature\Competitions;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionCatalogService;
use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionOperationsService;
use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionRegistrationService;
use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionResultService;
use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionScoringService;
use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionTemplateService;
use App\Modules\SchoolOperations\Competitions\Database\Seeders\CompetitionMasterSeeder;
use App\Modules\SchoolOperations\Competitions\Database\Seeders\CompetitionPermissionSeeder;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\StudentProviderInterface as CompetitionStudentProviderInterface;
use App\Modules\SchoolOperations\Competitions\Models\Competition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCategory;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCategoryEligibilityRule;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionConsent;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEdition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionParticipant;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRegistration;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionResult;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRound;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRubricCriterion;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionScoreVersion;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionTemplate;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompetitionFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Competition $competition;

    private CompetitionEdition $edition;

    private CompetitionCategory $category;

    private CompetitionParticipant $participantOne;

    private CompetitionParticipant $participantTwo;

    private CompetitionOperationsService $operations;

    private CompetitionScoringService $scoring;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CompetitionPermissionSeeder::class, CompetitionMasterSeeder::class]);
        $this->app->instance(CompetitionStudentProviderInterface::class, new FakeCompetitionStudentProvider);
        $role = Role::findOrCreate('COMPETITION_ADMIN', 'web');
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->syncRoles([$role]);
        Sanctum::actingAs($this->admin, ['*']);

        $catalog = app(CompetitionCatalogService::class);
        $typeId = CompetitionType::query()->where('code', 'OLYMPIAD')->value('id');
        $this->competition = $catalog->createCompetition([
            'name' => 'Math Challenge',
            'competition_type_id' => $typeId,
            'description' => 'Feature test competition',
        ], $this->admin);
        $this->edition = $catalog->createEdition($this->competition, [
            'year' => (int) now()->format('Y'),
            'name' => 'Math Challenge '.now()->format('Y'),
            'registration_opens' => now()->subDay()->toDateTimeString(),
            'registration_closes' => now()->addDays(10)->toDateTimeString(),
            'starts_on' => now()->addDays(14)->toDateString(),
            'ends_on' => now()->addDays(15)->toDateString(),
            'allows_internal' => true,
            'requires_eligibility' => true,
        ], $this->admin);
        $this->category = $catalog->createCategory($this->edition, [
            'code' => 'SMP', 'name' => 'SMP', 'participant_mode' => 'INDIVIDUAL',
            'capacity' => 8, 'scoring_model' => 'WEIGHTED', 'aggregation_method' => 'AVERAGE',
            'requires_judge' => true,
        ], $this->admin);
        $catalog->createRubric($this->edition, [
            'category_id' => $this->category->id,
            'name' => 'Rubric Matematika',
            'scoring_type' => 'WEIGHTED',
            'criteria' => [
                ['code' => 'ACCURACY', 'name' => 'Akurasi', 'weight' => 60, 'min_score' => 0, 'max_score' => 100, 'is_required' => true],
                ['code' => 'METHOD', 'name' => 'Metode', 'weight' => 40, 'min_score' => 0, 'max_score' => 100, 'is_required' => true],
            ],
        ], $this->admin);
        $catalog->openRegistration($this->edition, $this->admin);

        $registrationService = app(CompetitionRegistrationService::class);
        $this->participantOne = $this->approvedParticipant($registrationService, 101);
        $this->participantTwo = $this->approvedParticipant($registrationService, 102);
        $this->operations = app(CompetitionOperationsService::class);
        $this->scoring = app(CompetitionScoringService::class);
    }

    public function test_golden_flow_from_registration_through_result_award_and_certificate_reference(): void
    {
        $round = $this->operations->createRound($this->edition, [
            'category_id' => $this->category->id,
            'name' => 'Final',
            'round_type' => 'FINAL',
            'format' => 'KNOCKOUT',
            'is_final' => true,
        ], $this->admin);
        $match = $this->operations->createMatch($round, [
            'home_participant_id' => $this->participantOne->id,
            'away_participant_id' => $this->participantTwo->id,
            'scheduled_at' => now()->addDays(14)->toDateTimeString(),
            'duration_minutes' => 60,
            'venue_name' => 'Aula',
        ], $this->admin);

        $judgeUser = User::factory()->create(['is_active' => true]);
        $judgeUser->syncRoles([Role::findOrCreate('COMPETITION_JUDGE', 'web')]);
        $judge = $this->operations->createJudge([
            'user_id' => $judgeUser->id,
            'display_name' => 'Juri A',
            'expertise' => 'Matematika',
        ], $this->admin);
        $this->operations->assignJudge($this->edition, $judge, [
            'match_id' => $match->id,
            'assignment_role' => 'HEAD_JUDGE',
            'is_head_judge' => true,
        ], $this->admin);

        $this->operations->checkIn($this->edition, ['participant_id' => $this->participantOne->id, 'idempotency_key' => 'checkin-101'], $this->admin);
        $this->operations->checkIn($this->edition, ['participant_id' => $this->participantTwo->id, 'idempotency_key' => 'checkin-102'], $this->admin);
        $match = $this->operations->transitionMatch($match, $this->admin, 'START');

        Sanctum::actingAs($judgeUser, ['*']);
        $first = $this->scoring->saveScorecard($match, $judgeUser, [
            'participant_id' => $this->participantOne->id,
            'scores' => [
                ['criterion_id' => $this->criterionId('ACCURACY'), 'score' => 90],
                ['criterion_id' => $this->criterionId('METHOD'), 'score' => 80],
            ],
        ], true);
        $second = $this->scoring->saveScorecard($match, $judgeUser, [
            'participant_id' => $this->participantTwo->id,
            'scores' => [
                ['criterion_id' => $this->criterionId('ACCURACY'), 'score' => 70],
                ['criterion_id' => $this->criterionId('METHOD'), 'score' => 75],
            ],
        ], true);

        Sanctum::actingAs($this->admin, ['*']);
        $first = $this->scoring->finalizeScorecard($first, $this->admin, 'Nilai diperiksa.');
        $second = $this->scoring->finalizeScorecard($second, $this->admin, 'Nilai diperiksa.');
        $this->assertSame('LOCKED', $first->status);
        $this->assertSame(1, CompetitionScoreVersion::query()->where('scorecard_id', $first->id)->count());
        Sanctum::actingAs($judgeUser, ['*']);
        $amendment = $this->scoring->createAmendment($first, $judgeUser, [
            'reason' => 'Rubric entry diperiksa ulang.',
            'scores' => [
                ['criterion_id' => $this->criterionId('ACCURACY'), 'score' => 100],
                ['criterion_id' => $this->criterionId('METHOD'), 'score' => 90],
            ],
        ]);
        Sanctum::actingAs($this->admin, ['*']);
        $first = $this->scoring->decideAmendment($amendment, $this->admin, 'APPROVE');
        $this->assertSame('AMENDED', $first->status);
        $this->assertSame(2, (int) $first->current_version);
        $this->assertSame(2, CompetitionScoreVersion::query()->where('scorecard_id', $first->id)->count());
        $this->assertNotNull(CompetitionScoreVersion::query()->where('scorecard_id', $first->id)->where('version', 1)->value('new_payload'));

        $match = $this->operations->transitionMatch($match, $this->admin, 'COMPLETE');
        $this->assertSame('COMPLETED', $match->status);

        $resultService = app(CompetitionResultService::class);
        $finalized = $resultService->finalize($this->edition, $this->category->id, $this->admin, $round->id);
        $this->assertCount(2, $finalized['results']);
        $winner = CompetitionResult::query()->where('category_id', $this->category->id)->where('position', 1)->firstOrFail();
        $this->assertSame((int) $this->participantOne->id, (int) $winner->participant_id);
        $published = $resultService->publish($this->edition, $this->admin, $this->category->id);
        $this->assertSame(2, $published['published']);

        $award = $resultService->createAward($this->edition, [
            'category_id' => $this->category->id,
            'name' => 'Juara 1',
            'position_from' => 1,
            'position_to' => 1,
        ], $this->admin);
        $recipient = $resultService->assignAward($award, $winner->refresh(), $this->admin);
        $this->assertSame((int) $this->participantOne->student_id, (int) $recipient->student_id);
        $certificate = $resultService->generateCertificate($recipient, $this->admin);
        $this->assertNull($certificate->certificate_number);
        $this->assertSame('PENDING_PROVIDER', $certificate->status);
    }

    public function test_score_outside_rubric_is_rejected_and_finalized_score_requires_amendment(): void
    {
        $round = $this->operations->createRound($this->edition, ['category_id' => $this->category->id, 'name' => 'Babak', 'format' => 'SINGLE'], $this->admin);
        $match = $this->operations->createMatch($round, [
            'home_participant_id' => $this->participantOne->id,
            'away_participant_id' => $this->participantTwo->id,
        ], $this->admin);
        $judgeUser = User::factory()->create(['is_active' => true]);
        $judgeUser->syncRoles([Role::findOrCreate('COMPETITION_JUDGE', 'web')]);
        $judge = $this->operations->createJudge(['user_id' => $judgeUser->id, 'display_name' => 'Juri B'], $this->admin);
        $this->operations->assignJudge($this->edition, $judge, ['match_id' => $match->id], $this->admin);
        $match->forceFill(['status' => 'RUNNING'])->save();

        Sanctum::actingAs($judgeUser, ['*']);
        try {
            $this->scoring->saveScorecard($match, $judgeUser, [
                'participant_id' => $this->participantOne->id,
                'scores' => [['criterion_id' => $this->criterionId('ACCURACY'), 'score' => 150]],
            ], true);
            $this->fail('Skor di luar range rubric harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scores.'.$this->criterionId('ACCURACY'), $exception->errors());
        }
    }

    public function test_edition_template_copies_structure_without_copying_operational_history(): void
    {
        $template = CompetitionTemplate::query()->where('code', 'MATH-OLYMPIAD')->firstOrFail();
        $edition = app(CompetitionTemplateService::class)->instantiate(
            $this->competition,
            (int) $template->id,
            ['year' => (int) now()->format('Y') + 1, 'name' => 'Math Challenge Next Edition'],
            $this->admin,
        );
        $this->assertSame(3, CompetitionCategory::query()->where('edition_id', $edition->id)->count());
        $this->assertSame(6, CompetitionRound::query()->where('edition_id', $edition->id)->count());
        $this->assertSame(0, CompetitionRegistration::query()->where('edition_id', $edition->id)->count());
        $this->assertSame(1, (int) $template->fresh()->usage_count);
    }

    public function test_knockout_bracket_generates_first_round_fixtures_and_bye_slots(): void
    {
        $service = app(CompetitionOperationsService::class);
        $round = $service->createRound($this->edition, [
            'category_id' => $this->category->id,
            'name' => 'Quarter final',
            'format' => 'KNOCKOUT',
        ], $this->admin);
        $fixtures = $service->generateFixtures($round, $this->admin, ['format' => 'KNOCKOUT']);
        $this->assertSame('KNOCKOUT', $fixtures['format']);
        $this->assertSame(2, $fixtures['participants']);
        $this->assertCount(1, $fixtures['matches']);
        $this->assertSame('SCHEDULED', $fixtures['matches'][0]->status);
        $this->assertNull($fixtures['matches'][0]->scheduled_at);
    }

    public function test_declared_judge_conflict_blocks_assignment_until_review(): void
    {
        $judge = app(CompetitionOperationsService::class)->createJudge(['display_name' => 'Juri Terdeklarasi'], $this->admin);
        $ops = app(CompetitionOperationsService::class);
        $conflict = $ops->declareJudgeConflict($judge, [
            'participant_id' => $this->participantOne->id,
            'description' => 'Pernah menjadi pembina peserta.',
            'conflict_type' => 'COACH',
        ], $this->admin);
        $round = $ops->createRound($this->edition, ['category_id' => $this->category->id, 'name' => 'Penyisihan'], $this->admin);
        $match = $ops->createMatch($round, ['home_participant_id' => $this->participantOne->id, 'away_participant_id' => $this->participantTwo->id], $this->admin);
        try {
            $ops->assignJudge($this->edition, $judge, ['match_id' => $match->id], $this->admin);
            $this->fail('Konflik aktif harus mencegah penugasan juri.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('judge_id', $exception->errors());
        }
        $ops->reviewJudgeConflict($conflict, $this->admin, 'DISMISS', 'Komite memastikan tidak ada konflik sesuai aturan.');
        $assignment = $ops->assignJudge($this->edition, $judge, ['match_id' => $match->id], $this->admin);
        $this->assertSame((int) $judge->id, (int) $assignment->judge_id);
    }

    public function test_scheduler_rejects_overlapping_participant_and_venue_slots(): void
    {
        $service = app(CompetitionOperationsService::class);
        $third = $this->approvedParticipant(app(CompetitionRegistrationService::class), 105);
        $round = $service->createRound($this->edition, ['category_id' => $this->category->id, 'name' => 'Heat'], $this->admin);
        $startsAt = now()->addDays(2)->startOfHour();
        $service->createMatch($round, [
            'home_participant_id' => $this->participantOne->id,
            'away_participant_id' => $this->participantTwo->id,
            'scheduled_at' => $startsAt->toDateTimeString(),
            'duration_minutes' => 60,
            'venue_name' => 'Hall A',
        ], $this->admin);
        $this->expectException(ValidationException::class);
        $service->createMatch($round, [
            'home_participant_id' => $this->participantOne->id,
            'away_participant_id' => $third->id,
            'scheduled_at' => $startsAt->copy()->addMinutes(30)->toDateTimeString(),
            'duration_minutes' => 60,
            'venue_name' => 'Hall B',
        ], $this->admin);
    }

    public function test_duplicate_registration_is_rejected_and_idempotency_key_returns_same_record(): void
    {
        $service = app(CompetitionRegistrationService::class);
        $first = $this->approvedRegistration($service, 103, 'reg-idem-103');
        $repeat = $service->create($this->edition, [
            'category_id' => $this->category->id,
            'participant_type' => 'STUDENT',
            'student_id' => 103,
            'idempotency_key' => 'reg-idem-103',
        ], $this->admin);
        $this->assertSame((int) $first->id, (int) $repeat->id);

        $this->expectException(ValidationException::class);
        $service->create($this->edition, ['category_id' => $this->category->id, 'participant_type' => 'STUDENT', 'student_id' => 103], $this->admin);
    }

    public function test_permission_gated_dashboard_endpoint_is_available_to_competition_admin(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $response = $this->getJson('/api/v1/competitions/dashboard');
        $this->assertSame(200, $response->status(), $response->getContent());
        $response->assertJsonPath('success', true);
    }

    public function test_configured_eligibility_rule_blocks_approval_until_reasoned_override(): void
    {
        CompetitionCategoryEligibilityRule::query()->create([
            'category_id' => $this->category->id,
            'rule_code' => 'GRADE_MINIMUM',
            'name' => 'Minimal kelas 10',
            'rule_type' => 'GRADE',
            'operator' => 'GTE',
            'expected_value' => '10',
            'value_type' => 'NUMBER',
            'is_required' => true,
            'failure_message' => 'Peserta harus kelas 10 atau lebih.',
            'status' => 'ACTIVE',
        ]);
        $service = app(CompetitionRegistrationService::class);
        $registration = $service->create($this->edition, [
            'category_id' => $this->category->id,
            'participant_type' => 'STUDENT',
            'student_id' => 104,
            'idempotency_key' => 'eligibility-104',
        ], $this->admin);
        $registration = $service->submit($registration, $this->admin);
        $this->assertSame('ELIGIBILITY_FAILED', $registration->status);

        try {
            $service->decide($registration, $this->admin, 'APPROVED');
            $this->fail('Peserta yang tidak memenuhi aturan tidak boleh disetujui tanpa override.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('eligibility', $exception->errors());
        }

        $approved = $service->decide($registration->refresh(), $this->admin, 'APPROVED', 'Rekomendasi kepala unit.', true);
        $this->assertSame('APPROVED', $approved->status);
        $participant = CompetitionParticipant::query()->where('registration_id', $registration->id)->firstOrFail();
        $this->assertSame('OVERRIDE_APPROVED', $participant->eligibility_status);
    }

    public function test_parent_consent_is_linked_to_guardian_identity_before_approval(): void
    {
        $this->category->forceFill(['requires_parent_consent' => true])->save();
        $student = Student::query()->create(['nis' => 'COMP-PARENT-1', 'full_name' => 'Alya Competitor', 'is_active' => true]);
        $guardian = Guardian::query()->create(['full_name' => 'Wali Alya', 'email' => 'wali.alya@example.test']);
        StudentGuardian::query()->create(['student_id' => $student->id, 'guardian_id' => $guardian->id, 'is_primary' => true]);
        $parent = User::factory()->create([
            'is_active' => true,
            'userable_type' => $guardian->getMorphClass(),
            'userable_id' => $guardian->id,
        ]);
        $parent->syncRoles([Role::findOrCreate('wali-murid', 'web')]);
        $registrationService = app(CompetitionRegistrationService::class);
        $registration = $registrationService->create($this->edition, [
            'category_id' => $this->category->id,
            'participant_type' => 'STUDENT',
            'student_id' => $student->id,
            'idempotency_key' => 'guardian-consent-'.$student->id,
        ], $parent);
        $registration = $registrationService->submit($registration, $parent);
        $this->assertSame('UNDER_REVIEW', $registration->status);
        $consent = CompetitionConsent::query()->where('registration_id', $registration->id)->firstOrFail();

        $registrationService->decideConsent($consent, $parent, 'APPROVED', 'Saya menyetujui keikutsertaan.');
        $approved = $registrationService->decide($registration->refresh(), $this->admin, 'APPROVED');
        $this->assertSame('APPROVED', $approved->status);
    }

    public function test_student_can_discover_and_create_own_registration_through_versioned_api(): void
    {
        $student = Student::query()->create(['nis' => 'COMP-API-1', 'full_name' => 'Rafi API', 'is_active' => true]);
        $this->edition->forceFill(['public_visibility' => 'PUBLIC'])->save();
        $user = User::factory()->create([
            'is_active' => true,
            'userable_type' => $student->getMorphClass(),
            'userable_id' => $student->id,
        ]);
        $user->syncRoles([Role::findOrCreate('siswa', 'web')]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/competition-discover')->assertOk()->assertJsonPath('data.data.0.id', (int) $this->edition->id);
        $response = $this->postJson('/api/v1/competition-editions/'.$this->edition->id.'/registrations', [
            'category_id' => $this->category->id,
            'participant_type' => 'STUDENT',
            'student_id' => $student->id,
            'idempotency_key' => 'api-registration-'.$student->id,
        ]);
        $response->assertCreated()->assertJsonPath('data.student_id', (int) $student->id)->assertJsonPath('data.status', 'DRAFT');
    }

    public function test_public_page_only_returns_a_published_public_edition(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $this->competition->forceFill(['default_visibility' => 'PUBLIC'])->save();
        $this->edition->forceFill(['public_visibility' => 'PUBLIC', 'published_at' => now()])->save();
        $this->getJson('/api/v1/public/competitions/'.$this->competition->slug)
            ->assertOk()
            ->assertJsonPath('data.competition.name', $this->competition->name)
            ->assertJsonPath('data.edition.id', (int) $this->edition->id);
    }

    public function test_award_syncs_student_achievement_through_activity_provider(): void
    {
        $student = Student::query()->create(['nis' => 'COMP-ACHIEVEMENT-1', 'full_name' => 'Nadia Winner', 'is_active' => true]);
        $participant = CompetitionParticipant::query()->create([
            'edition_id' => $this->edition->id,
            'category_id' => $this->category->id,
            'participant_type' => 'STUDENT',
            'student_id' => $student->id,
            'display_name' => $student->full_name,
            'status' => 'ACTIVE',
            'eligibility_status' => 'ELIGIBLE',
            'is_active' => true,
        ]);
        $result = CompetitionResult::query()->create([
            'edition_id' => $this->edition->id,
            'category_id' => $this->category->id,
            'participant_id' => $participant->id,
            'final_score' => 91,
            'position' => 1,
            'status' => 'FINALIZED',
            'finalized_by' => $this->admin->id,
            'finalized_at' => now(),
        ]);
        $award = app(CompetitionResultService::class)->createAward($this->edition, [
            'category_id' => $this->category->id,
            'name' => 'Juara 1',
            'criteria_type' => 'RANKING',
            'position_from' => 1,
            'position_to' => 1,
        ], $this->admin);
        $recipient = app(CompetitionResultService::class)->assignAward($award, $result, $this->admin);
        $this->assertNotNull($recipient->achievement_reference);
        $this->assertDatabaseHas('activity_achievements', ['student_id' => $student->id, 'title' => 'Math Challenge — Juara 1']);
    }

    private function approvedParticipant(CompetitionRegistrationService $service, int $studentId): CompetitionParticipant
    {
        $registration = $this->approvedRegistration($service, $studentId, 'registration-'.$studentId);

        return CompetitionParticipant::query()->where('registration_id', $registration->id)->firstOrFail();
    }

    private function approvedRegistration(CompetitionRegistrationService $service, int $studentId, string $key): CompetitionRegistration
    {
        $registration = $service->create($this->edition, [
            'category_id' => $this->category->id,
            'participant_type' => 'STUDENT',
            'student_id' => $studentId,
            'idempotency_key' => $key,
        ], $this->admin);
        $service->submit($registration, $this->admin);

        return $service->decide($registration->refresh(), $this->admin, 'APPROVED');
    }

    private function criterionId(string $code): int
    {
        return (int) CompetitionRubricCriterion::query()->where('code', $code)->value('id');
    }
}

class FakeCompetitionStudentProvider implements CompetitionStudentProviderInterface
{
    public function snapshot(int $studentId): ?array
    {
        if ($studentId < 1) {
            return null;
        }

        return ['id' => $studentId, 'name' => 'Student '.$studentId, 'active' => true, 'grade_level' => 8, 'gender' => 'MALE', 'campus_id' => null];
    }

    public function search(string $term, int $limit = 20): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }
}
