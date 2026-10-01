<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Activity\Application\Services\ActivityAccessService;
use App\Modules\Activity\Application\Services\ActivityCompetitionService;
use App\Modules\Activity\Domain\Enums\RoundType;
use App\Modules\Activity\Models\ActivityCompetition;
use App\Modules\Activity\Models\ActivityCompetitionMatch;
use App\Modules\Activity\Models\ActivityCompetitionRound;
use App\Modules\Activity\Models\ActivityCompetitionResult;
use App\Modules\Activity\Models\ActivityCompetitionScore;
use App\Modules\Activity\Models\ActivityCompetitionTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kompetisi: peserta, tim, babak, juri, skor, dan hasil (\§46\–51).
 */
class ActivityCompetitionController extends BaseApiController
{
    public function __construct(
        private readonly ActivityCompetitionService $competitions,
        private readonly ActivityAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->competitions->paginate($request->all()));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'level' => ['nullable', Rule::in(['INTERNAL', 'SCHOOL', 'DISTRICT', 'CITY', 'PROVINCE', 'NATIONAL', 'INTERNATIONAL'])],
            'participant_type' => ['nullable', Rule::in(['INDIVIDUAL', 'TEAM', 'MIXED'])],
            'categories' => ['nullable', 'array'],
            'categories.*.name' => ['required_with:categories', 'string', 'max:191'],
            'judges' => ['nullable', 'array'],
        ]);

        return $this->createdResponse($this->competitions->create($request->all(), $request->user()), 'Kompetisi dibuat.');
    }

    public function show(ActivityCompetition $competition): JsonResponse
    {
        return $this->successResponse($this->competitions->detail($competition));
    }

    public function openRegistration(ActivityCompetition $competition, Request $request): JsonResponse
    {
        return $this->successResponse($this->competitions->openRegistration($competition, $request->user()), 'Pendaftaran kompetisi dibuka.');
    }

    // ----------------------------------------------------------- peserta

    public function registerParticipant(ActivityCompetition $competition, Request $request): JsonResponse
    {
        $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'category_id' => ['required', 'integer', 'exists:activity_competition_categories,id'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
        ]);

        return $this->createdResponse(
            $this->competitions->registerIndividual(
                $competition,
                (int) $request->input('student_id'),
                (int) $request->input('category_id'),
                $request->user(),
                $request->all(),
            ),
            'Peserta kompetisi terdaftar.',
        );
    }

    public function verifyParticipant(int $participantId, Request $request): JsonResponse
    {
        $participant = \App\Modules\Activity\Models\ActivityCompetitionParticipant::query()->findOrFail($participantId);

        $request->validate(['status' => ['nullable', Rule::in(['VERIFIED', 'REJECTED', 'FINALIZED'])]]);

        return $this->successResponse(
            $this->competitions->verifyParticipant($participant, $request->user(), (string) $request->input('status', 'VERIFIED')),
            'Status peserta diperbarui.',
        );
    }

    public function storeTeam(ActivityCompetition $competition, Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'category_id' => ['nullable', 'integer', 'exists:activity_competition_categories,id'],
            'members' => ['nullable', 'array'],
            'members.*.student_id' => ['required_with:members', 'integer', 'exists:students,id'],
        ]);

        return $this->createdResponse($this->competitions->createTeam($competition, $request->all(), $request->user()), 'Tim dibuat.');
    }

    public function updateTeamMembers(ActivityCompetitionTeam $team, Request $request): JsonResponse
    {
        $request->validate([
            'members' => ['required', 'array'],
            'members.*.student_id' => ['required', 'integer', 'exists:students,id'],
        ]);

        $this->competitions->syncTeamMembers($team, $request->input('members'));

        return $this->successResponse($team->load('members.student:id,full_name'), 'Anggota tim diperbarui.');
    }

    // ------------------------------------------------------------ babak

    public function storeRound(ActivityCompetition $competition, Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::in(RoundType::values())],
            'name' => ['nullable', 'string', 'max:191'],
            'scheduled_date' => ['nullable', 'date'],
        ]);

        return $this->createdResponse($this->competitions->createRound($competition, $request->all(), $request->user()), 'Babak dibuat.');
    }

    public function storeMatch(ActivityCompetitionRound $round, Request $request): JsonResponse
    {
        $request->validate([
            'participant_a_id' => ['nullable', 'integer', 'exists:activity_competition_participants,id'],
            'participant_b_id' => ['nullable', 'integer', 'exists:activity_competition_participants,id'],
            'scheduled_at' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:191'],
        ]);

        return $this->createdResponse($this->competitions->createMatch($round, $request->all(), $request->user()), 'Pertandingan dibuat.');
    }

    // ------------------------------------------------------------- juri

    public function assignJudge(ActivityCompetition $competition, Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'category_id' => ['nullable', 'integer', 'exists:activity_competition_categories,id'],
            'round_id' => ['nullable', 'integer', 'exists:activity_competition_rounds,id'],
            'name' => ['nullable', 'string', 'max:191'],
        ]);

        return $this->createdResponse($this->competitions->assignJudge($competition, $request->all(), $request->user()), 'Juri ditugaskan.');
    }

    /** Penampilan yang ditugaskan kepada saya sebagai juri. */
    public function myJudgeAssignments(Request $request): JsonResponse
    {
        $user = $request->user();

        $judges = \App\Modules\Activity\Models\ActivityCompetitionJudge::query()
            ->with('competition:id,name,level')
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->get();

        return $this->successResponse($judges->map(fn ($judge) => [
            'judge_id' => (int) $judge->id,
            'competition' => $judge->competition?->name,
            'competition_id' => (int) $judge->competition_id,
            'category_id' => $judge->category_id,
            'round_id' => $judge->round_id,
            'matches' => $this->competitions->matchesForJudge($judge),
        ])->all());
    }

    // ------------------------------------------------------------- skor

    public function submitScore(ActivityCompetitionMatch $match, Request $request): JsonResponse
    {
        $request->validate([
            'participant_id' => ['required', 'integer', 'exists:activity_competition_participants,id'],
            'total' => ['nullable', 'numeric'],
            'criteria_scores' => ['nullable', 'array'],
            'comment' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
        ]);

        return $this->successResponse(
            $this->competitions->submitScore($match, $request->user(), $request->all()),
            'Skor dikirim.',
        );
    }

    public function lockScores(ActivityCompetitionMatch $match, Request $request): JsonResponse
    {
        $locked = $this->competitions->lockMatchScores($match, $request->user());

        return $this->successResponse(['locked' => $locked], 'Skor dikunci.');
    }

    public function correctScore(ActivityCompetitionScore $score, Request $request): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'total' => ['nullable', 'numeric'],
            'criteria_scores' => ['nullable', 'array'],
            'comment' => ['nullable', 'string'],
        ]);

        return $this->successResponse($this->competitions->correctScore($score, $request->user(), $request->all()), 'Skor dikoreksi (dengan jejak).');
    }

    // ------------------------------------------------------------ hasil

    public function buildResults(ActivityCompetition $competition, Request $request): JsonResponse
    {
        $request->validate(['category_id' => ['nullable', 'integer']]);

        return $this->successResponse(
            $this->competitions->buildResults($competition, $request->user(), $request->input('category_id')),
            'Hasil disusun dari skor terkumpul.',
        );
    }

    public function verifyResult(ActivityCompetitionResult $result, Request $request): JsonResponse
    {
        $request->validate(['notes' => ['nullable', 'string']]);

        return $this->successResponse($this->competitions->verifyResult($result, $request->user(), $request->input('notes')), 'Hasil diverifikasi.');
    }

    public function publishResults(ActivityCompetition $competition, Request $request): JsonResponse
    {
        return $this->successResponse($this->competitions->publishResults($competition, $request->user()), 'Hasil diterbitkan dan prestasi dicatat.');
    }
}
