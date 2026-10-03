<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Http\Controllers;

use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionCatalogService;
use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionGovernanceService;
use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionOperationsService;
use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionRegistrationService;
use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionResultService;
use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionScopeService;
use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionScoringService;
use App\Modules\SchoolOperations\Competitions\Application\Services\CompetitionTemplateService;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\ParentProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\StudentProviderInterface;
use App\Modules\SchoolOperations\Competitions\Http\Requests\AssignJudgeRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\AwardRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\CheckinRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\CreateJudgeRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\CreateTeamRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\GenerateFixturesRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\RegistrationDecisionRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\ScheduleMatchRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\ScoreAmendmentRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\StoreCategoryRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\StoreCompetitionRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\StoreEditionRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\StoreMatchRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\StoreRegistrationRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\StoreRoundRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\StoreRubricRequest;
use App\Modules\SchoolOperations\Competitions\Http\Requests\StoreScorecardRequest;
use App\Modules\SchoolOperations\Competitions\Models\Competition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionAppeal;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionAppealCommittee;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionAuditLog;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionAward;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionAwardRecipient;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCategory;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCheckin;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionConsent;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEdition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionIncident;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionJudge;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionJudgeAssignment;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionJudgeConflict;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionMatch;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionParticipant;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionProtest;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRegistration;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRegistrationDocument;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionResult;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRound;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRubric;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionScoreAmendment;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionScorecard;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionSession;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionTeam;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionWaitlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** REST surface for the Competition domain engine. */
class CompetitionApiController extends Controller
{
    public function __construct(
        private readonly CompetitionCatalogService $catalog,
        private readonly CompetitionRegistrationService $registrations,
        private readonly CompetitionOperationsService $operations,
        private readonly CompetitionScoringService $scoring,
        private readonly CompetitionResultService $results,
        private readonly CompetitionScopeService $scope,
        private readonly CompetitionTemplateService $templates,
        private readonly CompetitionGovernanceService $governance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok($this->catalog->paginate($request->query(), $request->user()));
    }

    public function dashboard(Request $request): JsonResponse
    {
        return $this->ok($this->catalog->dashboard($request->user()));
    }

    public function meta(): JsonResponse
    {
        return $this->ok($this->catalog->meta());
    }

    public function templates(Request $request): JsonResponse
    {
        return $this->ok($this->templates->list($request->query()));
    }

    public function instantiateEdition(Competition $competition, Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:competition_templates,id'],
            'year' => ['required', 'integer', 'between:2000,2200'],
            'name' => ['nullable', 'string', 'max:200'],
            'theme' => ['nullable', 'string', 'max:200'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'registration_opens' => ['nullable', 'date'],
            'registration_closes' => ['nullable', 'date', 'after:registration_opens'],
            'max_participants' => ['nullable', 'integer', 'min:1'],
            'requires_parent_consent' => ['sometimes', 'boolean'],
            'public_visibility' => ['nullable', Rule::in(['INTERNAL', 'PRIVATE', 'PUBLIC'])],
            'settings' => ['nullable', 'array'],
        ]);
        $templateId = (int) $data['template_id'];
        unset($data['template_id']);

        return $this->ok($this->templates->instantiate($competition, $templateId, $data, $request->user()), 'Edition dan struktur awal dibuat dari template.', 201);
    }

    public function store(StoreCompetitionRequest $request): JsonResponse
    {
        return $this->ok($this->catalog->createCompetition($request->validated(), $request->user()), 'Kompetisi dibuat.', 201);
    }

    public function show(Competition $competition, Request $request): JsonResponse
    {
        return $this->ok($this->catalog->detail($competition, $request->user()));
    }

    public function update(Competition $competition, Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'], 'short_name' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:20000'], 'organizer_name' => ['nullable', 'string', 'max:200'],
            'organizer_contact_email' => ['nullable', 'email', 'max:180'], 'organizer_contact_phone' => ['nullable', 'string', 'max:40'],
            'organizer_organization_id' => ['nullable', 'integer'], 'competition_type_id' => ['sometimes', 'integer', 'exists:competition_types,id'],
            'competition_domain_id' => ['nullable', 'integer', 'exists:competition_domains,id'], 'campus_id' => ['nullable', 'integer'],
            'organization_id' => ['nullable', 'integer'], 'is_internal' => ['sometimes', 'boolean'], 'is_recurring' => ['sometimes', 'boolean'],
            'recurrence_pattern' => ['nullable', 'string', 'max:40'], 'default_visibility' => ['nullable', Rule::in(['INTERNAL', 'PRIVATE', 'PUBLIC'])],
            'default_participant_mode' => ['nullable', Rule::in(['INDIVIDUAL', 'TEAM', 'MIXED', 'ORGANIZATION'])], 'settings' => ['nullable', 'array'],
        ]);

        return $this->ok($this->catalog->updateCompetition($competition, $data, $request->user()), 'Kompetisi diperbarui.');
    }

    public function destroy(Competition $competition, Request $request): JsonResponse
    {
        $this->catalog->deleteCompetition($competition, $request->user());

        return $this->ok(null, 'Kompetisi dihapus.');
    }

    public function editions(Competition $competition, Request $request): JsonResponse
    {
        $this->scope->assertCompetition($request->user(), $competition);

        return $this->ok(CompetitionEdition::query()->where('competition_id', $competition->id)->orderByDesc('year')->get()->map(fn ($edition) => $this->catalog->editionData($edition)));
    }

    public function storeEdition(Competition $competition, StoreEditionRequest $request): JsonResponse
    {
        return $this->ok($this->catalog->createEdition($competition, $request->validated(), $request->user()), 'Edition dibuat.', 201);
    }

    public function showEdition(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $this->scope->assertEdition($request->user(), $edition);

        return $this->ok($this->catalog->editionData($edition));
    }

    public function updateEdition(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'], 'theme' => ['nullable', 'string', 'max:200'],
            'organizer_name' => ['nullable', 'string', 'max:200'], 'host_name' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'], 'terms' => ['nullable', 'string', 'max:20000'],
            'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'registration_opens' => ['nullable', 'date'], 'registration_closes' => ['nullable', 'date', 'after:registration_opens'],
            'participant_type' => ['nullable', 'string', 'max:30'], 'registration_mode' => ['nullable', 'string', 'max:30'],
            'max_participants' => ['nullable', 'integer', 'min:1'], 'max_teams' => ['nullable', 'integer', 'min:1'],
            'allows_external' => ['sometimes', 'boolean'], 'allows_internal' => ['sometimes', 'boolean'],
            'requires_parent_consent' => ['sometimes', 'boolean'], 'requires_payment' => ['sometimes', 'boolean'],
            'requires_eligibility' => ['sometimes', 'boolean'], 'requires_selection' => ['sometimes', 'boolean'],
            'requires_documents' => ['sometimes', 'boolean'], 'timezone' => ['nullable', 'timezone'],
            'public_visibility' => ['nullable', Rule::in(['INTERNAL', 'PRIVATE', 'PUBLIC'])], 'settings' => ['nullable', 'array'],
        ]);

        return $this->ok($this->catalog->updateEdition($edition, $data, $request->user()), 'Edition diperbarui.');
    }

    public function publishEdition(CompetitionEdition $edition, Request $request): JsonResponse
    {
        return $this->ok($this->catalog->publishEdition($edition, $request->user()), 'Edition dipublikasikan.');
    }

    public function openRegistration(CompetitionEdition $edition, Request $request): JsonResponse
    {
        return $this->ok($this->catalog->openRegistration($edition, $request->user()), 'Pendaftaran dibuka.');
    }

    public function categories(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $this->scope->assertEdition($request->user(), $edition);

        return $this->ok(CompetitionCategory::query()->where('edition_id', $edition->id)->orderBy('sort_order')->orderBy('name')->get());
    }

    public function storeCategory(CompetitionEdition $edition, StoreCategoryRequest $request): JsonResponse
    {
        return $this->ok($this->catalog->createCategory($edition, $request->validated(), $request->user()), 'Kategori dibuat.', 201);
    }

    public function rubrics(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $this->scope->assertEdition($request->user(), $edition);

        return $this->ok(CompetitionRubric::query()->where('edition_id', $edition->id)->with('competitionRubricCriterions')->orderByDesc('is_default')->get());
    }

    public function storeRubric(CompetitionEdition $edition, StoreRubricRequest $request): JsonResponse
    {
        return $this->ok($this->catalog->createRubric($edition, $request->validated(), $request->user()), 'Rubric diterbitkan.', 201);
    }

    public function registrations(CompetitionEdition $edition, Request $request): JsonResponse
    {
        return $this->ok($this->registrations->paginate($edition, $request->query(), $request->user()));
    }

    public function discover(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('search', ''));
        $editions = CompetitionEdition::query()->where('status', 'REGISTRATION_OPEN')->where('allows_internal', true)->whereNotNull('published_at');
        if (! $this->scope->isGlobal($request->user())) {
            $campusId = $request->user()->getAttribute('campus_id') ?? $request->user()->userable?->getAttribute('campus_id');
            $editions->where(function ($visibility) use ($campusId): void {
                $visibility->where('public_visibility', 'PUBLIC');
                if (is_numeric($campusId)) {
                    $visibility->orWhere(fn ($row) => $row->whereNull('campus_id')->orWhere('campus_id', (int) $campusId));
                } else {
                    $visibility->orWhereNull('campus_id');
                }
            });
        }
        $page = $editions->whereHas('competition', fn ($q) => $q->whereNull('deleted_at')->when($term !== '', fn ($search) => $search->where('name', 'like', '%'.$term.'%')->orWhere('code', 'like', '%'.$term.'%')))
            ->orderByDesc('year')->paginate(min(100, max(1, $request->integer('per_page', 25))));
        $page->getCollection()->transform(function (CompetitionEdition $edition): array {
            $competition = Competition::query()->find($edition->competition_id);

            return [
                'id' => $edition->id, 'year' => $edition->year, 'name' => $edition->name,
                'description' => $edition->description, 'starts_on' => $edition->starts_on,
                'ends_on' => $edition->ends_on, 'registration_closes' => $edition->registration_closes,
                'status' => $edition->status, 'requires_parent_consent' => (bool) $edition->requires_parent_consent,
                'competition' => $competition?->only(['id', 'name', 'slug', 'code', 'description', 'organizer_name']),
                'categories' => CompetitionCategory::query()->where('edition_id', $edition->id)->where('is_active', true)->get(),
            ];
        });

        return $this->ok($page);
    }

    public function myChildren(Request $request): JsonResponse
    {
        $request->user()->can('competition.portal.parent') || abort(403);
        $parents = app(ParentProviderInterface::class);
        $students = app(StudentProviderInterface::class);
        $children = array_values(array_filter(array_map(fn (int $id) => $students->snapshot($id), $parents->studentIdsForUser((int) $request->user()->id))));

        return $this->ok($children);
    }

    public function myRegistrations(Request $request): JsonResponse
    {
        return $this->ok($this->registrations->mine($request->user(), $request->query()));
    }

    public function showRegistration(CompetitionRegistration $registration, Request $request): JsonResponse
    {
        return $this->ok($this->registrations->show($registration, $request->user()));
    }

    public function storeRegistration(CompetitionEdition $edition, StoreRegistrationRequest $request): JsonResponse
    {
        return $this->ok($this->registrations->create($edition, $request->validated(), $request->user()), 'Pendaftaran disimpan sebagai draft.', 201);
    }

    public function submitRegistration(CompetitionRegistration $registration, Request $request): JsonResponse
    {
        $data = $request->validate(['values' => ['nullable', 'array'], 'consent' => ['nullable', 'boolean']]);

        return $this->ok($this->registrations->submit($registration, $request->user(), $data), 'Pendaftaran dikirim.');
    }

    public function evaluateEligibility(CompetitionRegistration $registration, Request $request): JsonResponse
    {
        return $this->ok($this->registrations->evaluate($registration, $request->user()), 'Eligibility dievaluasi.');
    }

    public function decideRegistration(CompetitionRegistration $registration, RegistrationDecisionRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->ok($this->registrations->decide($registration, $request->user(), $data['decision'], $data['reason'] ?? null, (bool) ($data['override'] ?? false)), 'Keputusan pendaftaran disimpan.');
    }

    public function withdrawRegistration(CompetitionRegistration $registration, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->ok($this->registrations->withdraw($registration, $request->user(), $data['reason']), 'Pendaftaran ditarik.');
    }

    public function syncPaymentStatus(CompetitionRegistration $registration, Request $request): JsonResponse
    {
        return $this->ok($this->registrations->syncPaymentStatus($registration, $request->user()), 'Status pembayaran disinkronkan dari Finance.');
    }

    public function waiveRegistrationFee(CompetitionRegistration $registration, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->ok($this->registrations->waivePayment($registration, $request->user(), $data['reason']), 'Biaya pendaftaran dibebaskan dengan audit.');
    }

    public function decideConsent(CompetitionConsent $consent, Request $request): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['APPROVED', 'REJECTED'])], 'note' => ['nullable', 'string', 'max:2000']]);

        return $this->ok($this->registrations->decideConsent($consent, $request->user(), $data['decision'], $data['note'] ?? null), 'Persetujuan orang tua disimpan.');
    }

    public function uploadDocument(CompetitionRegistration $registration, Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx'], 'document_type' => ['required', 'string', 'max:60']]);

        return $this->ok($this->registrations->uploadDocument($registration, $data['file'], $data['document_type'], $request->user()), 'Dokumen diunggah dan menunggu verifikasi.', 201);
    }

    public function reviewDocument(CompetitionRegistrationDocument $document, Request $request): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['VERIFIED', 'REJECTED'])], 'reason' => ['nullable', 'string', 'max:2000']]);

        return $this->ok($this->registrations->reviewDocument($document, $request->user(), $data['decision'], $data['reason'] ?? null), 'Dokumen ditinjau.');
    }

    public function downloadDocument(CompetitionRegistrationDocument $document, Request $request)
    {
        $this->registrations->show(CompetitionRegistration::query()->findOrFail($document->registration_id), $request->user());
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->file_name ?: basename($document->file_path));
    }

    public function participants(CompetitionEdition $edition, Request $request): JsonResponse
    {
        return $this->ok($this->registrations->participants($edition, $request->query(), $request->user()));
    }

    public function storeTeam(CompetitionEdition $edition, CreateTeamRequest $request): JsonResponse
    {
        return $this->ok($this->registrations->createTeam($edition, $request->validated(), $request->user()), 'Tim dibuat.', 201);
    }

    public function teams(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $this->scope->assertEdition($request->user(), $edition);

        return $this->ok(CompetitionTeam::query()->where('edition_id', $edition->id)->with('competitionTeamMembers')->orderBy('name')->paginate(50));
    }

    public function lockRoster(CompetitionTeam $team, Request $request): JsonResponse
    {
        return $this->ok($this->registrations->lockRoster($team, $request->user()), 'Roster tim dikunci.');
    }

    public function confirmWaitlist(CompetitionWaitlist $waitlist, Request $request): JsonResponse
    {
        return $this->ok($this->registrations->confirmWaitlist($waitlist, $request->user()), 'Slot waitlist dikonfirmasi.');
    }

    public function rounds(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $this->scope->assertEdition($request->user(), $edition);

        return $this->ok(CompetitionRound::query()->where('edition_id', $edition->id)->when($request->integer('category_id'), fn ($q, $id) => $q->where('category_id', $id))->orderBy('sequence')->get());
    }

    public function storeRound(CompetitionEdition $edition, StoreRoundRequest $request): JsonResponse
    {
        return $this->ok($this->operations->createRound($edition, $request->validated(), $request->user()), 'Babak dibuat.', 201);
    }

    public function generateFixtures(CompetitionRound $round, GenerateFixturesRequest $request): JsonResponse
    {
        return $this->ok($this->operations->generateFixtures($round, $request->user(), $request->validated()), 'Bracket/fixture dibuat.', 201);
    }

    public function bracket(CompetitionRound $round, Request $request): JsonResponse
    {
        return $this->ok($this->operations->bracket($round, $request->user()));
    }

    public function matches(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $this->scope->assertEdition($request->user(), $edition);
        $roundIds = CompetitionRound::query()->where('edition_id', $edition->id)->when($request->integer('category_id'), fn ($q, $id) => $q->where('category_id', $id))->select('id');

        return $this->ok(CompetitionMatch::query()->whereIn('round_id', $roundIds)->when($request->filled('status'), fn ($q) => $q->where('status', strtoupper($request->string('status'))))->orderBy('scheduled_at')->orderBy('match_number')->paginate(100));
    }

    public function storeMatch(CompetitionRound $round, StoreMatchRequest $request): JsonResponse
    {
        return $this->ok($this->operations->createMatch($round, $request->validated(), $request->user()), 'Pertandingan dibuat.', 201);
    }

    public function matchDetail(CompetitionMatch $match, Request $request): JsonResponse
    {
        $round = CompetitionRound::query()->findOrFail($match->round_id);
        $edition = CompetitionEdition::query()->findOrFail($round->edition_id);
        $this->scope->assertEdition($request->user(), $edition);
        $actor = $request->user();
        if (! $actor->can('competition.view') && ! $actor->can('score.review')) {
            $judgeId = CompetitionJudge::query()->where('user_id', $actor->id)->value('id');
            $assignmentQuery = CompetitionJudgeAssignment::query()->where('edition_id', $edition->id)->where('judge_id', $judgeId)->whereNotIn('status', ['DECLINED', 'CANCELLED']);
            $assignmentQuery->where(function ($query) use ($match): void {
                $query->where('match_id', $match->id)
                    ->orWhere(fn ($scope) => $scope->whereNull('match_id')->where('round_id', $match->round_id));
                if ($match->session_id !== null) {
                    $query->orWhere(fn ($scope) => $scope->whereNull('match_id')->whereNull('round_id')->where('session_id', $match->session_id));
                }
                $query->orWhere(fn ($scope) => $scope->whereNull('match_id')->whereNull('round_id')->whereNull('session_id')->where('category_id', $match->category_id));
            });
            abort_unless($judgeId && $assignmentQuery->exists(), 403);
        }
        $rubric = CompetitionRubric::query()->where('edition_id', $edition->id)->where('is_active', true)->where('status', 'PUBLISHED')
            ->where(fn ($q) => $q->where('category_id', $match->category_id)->orWhereNull('category_id'))
            ->where(fn ($q) => $q->where('round_id', $round->id)->orWhereNull('round_id'))
            ->orderByRaw('CASE WHEN category_id IS NULL THEN 1 ELSE 0 END')->first();

        return $this->ok([
            'match' => $match,
            'round' => $round,
            'edition' => $edition->only(['id', 'name', 'year', 'status']),
            'category' => CompetitionCategory::query()->find($match->category_id),
            'home_participant' => $match->home_participant_id ? CompetitionParticipant::query()->find($match->home_participant_id) : null,
            'away_participant' => $match->away_participant_id ? CompetitionParticipant::query()->find($match->away_participant_id) : null,
            'rubric' => $rubric?->load('competitionRubricCriterions'),
        ]);
    }

    public function scheduleMatch(CompetitionMatch $match, ScheduleMatchRequest $request): JsonResponse
    {
        return $this->ok($this->operations->scheduleMatch($match, $request->validated(), $request->user()), 'Jadwal pertandingan disimpan.');
    }

    public function transitionMatch(CompetitionMatch $match, Request $request, string $transition): JsonResponse
    {
        return $this->ok($this->operations->transitionMatch($match, $request->user(), $transition), 'Status pertandingan diperbarui.');
    }

    public function sessions(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $this->scope->assertEdition($request->user(), $edition);

        return $this->ok(CompetitionSession::query()->where('edition_id', $edition->id)->when($request->integer('category_id'), fn ($q, $id) => $q->where('category_id', $id))->orderBy('starts_at')->paginate(100));
    }

    public function storeSession(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'code' => ['nullable', 'string', 'max:60'], 'category_id' => ['nullable', 'integer', 'exists:competition_categories,id'], 'round_id' => ['nullable', 'integer', 'exists:competition_rounds,id'], 'session_type' => ['nullable', 'string', 'max:40'], 'starts_at' => ['required', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'], 'venue_name' => ['nullable', 'string', 'max:150'], 'room_id' => ['nullable', 'integer'], 'online_url' => ['nullable', 'url', 'max:255'], 'capacity' => ['nullable', 'integer', 'min:1'], 'judge_panel_size' => ['nullable', 'integer', 'min:1'], 'notes' => ['nullable', 'string', 'max:3000'], 'settings' => ['nullable', 'array']]);

        return $this->ok($this->operations->createSession($edition, $data, $request->user()), 'Sesi kompetisi dibuat.', 201);
    }

    public function judges(Request $request): JsonResponse
    {
        $request->user()->can('judge.view') || abort(403);

        return $this->ok(CompetitionJudge::query()->where('is_active', true)->when($request->filled('search'), fn ($q) => $q->where('display_name', 'like', '%'.$request->string('search').'%'))->orderBy('display_name')->paginate(50));
    }

    public function storeJudge(CreateJudgeRequest $request): JsonResponse
    {
        return $this->ok($this->operations->createJudge($request->validated(), $request->user()), 'Profil juri dibuat.', 201);
    }

    public function declareJudgeConflict(CompetitionJudge $judge, Request $request): JsonResponse
    {
        $data = $request->validate(['participant_id' => ['nullable', 'integer', 'exists:competition_participants,id'], 'team_id' => ['nullable', 'integer', 'exists:competition_teams,id'], 'student_id' => ['nullable', 'integer'], 'institution_name' => ['nullable', 'string', 'max:200'], 'conflict_type' => ['nullable', 'string', 'max:40'], 'description' => ['required', 'string', 'max:4000']]);

        return $this->ok($this->operations->declareJudgeConflict($judge, $data, $request->user()), 'Konflik kepentingan dicatat.', 201);
    }

    public function reviewJudgeConflict(CompetitionJudgeConflict $conflict, Request $request): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['CONFIRM', 'DISMISS', 'REASSIGN'])], 'reason' => ['required', 'string', 'max:2000'], 'substitute_judge_id' => ['nullable', 'integer', 'exists:competition_judges,id']]);

        return $this->ok($this->operations->reviewJudgeConflict($conflict, $request->user(), $data['decision'], $data['reason'], isset($data['substitute_judge_id']) ? (int) $data['substitute_judge_id'] : null), 'Konflik kepentingan ditinjau.');
    }

    public function assignJudge(CompetitionEdition $edition, AssignJudgeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $judge = CompetitionJudge::query()->findOrFail($data['judge_id']);

        return $this->ok($this->operations->assignJudge($edition, $judge, $data, $request->user()), 'Juri ditugaskan.', 201);
    }

    public function judgeAssignments(Request $request): JsonResponse
    {
        return $this->ok($this->operations->assignmentsForJudge($request->user()));
    }

    public function checkIn(CompetitionEdition $edition, CheckinRequest $request): JsonResponse
    {
        return $this->ok($this->operations->checkIn($edition, $request->validated(), $request->user()), 'Check-in berhasil.', 201);
    }

    public function noShow(CompetitionCheckin $checkin, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->ok($this->operations->markNoShow($checkin, $request->user(), $data['reason']), 'No-show dicatat.');
    }

    public function scheduleConflicts(CompetitionEdition $edition, Request $request): JsonResponse
    {
        return $this->ok(['conflicts' => $this->operations->reportScheduleConflicts($edition, $request->user())]);
    }

    public function scorecards(CompetitionEdition $edition, Request $request): JsonResponse
    {
        return $this->ok($this->scoring->scorecards($edition, $request->query(), $request->user()));
    }

    public function saveScorecard(CompetitionMatch $match, StoreScorecardRequest $request): JsonResponse
    {
        return $this->ok($this->scoring->saveScorecard($match, $request->user(), $request->validated(), $request->boolean('submit')), 'Scorecard tersimpan.', 201);
    }

    public function submitScorecard(CompetitionScorecard $scorecard, Request $request): JsonResponse
    {
        return $this->ok($this->scoring->submitScorecard($scorecard, $request->user()), 'Scorecard dikirim.');
    }

    public function finalizeScorecard(CompetitionScorecard $scorecard, Request $request): JsonResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:2000']]);

        return $this->ok($this->scoring->finalizeScorecard($scorecard, $request->user(), $data['review_note'] ?? null), 'Skor difinalisasi dan dikunci.');
    }

    public function createScoreAmendment(CompetitionScorecard $scorecard, ScoreAmendmentRequest $request): JsonResponse
    {
        return $this->ok($this->scoring->createAmendment($scorecard, $request->user(), $request->validated()), 'Permintaan amendment dicatat.', 201);
    }

    public function decideScoreAmendment(CompetitionScoreAmendment $amendment, Request $request): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['APPROVE', 'REJECT'])], 'reason' => ['nullable', 'string', 'max:2000']]);

        return $this->ok($this->scoring->decideAmendment($amendment, $request->user(), $data['decision'], $data['reason'] ?? null), 'Amendment diproses.');
    }

    public function applyPenalty(Request $request): JsonResponse
    {
        $data = $request->validate(['edition_id' => ['required', 'integer', 'exists:competition_editions,id'], 'category_id' => ['nullable', 'integer', 'exists:competition_categories,id'], 'participant_id' => ['nullable', 'integer', 'exists:competition_participants,id'], 'team_id' => ['nullable', 'integer', 'exists:competition_teams,id'], 'round_id' => ['nullable', 'integer', 'exists:competition_rounds,id'], 'session_id' => ['nullable', 'integer', 'exists:competition_sessions,id'], 'match_id' => ['nullable', 'integer', 'exists:competition_matches,id'], 'scorecard_id' => ['nullable', 'integer', 'exists:competition_scorecards,id'], 'penalty_type' => ['nullable', 'string', 'max:40'], 'value_type' => ['nullable', Rule::in(['POINTS', 'TIME', 'PERCENTAGE'])], 'penalty_value' => ['required', 'numeric', 'min:0'], 'reason' => ['required', 'string', 'max:2000'], 'rule_code' => ['nullable', 'string', 'max:60'], 'evidence' => ['nullable', 'array']]);

        return $this->ok($this->scoring->applyPenalty($data, $request->user()), 'Penalti diterapkan.', 201);
    }

    public function results(CompetitionEdition $edition, Request $request): JsonResponse
    {
        return $this->ok($this->results->results($edition, $request->query(), $request->user()));
    }

    public function finalizeResults(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $data = $request->validate(['category_id' => ['required', 'integer', 'exists:competition_categories,id'], 'round_id' => ['nullable', 'integer', 'exists:competition_rounds,id']]);

        return $this->ok($this->results->finalize($edition, (int) $data['category_id'], $request->user(), isset($data['round_id']) ? (int) $data['round_id'] : null), 'Hasil dan ranking difinalisasi.');
    }

    public function publishResults(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $data = $request->validate(['category_id' => ['nullable', 'integer', 'exists:competition_categories,id']]);

        return $this->ok($this->results->publish($edition, $request->user(), isset($data['category_id']) ? (int) $data['category_id'] : null), 'Hasil dipublikasikan.');
    }

    public function awards(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $this->scope->assertEdition($request->user(), $edition);

        return $this->ok(CompetitionAward::query()->where('edition_id', $edition->id)->with('competitionAwardRecipients')->orderBy('sort_order')->get());
    }

    public function storeAward(CompetitionEdition $edition, AwardRequest $request): JsonResponse
    {
        return $this->ok($this->results->createAward($edition, $request->validated(), $request->user()), 'Penghargaan dikonfigurasi.', 201);
    }

    public function assignAward(CompetitionAward $award, CompetitionResult $result, Request $request): JsonResponse
    {
        return $this->ok($this->results->assignAward($award, $result, $request->user()), 'Penghargaan diberikan.', 201);
    }

    public function generateCertificate(CompetitionAwardRecipient $recipient, Request $request): JsonResponse
    {
        return $this->ok($this->results->generateCertificate($recipient, $request->user()), 'Permintaan sertifikat diproses.', 201);
    }

    public function protests(CompetitionEdition $edition, Request $request): JsonResponse
    {
        return $this->ok($this->governance->protests($edition, $request->query(), $request->user()));
    }

    public function storeProtest(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $data = $request->validate(['participant_id' => ['nullable', 'integer', 'exists:competition_participants,id'], 'match_id' => ['nullable', 'integer', 'exists:competition_matches,id'], 'session_id' => ['nullable', 'integer', 'exists:competition_sessions,id'], 'round_id' => ['nullable', 'integer', 'exists:competition_rounds,id'], 'protest_type' => ['nullable', 'string', 'max:40'], 'subject' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:10000'], 'evidence' => ['nullable', 'array'], 'appeal_deadline' => ['nullable', 'date']]);

        return $this->ok($this->governance->createProtest($edition, $data, $request->user()), 'Protest dikirim.', 201);
    }

    public function storeAppeal(CompetitionProtest $protest, Request $request): JsonResponse
    {
        $data = $request->validate(['result_id' => ['nullable', 'integer', 'exists:competition_results,id'], 'appeal_type' => ['nullable', 'string', 'max:40'], 'grounds' => ['required', 'string', 'max:10000'], 'description' => ['nullable', 'string', 'max:10000'], 'evidence' => ['nullable', 'array'], 'requested_remedy' => ['nullable', 'string', 'max:4000']]);

        return $this->ok($this->governance->createAppeal($protest, $data, $request->user()), 'Appeal dikirim.', 201);
    }

    public function appeals(CompetitionEdition $edition, Request $request): JsonResponse
    {
        return $this->ok($this->governance->appeals($edition, $request->query(), $request->user()));
    }

    public function decideAppeal(CompetitionAppeal $appeal, Request $request): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['UPHOLD', 'MODIFY', 'REVERSE', 'REJECT'])], 'reason' => ['required', 'string', 'max:10000']]);

        return $this->ok($this->governance->decideAppeal($appeal, $request->user(), $data['decision'], $data['reason']), 'Keputusan appeal dicatat final.');
    }

    public function incidents(CompetitionEdition $edition, Request $request): JsonResponse
    {
        return $this->ok($this->governance->incidents($edition, $request->query(), $request->user()));
    }

    public function storeIncident(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $data = $request->validate(['category_id' => ['nullable', 'integer', 'exists:competition_categories,id'], 'round_id' => ['nullable', 'integer', 'exists:competition_rounds,id'], 'session_id' => ['nullable', 'integer', 'exists:competition_sessions,id'], 'match_id' => ['nullable', 'integer', 'exists:competition_matches,id'], 'participant_id' => ['nullable', 'integer', 'exists:competition_participants,id'], 'team_id' => ['nullable', 'integer', 'exists:competition_teams,id'], 'incident_type' => ['nullable', 'string', 'max:40'], 'severity' => ['nullable', Rule::in(['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])], 'title' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:10000'], 'evidence' => ['nullable', 'array'], 'action_taken' => ['nullable', 'string', 'max:5000'], 'requires_follow_up' => ['sometimes', 'boolean']]);

        return $this->ok($this->governance->createIncident($edition, $data, $request->user()), 'Insiden dicatat.', 201);
    }

    public function resolveIncident(CompetitionIncident $incident, Request $request): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string', 'max:10000']]);

        return $this->ok($this->governance->resolveIncident($incident, $request->user(), $data['resolution']), 'Insiden diselesaikan.');
    }

    public function storeAppealCommittee(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'chair_employee_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:2000']]);

        return $this->ok($this->governance->createAppealCommittee($edition, $data, $request->user()), 'Komite appeal dibuat.', 201);
    }

    public function addAppealCommitteeMember(CompetitionAppealCommittee $committee, Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer', 'exists:users,id'], 'member_name' => ['nullable', 'string', 'max:200'], 'member_role' => ['nullable', Rule::in(['CHAIR', 'MEMBER', 'REVIEWER', 'DECISION_MAKER'])], 'is_voting' => ['sometimes', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000']]);

        return $this->ok($this->governance->addCommitteeMember($committee, $data, $request->user()), 'Anggota komite ditambahkan.', 201);
    }

    public function audit(CompetitionEdition $edition, Request $request): JsonResponse
    {
        $this->scope->assertEdition($request->user(), $edition);
        $request->user()->can('competition.audit') || abort(403);

        return $this->ok(CompetitionAuditLog::query()->where('edition_id', $edition->id)->orderByDesc('id')->paginate(100));
    }

    private function ok(mixed $data, string $message = '', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
