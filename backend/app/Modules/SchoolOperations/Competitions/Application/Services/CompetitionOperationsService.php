<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\FacilityProviderInterface;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionAdvancement;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionBracket;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionBracketSlot;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCategory;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCheckin;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEdition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionGroup;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionGroupMember;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionJudge;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionJudgeAssignment;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionJudgeConflict;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionMatch;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionParticipant;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRound;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionScorecard;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionSession;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionTeam;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Round, bracket/fixture generation, scheduling, judging and check-in operations. */
class CompetitionOperationsService
{
    public function __construct(
        private readonly CompetitionAuditService $audit,
        private readonly CompetitionScopeService $scope,
        private readonly FacilityProviderInterface $facilities,
    ) {}

    public function createRound(CompetitionEdition $edition, array $data, User $actor): CompetitionRound
    {
        $this->scope->assertEdition($actor, $edition);
        $category = CompetitionCategory::query()->where('edition_id', $edition->id)->findOrFail($data['category_id']);
        $sequence = (int) ($data['sequence'] ?? ((int) CompetitionRound::query()->where('category_id', $category->id)->max('sequence') + 1));
        if (CompetitionRound::query()->where('category_id', $category->id)->where('sequence', $sequence)->exists()) {
            throw ValidationException::withMessages(['sequence' => 'Nomor urut babak sudah digunakan.']);
        }

        return DB::transaction(function () use ($edition, $category, $data, $actor, $sequence): CompetitionRound {
            $round = CompetitionRound::query()->create([
                'edition_id' => $edition->id,
                'category_id' => $category->id,
                'code' => strtoupper((string) ($data['code'] ?? 'R'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT).'-'.Str::upper(Str::random(3)))),
                'name' => trim($data['name']),
                'sequence' => $sequence,
                'round_type' => strtoupper((string) ($data['round_type'] ?? 'QUALIFICATION')),
                'format' => strtoupper((string) ($data['format'] ?? $category->round_format ?? 'SINGLE')),
                'scoring_method' => strtoupper((string) ($data['scoring_method'] ?? $category->scoring_model)),
                'advancement_rule' => strtoupper((string) ($data['advancement_rule'] ?? 'RANKING')),
                'qualification_count' => $data['qualification_count'] ?? null,
                'qualification_threshold' => $data['qualification_threshold'] ?? null,
                'bracket_size' => $data['bracket_size'] ?? null,
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'is_final' => (bool) ($data['is_final'] ?? false),
                'status' => 'DRAFT',
                'created_by' => $actor->id,
                'settings' => $data['settings'] ?? null,
            ]);
            $this->audit->record('RoundCreated', $actor, $round, $edition, after: $round->only(['id', 'name', 'sequence', 'format']));

            return $round;
        });
    }

    /**
     * Generate a knockout bracket, round-robin fixtures, or a first Swiss pairing.
     *
     * @return array{bracket?:CompetitionBracket,matches:list<CompetitionMatch>,participants:int,format:string}
     */
    public function generateFixtures(CompetitionRound $round, User $actor, array $options = []): array
    {
        $edition = CompetitionEdition::query()->findOrFail($round->edition_id);
        $this->scope->assertEdition($actor, $edition);
        if (! in_array($round->status, ['DRAFT', 'SCHEDULED'], true)) {
            throw ValidationException::withMessages(['round' => 'Fixtures hanya dapat dibuat pada babak DRAFT/SCHEDULED.']);
        }
        $category = CompetitionCategory::query()->findOrFail($round->category_id);
        $participantQuery = CompetitionParticipant::query()
            ->where('edition_id', $edition->id)->where('category_id', $category->id)
            ->where('is_active', true)->whereNotIn('status', ['WITHDRAWN', 'DISQUALIFIED', 'ELIMINATED']);
        if ((int) $round->sequence > 1) {
            $participantQuery->whereIn('qualification_status', ['ADVANCED', 'QUALIFIED', 'FINALIST']);
        }
        $participants = $participantQuery->orderByRaw('CASE WHEN seed_number IS NULL THEN 1 ELSE 0 END')->orderBy('seed_number')->orderBy('id')->get();
        if ($participants->count() < 2) {
            throw ValidationException::withMessages(['participants' => 'Minimal dua peserta eligible diperlukan untuk membuat fixture.']);
        }

        return DB::transaction(function () use ($round, $actor, $options, $participants, $edition): array {
            if (CompetitionMatch::query()->where('round_id', $round->id)->exists()) {
                throw ValidationException::withMessages(['round' => 'Babak ini sudah memiliki pertandingan. Hapus/void fixture melalui operasi berizin sebelum membuat ulang.']);
            }
            $format = strtoupper((string) ($options['format'] ?? $round->format));
            $matches = match ($format) {
                'KNOCKOUT', 'BRACKET', 'SINGLE_ELIMINATION' => $this->knockout($round, $participants, $actor, $options),
                'ROUND_ROBIN', 'GROUP' => $this->roundRobin($round, $participants, $actor, $options),
                'SWISS' => $this->swiss($round, $participants, $actor, $options),
                default => throw ValidationException::withMessages(['format' => 'Generator otomatis mendukung KNOCKOUT, ROUND_ROBIN, dan SWISS. Format lain dapat dijadwalkan manual.']),
            };
            $round->forceFill(['format' => $format, 'status' => 'SCHEDULED', 'published_at' => now()])->save();
            $this->audit->record('BracketGenerated', $actor, $round, $edition, after: ['format' => $format, 'participants' => $participants->count(), 'matches' => count($matches)]);

            $bracket = CompetitionBracket::query()->where('round_id', $round->id)->latest('id')->first();

            return ['matches' => $matches, 'participants' => $participants->count(), 'format' => $format, 'bracket' => $bracket?->render_data];
        });
    }

    public function bracket(CompetitionRound $round, User $actor): array
    {
        $edition = CompetitionEdition::query()->findOrFail($round->edition_id);
        $this->scope->assertEdition($actor, $edition);
        $bracket = CompetitionBracket::query()->where('round_id', $round->id)->latest('id')->first();
        if ($bracket === null) {
            return ['bracket' => null, 'slots' => [], 'matches' => []];
        }

        return [
            'bracket' => $bracket,
            'slots' => CompetitionBracketSlot::query()->where('bracket_id', $bracket->id)->orderBy('round_number')->orderBy('position')->get(),
            'matches' => CompetitionMatch::query()->where('round_id', $round->id)->orderBy('match_number')->get(),
        ];
    }

    public function createMatch(CompetitionRound $round, array $data, User $actor): CompetitionMatch
    {
        $edition = CompetitionEdition::query()->findOrFail($round->edition_id);
        $this->scope->assertEdition($actor, $edition);
        $home = isset($data['home_participant_id']) ? CompetitionParticipant::query()->where('edition_id', $edition->id)->where('category_id', $round->category_id)->findOrFail($data['home_participant_id']) : null;
        $away = isset($data['away_participant_id']) ? CompetitionParticipant::query()->where('edition_id', $edition->id)->where('category_id', $round->category_id)->findOrFail($data['away_participant_id']) : null;
        $this->assertDistinctParticipants($home?->id, $away?->id);
        $category = CompetitionCategory::query()->findOrFail($round->category_id);
        if ($home === null && $away === null) {
            throw ValidationException::withMessages(['participants' => 'Pertandingan harus memiliki setidaknya satu peserta/tim.']);
        }

        return DB::transaction(function () use ($round, $data, $actor, $edition, $home, $away, $category): CompetitionMatch {
            $matchNumber = (int) ($data['match_number'] ?? ((int) CompetitionMatch::query()->where('round_id', $round->id)->max('match_number') + 1));
            $match = CompetitionMatch::query()->create([
                'round_id' => $round->id,
                'category_id' => $category->id,
                'group_id' => $data['group_id'] ?? null,
                'session_id' => $data['session_id'] ?? null,
                'match_number' => $matchNumber,
                'code' => $data['code'] ?? strtoupper('M-'.Str::random(8)),
                'name' => $data['name'] ?? null,
                'home_participant_id' => $home?->id,
                'home_team_id' => $home?->team_id,
                'away_participant_id' => $away?->id,
                'away_team_id' => $away?->team_id,
                'venue_name' => $data['venue_name'] ?? null,
                'room_id' => $data['room_id'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'scheduled_end_at' => $data['scheduled_end_at'] ?? null,
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'status' => $data['scheduled_at'] ?? null ? 'SCHEDULED' : 'SCHEDULED',
                'notes' => $data['notes'] ?? null,
            ]);
            if ($match->scheduled_at !== null) {
                $this->assertNoScheduleConflict($match, $data['judge_ids'] ?? []);
                $this->assertFacilityAvailability($match->room_id, $match->scheduled_at, $match->scheduled_end_at, $match->duration_minutes);
            }
            $this->audit->record('MatchScheduled', $actor, $match, $edition, after: $match->only(['id', 'match_number', 'scheduled_at', 'venue_name']));

            return $match;
        });
    }

    public function scheduleMatch(CompetitionMatch $match, array $data, User $actor): CompetitionMatch
    {
        $round = CompetitionRound::query()->findOrFail($match->round_id);
        $edition = CompetitionEdition::query()->findOrFail($round->edition_id);
        $this->scope->assertEdition($actor, $edition);
        if (! in_array($match->status, ['SCHEDULED', 'POSTPONED'], true)) {
            throw ValidationException::withMessages(['match' => 'Pertandingan yang sudah dimulai tidak dapat dijadwalkan ulang tanpa alur perubahan jadwal.']);
        }
        $before = $match->only(['scheduled_at', 'scheduled_end_at', 'venue_name', 'room_id']);
        $match->fill(array_intersect_key($data, array_flip(['scheduled_at', 'scheduled_end_at', 'venue_name', 'room_id', 'duration_minutes'])));
        if ($match->scheduled_at === null) {
            throw ValidationException::withMessages(['scheduled_at' => 'Waktu mulai wajib diisi.']);
        }
        if ($match->scheduled_end_at === null && $match->duration_minutes !== null) {
            $match->scheduled_end_at = $match->scheduled_at->copy()->addMinutes((int) $match->duration_minutes);
        }
        $this->assertNoScheduleConflict($match, $data['judge_ids'] ?? []);
        $this->assertFacilityAvailability($match->room_id, $match->scheduled_at, $match->scheduled_end_at, $match->duration_minutes);
        $match->save();
        $this->audit->record('MatchScheduleChanged', $actor, $match, $edition, before: $before, after: $match->only(array_keys($before)));

        return $match->refresh();
    }

    public function createSession(CompetitionEdition $edition, array $data, User $actor): CompetitionSession
    {
        $this->scope->assertEdition($actor, $edition);
        $category = isset($data['category_id']) ? CompetitionCategory::query()->where('edition_id', $edition->id)->findOrFail($data['category_id']) : null;
        if (isset($data['ends_at'], $data['starts_at']) && $data['ends_at'] <= $data['starts_at']) {
            throw ValidationException::withMessages(['ends_at' => 'Waktu selesai harus sesudah waktu mulai.']);
        }

        return DB::transaction(function () use ($edition, $data, $actor, $category): CompetitionSession {
            $session = CompetitionSession::query()->create([
                'edition_id' => $edition->id,
                'category_id' => $category?->id,
                'round_id' => $data['round_id'] ?? null,
                'event_session_id' => $data['event_session_id'] ?? null,
                'code' => strtoupper((string) ($data['code'] ?? 'SES-'.Str::random(7))),
                'name' => trim($data['name']),
                'session_type' => strtoupper((string) ($data['session_type'] ?? 'JUDGING')),
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'] ?? null,
                'venue_name' => $data['venue_name'] ?? null,
                'room_id' => $data['room_id'] ?? null,
                'online_url' => $data['online_url'] ?? null,
                'capacity' => $data['capacity'] ?? null,
                'judge_panel_size' => $data['judge_panel_size'] ?? null,
                'status' => 'SCHEDULED',
                'notes' => $data['notes'] ?? null,
                'settings' => $data['settings'] ?? null,
            ]);
            $this->assertNoSessionConflict($session);
            $this->assertFacilityAvailability($session->room_id, $session->starts_at, $session->ends_at, null);
            $this->audit->record('CompetitionSessionScheduled', $actor, $session, $edition, after: $session->only(['id', 'name', 'starts_at', 'venue_name']));

            return $session;
        });
    }

    public function createJudge(array $data, User $actor): CompetitionJudge
    {
        if (! $actor->can('judge.assign')) {
            abort(403);
        }
        if (empty($data['display_name']) && empty($data['employee_id']) && empty($data['user_id'])) {
            throw ValidationException::withMessages(['display_name' => 'Juri memerlukan identitas pegawai/pengguna atau nama eksternal.']);
        }

        $judge = CompetitionJudge::query()->create([
            'campus_id' => $data['campus_id'] ?? null,
            'employee_id' => $data['employee_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'display_name' => $data['display_name'] ?? ('Juri #'.($data['employee_id'] ?? $data['user_id'])),
            'expertise' => $data['expertise'] ?? null,
            'qualification' => $data['qualification'] ?? null,
            'organization_name' => $data['organization_name'] ?? null,
            'certification_number' => $data['certification_number'] ?? null,
            'is_certified' => (bool) ($data['is_certified'] ?? false),
            'max_concurrent_assignments' => $data['max_concurrent_assignments'] ?? null,
            'availability_notes' => $data['availability_notes'] ?? null,
            'conflict_declaration' => $data['conflict_declaration'] ?? [],
            'status' => 'ACTIVE',
            'is_active' => true,
        ]);
        $this->audit->record('JudgeProfileCreated', $actor, $judge, after: ['display_name' => $judge->display_name, 'employee_id' => $judge->employee_id]);

        return $judge;
    }

    public function declareJudgeConflict(CompetitionJudge $judge, array $data, User $actor): CompetitionJudgeConflict
    {
        if (! $actor->can('judge.assign')) {
            abort(403);
        }
        $participant = isset($data['participant_id']) ? CompetitionParticipant::query()->findOrFail($data['participant_id']) : null;
        if ($participant !== null) {
            $edition = CompetitionEdition::query()->findOrFail($participant->edition_id);
            $this->scope->assertEdition($actor, $edition);
        }
        $conflict = CompetitionJudgeConflict::query()->create([
            'judge_id' => $judge->id,
            'participant_id' => $participant?->id,
            'team_id' => $data['team_id'] ?? $participant?->team_id,
            'student_id' => $data['student_id'] ?? $participant?->student_id,
            'institution_name' => $data['institution_name'] ?? $participant?->institution_name,
            'conflict_type' => strtoupper((string) ($data['conflict_type'] ?? 'PERSONAL')),
            'description' => trim($data['description']),
            'declared_by' => $actor->id,
            'declared_at' => now(),
            'status' => 'DECLARED',
        ]);
        $this->audit->record('JudgeConflictDeclared', $actor, $conflict, after: ['judge_id' => $judge->id, 'participant_id' => $participant?->id, 'status' => 'DECLARED']);

        return $conflict;
    }

    public function reviewJudgeConflict(CompetitionJudgeConflict $conflict, User $actor, string $decision, ?string $reason = null, ?int $substituteJudgeId = null): CompetitionJudgeConflict
    {
        if (! $actor->can('judge.assign')) {
            abort(403);
        }
        $decision = strtoupper($decision);
        if (! in_array($decision, ['CONFIRM', 'DISMISS', 'REASSIGN'], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan konflik harus CONFIRM, DISMISS, atau REASSIGN.']);
        }
        $substitute = $substituteJudgeId ? CompetitionJudge::query()->findOrFail($substituteJudgeId) : null;
        if ($decision === 'REASSIGN' && ($substitute === null || ! $substitute->is_active)) {
            throw ValidationException::withMessages(['substitute_judge_id' => 'Juri pengganti aktif wajib dipilih.']);
        }
        if ($reason === null || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan review konflik wajib dicatat.']);
        }
        $conflict->forceFill([
            'status' => $decision === 'CONFIRM' ? 'ACCEPTED' : ($decision === 'REASSIGN' ? 'REASSIGNED' : 'RESOLVED'),
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'resolution' => $decision,
            'resolution_note' => $reason,
            'assigned_substitute_judge_id' => $substitute?->id,
        ])->save();
        $this->audit->record('JudgeConflictReviewed', $actor, $conflict, after: ['status' => $conflict->status, 'resolution' => $decision, 'substitute_judge_id' => $substitute?->id], reason: $reason);

        return $conflict->refresh();
    }

    public function assignJudge(CompetitionEdition $edition, CompetitionJudge $judge, array $data, User $actor): CompetitionJudgeAssignment
    {
        $this->scope->assertEdition($actor, $edition);
        if (! $actor->can('judge.assign')) {
            abort(403);
        }
        if (! $judge->is_active || $judge->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['judge_id' => 'Juri tidak aktif.']);
        }
        $match = isset($data['match_id']) ? CompetitionMatch::query()->findOrFail($data['match_id']) : null;
        $session = isset($data['session_id']) ? CompetitionSession::query()->findOrFail($data['session_id']) : null;
        $round = isset($data['round_id']) ? CompetitionRound::query()->findOrFail($data['round_id']) : null;
        if ($match && (int) $match->competitionRound->edition_id !== (int) $edition->id) {
            throw ValidationException::withMessages(['match_id' => 'Pertandingan bukan milik edition ini.']);
        }
        if ($session && (int) $session->edition_id !== (int) $edition->id) {
            throw ValidationException::withMessages(['session_id' => 'Sesi bukan milik edition ini.']);
        }
        if ($round && (int) $round->edition_id !== (int) $edition->id) {
            throw ValidationException::withMessages(['round_id' => 'Babak bukan milik edition ini.']);
        }
        if (isset($data['category_id']) && ! CompetitionCategory::query()->where('edition_id', $edition->id)->whereKey($data['category_id'])->exists()) {
            throw ValidationException::withMessages(['category_id' => 'Kategori bukan milik edition ini.']);
        }
        if ($judge->campus_id !== null && $edition->campus_id !== null && (int) $judge->campus_id !== (int) $edition->campus_id) {
            throw ValidationException::withMessages(['judge_id' => 'Juri tidak berada pada cakupan kampus edition ini.']);
        }
        $assignmentQuery = CompetitionJudgeAssignment::query()->where('edition_id', $edition->id)->where('judge_id', $judge->id)
            ->whereNotIn('status', ['DECLINED', 'CANCELLED']);
        if ($match !== null) {
            $assignmentQuery->where('match_id', $match->id);
        } elseif ($session !== null) {
            $assignmentQuery->where('session_id', $session->id);
        } elseif ($round !== null) {
            $assignmentQuery->where('round_id', $round->id);
        } else {
            $assignmentQuery->where('category_id', $data['category_id'] ?? null)->whereNull('match_id')->whereNull('session_id')->whereNull('round_id');
        }
        $assignment = $assignmentQuery->first();
        if ($assignment !== null) {
            return $assignment;
        }

        $targetParticipants = $match ? array_values(array_filter([$match->home_participant_id, $match->away_participant_id])) : [];
        if ($this->hasDeclaredConflict($judge, $targetParticipants, $match)) {
            throw ValidationException::withMessages(['judge_id' => 'Juri memiliki konflik kepentingan pada peserta/instansi ini.']);
        }
        if ($match?->scheduled_at !== null) {
            $this->assertJudgeAvailable($judge, $match);
        }

        $assignment = CompetitionJudgeAssignment::query()->create([
            'edition_id' => $edition->id,
            'judge_id' => $judge->id,
            'category_id' => $data['category_id'] ?? $match?->category_id ?? $session?->category_id,
            'round_id' => $round?->id ?? $match?->round_id ?? $session?->round_id,
            'session_id' => $session?->id ?? $match?->session_id,
            'match_id' => $match?->id,
            'assignment_role' => strtoupper((string) ($data['assignment_role'] ?? 'JUDGE')),
            'is_head_judge' => (bool) ($data['is_head_judge'] ?? false),
            'status' => 'ASSIGNED',
            'assigned_by' => $actor->id,
            'assigned_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);
        $this->audit->record('JudgeAssigned', $actor, $assignment, $edition, after: ['judge_id' => $judge->id, 'match_id' => $match?->id, 'session_id' => $session?->id, 'role' => $assignment->assignment_role]);

        return $assignment;
    }

    public function assignmentsForJudge(User $actor)
    {
        return CompetitionJudgeAssignment::query()->with(['competitionJudge:id,display_name,expertise', 'competitionMatch:id,round_id,match_number,scheduled_at,venue_name,status', 'competitionSession:id,name,starts_at,venue_name,status'])
            ->whereIn('judge_id', CompetitionJudge::query()->where('user_id', $actor->id)->select('id'))
            ->whereNotIn('status', ['DECLINED', 'CANCELLED'])
            ->orderBy('assigned_at')->paginate(50);
    }

    public function checkIn(CompetitionEdition $edition, array $data, User $actor): CompetitionCheckin
    {
        $this->scope->assertEdition($actor, $edition);
        $participant = isset($data['participant_id']) ? CompetitionParticipant::query()->where('edition_id', $edition->id)->findOrFail($data['participant_id']) : null;
        $team = isset($data['team_id']) ? CompetitionTeam::query()->where('edition_id', $edition->id)->findOrFail($data['team_id']) : null;
        if ($participant === null && $team === null) {
            throw ValidationException::withMessages(['participant_id' => 'Pilih peserta atau tim untuk check-in.']);
        }
        if ($participant && ! in_array($participant->eligibility_status, ['ELIGIBLE', 'OVERRIDE_APPROVED'], true)) {
            throw ValidationException::withMessages(['participant_id' => 'Peserta belum eligible untuk check-in.']);
        }
        $key = $data['idempotency_key'] ?? null;
        if ($key !== null) {
            $existing = CompetitionCheckin::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        $checkin = CompetitionCheckin::query()->create([
            'edition_id' => $edition->id,
            'category_id' => $participant?->category_id ?? $team?->category_id,
            'participant_id' => $participant?->id,
            'team_id' => $team?->id,
            'registration_id' => $participant?->registration_id ?? $team?->registration_id,
            'session_id' => $data['session_id'] ?? null,
            'match_id' => $data['match_id'] ?? null,
            'checkin_method' => strtoupper((string) ($data['checkin_method'] ?? 'MANUAL')),
            'identity_verification_method' => $data['identity_verification_method'] ?? null,
            'identity_reference' => $data['identity_reference'] ?? null,
            'status' => 'CHECKED_IN',
            'eligibility_confirmed' => true,
            'checked_in_at' => now(),
            'checked_in_by' => $actor->id,
            'device_id' => $data['device_id'] ?? null,
            'location' => $data['location'] ?? null,
            'idempotency_key' => $key,
            'notes' => $data['notes'] ?? null,
        ]);
        $this->audit->record('ParticipantCheckedIn', $actor, $checkin, $edition, after: ['participant_id' => $participant?->id, 'team_id' => $team?->id, 'method' => $checkin->checkin_method]);

        return $checkin;
    }

    public function markNoShow(CompetitionCheckin $checkin, User $actor, string $reason): CompetitionCheckin
    {
        ($actor->can('participant.manage') || $actor->can('schedule.manage')) || abort(403);
        $checkin->forceFill(['status' => 'NO_SHOW', 'notes' => trim(($checkin->notes ? $checkin->notes.'\n' : '').$reason)])->save();
        $this->audit->record('ParticipantNoShow', $actor, $checkin, after: ['status' => 'NO_SHOW'], reason: $reason);

        return $checkin->refresh();
    }

    public function transitionMatch(CompetitionMatch $match, User $actor, string $transition): CompetitionMatch
    {
        $transition = strtoupper($transition);
        $round = CompetitionRound::query()->findOrFail($match->round_id);
        $edition = CompetitionEdition::query()->findOrFail($round->edition_id);
        $this->scope->assertEdition($actor, $edition);
        $transitions = [
            'START' => ['from' => ['SCHEDULED', 'CHECK_IN', 'READY'], 'to' => 'RUNNING'],
            'PAUSE' => ['from' => ['RUNNING'], 'to' => 'PAUSED'],
            'RESUME' => ['from' => ['PAUSED'], 'to' => 'RUNNING'],
            'COMPLETE' => ['from' => ['RUNNING', 'PAUSED'], 'to' => 'COMPLETED'],
            'POSTPONE' => ['from' => ['SCHEDULED', 'CHECK_IN', 'READY'], 'to' => 'POSTPONED'],
            'CANCEL' => ['from' => ['SCHEDULED', 'POSTPONED'], 'to' => 'CANCELLED'],
        ];
        if (! isset($transitions[$transition]) || ! in_array($match->status, $transitions[$transition]['from'], true)) {
            throw ValidationException::withMessages(['status' => 'Transisi pertandingan tidak valid.']);
        }
        if ($transition === 'START') {
            $participants = array_values(array_filter([$match->home_participant_id, $match->away_participant_id]));
            foreach ($participants as $participantId) {
                if (! CompetitionCheckin::query()->where('participant_id', $participantId)->where('edition_id', $edition->id)->whereIn('status', ['CHECKED_IN', 'LATE'])->exists()) {
                    throw ValidationException::withMessages(['checkin' => 'Semua peserta pertandingan harus check-in sebelum pertandingan dimulai.']);
                }
            }
        }
        if ($transition === 'COMPLETE') {
            $category = CompetitionCategory::query()->findOrFail($match->category_id);
            if ($category->requires_judge) {
                $scorecards = CompetitionScorecard::query()->where('match_id', $match->id);
                if ($scorecards->whereNotIn('status', ['LOCKED', 'AMENDED'])->exists()) {
                    throw ValidationException::withMessages(['scores' => 'Selesaikan/finalisasi scorecard sebelum menutup pertandingan.']);
                }
                foreach (array_values(array_filter([$match->home_participant_id, $match->away_participant_id])) as $participantId) {
                    if (! CompetitionScorecard::query()->where('match_id', $match->id)->where('participant_id', $participantId)->whereIn('status', ['LOCKED', 'AMENDED'])->exists()) {
                        throw ValidationException::withMessages(['scores' => 'Setiap peserta pertandingan memerlukan minimal satu scorecard final.']);
                    }
                }
            }
        }
        $before = ['status' => $match->status];
        $match->forceFill([
            'status' => $transitions[$transition]['to'],
            'started_at' => $transition === 'START' ? now() : $match->started_at,
            'ended_at' => $transition === 'COMPLETE' ? now() : $match->ended_at,
        ])->save();
        if ($transition === 'COMPLETE') {
            $this->setMatchWinner($match);
            if (! CompetitionMatch::query()->where('round_id', $round->id)->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'VOID'])->exists()) {
                $round->forceFill(['status' => 'COMPLETED', 'ends_on' => $round->ends_on ?? now()])->save();
            }
        }
        $event = match ($transition) {
            'START' => 'MatchStarted', 'COMPLETE' => 'MatchCompleted', default => 'Match'.Str::ucfirst(mb_strtolower($transition)),
        };
        $this->audit->record($event, $actor, $match, $edition, before: $before, after: ['status' => $match->status]);

        return $match->refresh();
    }

    public function reportScheduleConflicts(CompetitionEdition $edition, User $actor): array
    {
        $this->scope->assertEdition($actor, $edition);
        $matches = CompetitionMatch::query()->whereIn('round_id', CompetitionRound::query()->where('edition_id', $edition->id)->select('id'))
            ->whereNotNull('scheduled_at')->whereNotIn('status', ['CANCELLED', 'VOID'])->orderBy('scheduled_at')->get();
        $conflicts = [];
        for ($i = 0; $i < $matches->count(); $i++) {
            for ($j = $i + 1; $j < $matches->count(); $j++) {
                $a = $matches[$i];
                $b = $matches[$j];
                if ($this->overlap($a->scheduled_at, $a->scheduled_end_at, $b->scheduled_at, $b->scheduled_end_at)) {
                    $sharedParticipant = array_intersect(
                        array_filter([$a->home_participant_id, $a->away_participant_id, $a->home_team_id, $a->away_team_id]),
                        array_filter([$b->home_participant_id, $b->away_participant_id, $b->home_team_id, $b->away_team_id]),
                    );
                    if ($sharedParticipant !== []) {
                        $conflicts[] = ['type' => 'PARTICIPANT', 'match_ids' => [$a->id, $b->id], 'references' => array_values($sharedParticipant)];
                    }
                    if ($a->venue_name && $b->venue_name && mb_strtolower($a->venue_name) === mb_strtolower($b->venue_name)) {
                        $conflicts[] = ['type' => 'VENUE', 'match_ids' => [$a->id, $b->id], 'venue' => $a->venue_name];
                    }
                }
            }
        }

        return $conflicts;
    }

    private function knockout(CompetitionRound $round, $participants, User $actor, array $options): array
    {
        $size = max((int) ($options['bracket_size'] ?? $round->bracket_size ?? $this->nextPowerOfTwo($participants->count())), $participants->count());
        $size = $this->nextPowerOfTwo($size);
        $stageCount = (int) log($size, 2);
        $bracket = CompetitionBracket::query()->create([
            'round_id' => $round->id,
            'bracket_type' => 'KNOCKOUT',
            'size' => $size,
            'status' => 'GENERATED',
            'generated_by' => $actor->id,
            'generated_at' => now(),
            'is_locked' => false,
            'settings' => $options,
        ]);
        $slotsByStage = [];
        $participantIndex = 0;
        for ($stage = 1; $stage <= $stageCount; $stage++) {
            $slotCount = (int) ($size / (2 ** ($stage - 1)));
            for ($position = 1; $position <= $slotCount; $position++) {
                $participant = $stage === 1 ? $participants->get($participantIndex++) : null;
                if ($stage === 1 && $participant === null) {
                    $participantId = null;
                    $teamId = null;
                    $status = 'BYE';
                    $isBye = true;
                } else {
                    $participantId = $participant?->id;
                    $teamId = $participant?->team_id;
                    $status = $participant ? 'ASSIGNED' : 'EMPTY';
                    $isBye = false;
                }
                $slotsByStage[$stage][$position] = CompetitionBracketSlot::query()->create([
                    'bracket_id' => $bracket->id,
                    'round_number' => $stage,
                    'position' => $position,
                    'participant_id' => $participantId,
                    'team_id' => $teamId,
                    'seed' => $participant?->seed_number,
                    'status' => $status,
                    'is_bye' => $isBye,
                ]);
            }
        }
        for ($stage = 1; $stage <= $stageCount; $stage++) {
            $slotCount = count($slotsByStage[$stage]);
            for ($pair = 1; $pair <= (int) ($slotCount / 2); $pair++) {
                $left = $slotsByStage[$stage][($pair * 2) - 1];
                $right = $slotsByStage[$stage][$pair * 2];
                if ($stage < $stageCount) {
                    $target = $slotsByStage[$stage + 1][$pair];
                    $left->forceFill(['winner_slot_id' => $target->id])->save();
                    $right->forceFill(['winner_slot_id' => $target->id])->save();
                    $target->forceFill(['source_slot_id' => $left->id])->save();
                }
                if ($stage === 1 && $left->participant_id !== null && $right->participant_id !== null) {
                    $this->insertGeneratedMatch($round, (int) $left->participant_id, (int) $right->participant_id, $pair);
                } elseif ($stage === 1 && $left->participant_id !== null && $right->is_bye) {
                    $left->forceFill(['status' => 'BYE_ADVANCED'])->save();
                    CompetitionAdvancement::query()->create([
                        'round_id' => $round->id,
                        'participant_id' => $left->participant_id,
                        'to_round_id' => $round->id,
                        'status' => 'ADVANCED',
                        'method' => 'BYE',
                        'decided_by' => $actor->id,
                        'decided_at' => now(),
                    ]);
                }
            }
        }
        $bracket->forceFill(['render_data' => $this->renderBracket($slotsByStage)])->save();

        return CompetitionMatch::query()->where('round_id', $round->id)->get()->all();
    }

    private function roundRobin(CompetitionRound $round, $participants, User $actor, array $options): array
    {
        $participantList = $participants->values()->all();
        if (count($participantList) % 2 === 1) {
            $participantList[] = null;
        }
        $count = count($participantList);
        $roundCount = $count - 1;
        $matches = [];
        $group = CompetitionGroup::query()->create([
            'round_id' => $round->id,
            'code' => 'GROUP-A',
            'name' => $options['group_name'] ?? 'Group A',
            'capacity' => count($participants),
            'qualification_count' => $options['qualification_count'] ?? null,
            'status' => 'ACTIVE',
        ]);
        foreach ($participants as $participant) {
            CompetitionGroupMember::query()->create(['group_id' => $group->id, 'participant_id' => $participant->id, 'team_id' => $participant->team_id, 'seed' => $participant->seed_number, 'status' => 'ACTIVE']);
        }
        for ($roundNo = 0; $roundNo < $roundCount; $roundNo++) {
            for ($i = 0; $i < (int) ($count / 2); $i++) {
                $home = $participantList[$i];
                $away = $participantList[$count - 1 - $i];
                if ($home !== null && $away !== null) {
                    $homeFirst = ($roundNo + $i) % 2 === 0;
                    $matches[] = $this->insertGeneratedMatch($round, $homeFirst ? (int) $home->id : (int) $away->id, $homeFirst ? (int) $away->id : (int) $home->id, count($matches) + 1, $group->id);
                }
            }
            $fixed = array_shift($participantList);
            $last = array_pop($participantList);
            array_unshift($participantList, $fixed);
            array_splice($participantList, 1, 0, [$last]);
        }
        if (($options['home_away'] ?? false) === true) {
            $firstLeg = $matches;
            foreach ($firstLeg as $match) {
                $matches[] = $this->insertGeneratedMatch($round, (int) $match->away_participant_id, (int) $match->home_participant_id, count($matches) + 1, $group->id);
            }
        }

        return $matches;
    }

    private function swiss(CompetitionRound $round, $participants, User $actor, array $options): array
    {
        $points = CompetitionGroupMember::query()
            ->whereIn('participant_id', $participants->pluck('id'))
            ->orderByDesc('points')->orderByDesc('score_for')->get()->keyBy('participant_id');
        $ordered = $participants->sort(function ($a, $b) use ($points): int {
            $aScore = (float) ($points[$a->id]->points ?? 0);
            $bScore = (float) ($points[$b->id]->points ?? 0);

            return $bScore <=> $aScore ?: ((int) ($a->seed_number ?? $a->id) <=> (int) ($b->seed_number ?? $b->id));
        })->values();
        $matches = [];
        $unpaired = $ordered->all();
        $previousPairs = CompetitionMatch::query()->whereIn('round_id', CompetitionRound::query()->where('category_id', $round->category_id)->where('sequence', '<', $round->sequence)->select('id'))->get();
        $played = [];
        foreach ($previousPairs as $prior) {
            $played[$prior->home_participant_id.':'.$prior->away_participant_id] = true;
            $played[$prior->away_participant_id.':'.$prior->home_participant_id] = true;
        }
        $matchNumber = 1;
        while (count($unpaired) >= 2) {
            $home = array_shift($unpaired);
            $candidateIndex = null;
            foreach ($unpaired as $index => $candidate) {
                if (! isset($played[$home->id.':'.$candidate->id])) {
                    $candidateIndex = $index;
                    break;
                }
            }
            $candidateIndex ??= 0;
            $away = array_splice($unpaired, $candidateIndex, 1)[0];
            $matches[] = $this->insertGeneratedMatch($round, (int) $home->id, (int) $away->id, $matchNumber++);
        }

        return $matches;
    }

    private function insertGeneratedMatch(CompetitionRound $round, int $homeId, int $awayId, int $number, ?int $groupId = null): CompetitionMatch
    {
        return CompetitionMatch::query()->create([
            'round_id' => $round->id,
            'category_id' => $round->category_id,
            'group_id' => $groupId,
            'match_number' => $number,
            'code' => strtoupper('M-'.Str::random(8)),
            'home_participant_id' => $homeId,
            'home_team_id' => CompetitionParticipant::query()->find($homeId)?->team_id,
            'away_participant_id' => $awayId,
            'away_team_id' => CompetitionParticipant::query()->find($awayId)?->team_id,
            'status' => 'SCHEDULED',
            'scheduled_at' => null,
        ]);
    }

    private function assertFacilityAvailability(?int $roomId, $startsAt, $endsAt, ?int $durationMinutes): void
    {
        if ($roomId === null || ! $this->facilities->isAvailable()) {
            return;
        }
        $start = $startsAt instanceof DateTimeInterface ? $startsAt : Carbon::parse($startsAt);
        $end = $endsAt instanceof DateTimeInterface ? $endsAt : ($endsAt ? Carbon::parse($endsAt) : $start->copy()->addMinutes($durationMinutes ?: 60));
        $availability = $this->facilities->checkAvailability([
            'room_id' => $roomId,
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
        ]);
        if (! ($availability['available'] ?? false)) {
            throw ValidationException::withMessages(['room_id' => $availability['conflicts'][0]['reason'] ?? 'Facility provider melaporkan konflik ruangan.']);
        }
    }

    private function assertNoScheduleConflict(CompetitionMatch $match, array $judgeIds = []): void
    {
        $start = $match->scheduled_at;
        $end = $match->scheduled_end_at ?? $start?->copy()->addMinutes((int) ($match->duration_minutes ?: 60));
        if ($start === null || $end === null) {
            return;
        }
        $candidateIds = array_values(array_filter([$match->home_participant_id, $match->away_participant_id, $match->home_team_id, $match->away_team_id]));
        $candidates = CompetitionMatch::query()->where('id', '<>', $match->id)->whereNotNull('scheduled_at')
            ->whereNotIn('status', ['CANCELLED', 'VOID', 'POSTPONED'])->where('scheduled_at', '<', $end)->get()
            ->filter(fn (CompetitionMatch $other): bool => $this->overlap(
                $start, $end, $other->scheduled_at,
                $other->scheduled_end_at ?? $other->scheduled_at->copy()->addMinutes((int) ($other->duration_minutes ?: 60)),
            ));
        foreach ($candidates as $other) {
            $otherIds = array_values(array_filter([$other->home_participant_id, $other->away_participant_id, $other->home_team_id, $other->away_team_id]));
            if ($candidateIds !== [] && array_intersect($candidateIds, $otherIds) !== []) {
                throw ValidationException::withMessages(['scheduled_at' => 'Peserta/tim memiliki pertandingan lain pada rentang waktu yang sama.']);
            }
            if ($match->venue_name !== null && $other->venue_name !== null && mb_strtolower($match->venue_name) === mb_strtolower($other->venue_name)) {
                throw ValidationException::withMessages(['venue_name' => 'Venue sudah digunakan pada waktu tersebut.']);
            }
            if ($match->room_id !== null && (int) $match->room_id === (int) $other->room_id) {
                throw ValidationException::withMessages(['room_id' => 'Ruang sudah digunakan pada waktu tersebut.']);
            }
        }
        foreach ($judgeIds as $judgeId) {
            $judge = CompetitionJudge::query()->find($judgeId);
            if ($judge) {
                $this->assertJudgeAvailable($judge, $match);
            }
        }
    }

    private function assertNoSessionConflict(CompetitionSession $session): void
    {
        if ($session->venue_name === null && $session->room_id === null) {
            return;
        }
        $end = $session->ends_at ?? $session->starts_at->copy()->addHour();
        $candidates = CompetitionSession::query()->where('id', '<>', $session->id)
            ->whereNotIn('status', ['CANCELLED', 'COMPLETED'])->where('starts_at', '<', $end)->get()
            ->filter(fn (CompetitionSession $other): bool => $this->overlap(
                $session->starts_at, $end, $other->starts_at,
                $other->ends_at ?? $other->starts_at->copy()->addHour(),
            ));
        foreach ($candidates as $other) {
            if ($session->venue_name !== null && $other->venue_name !== null && mb_strtolower($session->venue_name) === mb_strtolower($other->venue_name)) {
                throw ValidationException::withMessages(['venue_name' => 'Venue bertabrakan dengan sesi kompetisi lain.']);
            }
            if ($session->room_id !== null && (int) $session->room_id === (int) $other->room_id) {
                throw ValidationException::withMessages(['room_id' => 'Ruang bertabrakan dengan sesi kompetisi lain.']);
            }
        }
    }

    private function assertJudgeAvailable(CompetitionJudge $judge, CompetitionMatch $match): void
    {
        $start = $match->scheduled_at;
        $end = $match->scheduled_end_at ?? $start?->copy()->addMinutes((int) ($match->duration_minutes ?: 60));
        if ($start === null || $end === null) {
            return;
        }
        $overlapping = 0;
        $assignments = CompetitionJudgeAssignment::query()->where('judge_id', $judge->id)->whereNotIn('status', ['DECLINED', 'CANCELLED'])->whereNotNull('match_id')->get();
        foreach ($assignments as $assignment) {
            if ((int) $assignment->match_id === (int) $match->id) {
                continue;
            }
            $other = CompetitionMatch::query()->find($assignment->match_id);
            if ($other && $other->scheduled_at && $this->overlap(
                $start, $end, $other->scheduled_at,
                $other->scheduled_end_at ?? $other->scheduled_at->copy()->addMinutes((int) ($other->duration_minutes ?: 60)),
            )) {
                $overlapping++;
            }
        }
        $limit = (int) ($judge->max_concurrent_assignments ?: 1);
        if ($overlapping >= $limit) {
            throw ValidationException::withMessages(['judge_id' => 'Juri sudah mencapai kapasitas penugasan pada rentang waktu tersebut.']);
        }
    }

    private function hasDeclaredConflict(CompetitionJudge $judge, array $participantIds, ?CompetitionMatch $match): bool
    {
        if ($participantIds === []) {
            return false;
        }
        $participants = CompetitionParticipant::query()->whereIn('id', $participantIds)->get(['student_id', 'team_id', 'institution_name']);
        $studentIds = $participants->pluck('student_id')->filter()->map(fn ($id) => (int) $id)->all();
        $institutions = $participants->pluck('institution_name')->filter()->map(fn ($name) => mb_strtolower((string) $name))->all();
        $declared = CompetitionJudgeConflict::query()->where('judge_id', $judge->id)
            ->whereIn('status', ['DECLARED', 'UNDER_REVIEW', 'ACCEPTED', 'ACTIVE'])
            ->where(function (Builder $query) use ($participantIds, $studentIds, $institutions, $match): void {
                $query->whereIn('participant_id', $participantIds);
                if ($studentIds !== []) {
                    $query->orWhereIn('student_id', $studentIds);
                }
                if ($match?->home_team_id || $match?->away_team_id) {
                    $query->orWhereIn('team_id', array_values(array_filter([$match->home_team_id, $match->away_team_id])));
                }
                foreach ($institutions as $institution) {
                    $query->orWhereRaw('LOWER(institution_name) = ?', [$institution]);
                }
            })->exists();
        if ($declared) {
            return true;
        }
        $declaration = (array) ($judge->conflict_declaration ?? []);
        $conflictedStudentIds = array_map('intval', (array) ($declaration['student_ids'] ?? []));
        $conflictedTeamIds = array_map('intval', (array) ($declaration['team_ids'] ?? []));
        $conflictedInstitutions = array_map(fn ($name) => mb_strtolower((string) $name), (array) ($declaration['institution_names'] ?? []));
        foreach ($participants as $participant) {
            if ($participant->student_id && in_array((int) $participant->student_id, $conflictedStudentIds, true)) {
                return true;
            }
            if ($participant->team_id && in_array((int) $participant->team_id, $conflictedTeamIds, true)) {
                return true;
            }
            if ($participant->institution_name && in_array(mb_strtolower((string) $participant->institution_name), $conflictedInstitutions, true)) {
                return true;
            }
        }
        if ($match !== null && array_intersect(array_filter([$match->home_team_id, $match->away_team_id]), $conflictedTeamIds) !== []) {
            return true;
        }

        return false;
    }

    private function setMatchWinner(CompetitionMatch $match): void
    {
        $cards = CompetitionScorecard::query()
            ->where('match_id', $match->id)->whereIn('status', ['LOCKED', 'AMENDED'])
            ->select('participant_id', DB::raw('AVG(final_score) as score'))->groupBy('participant_id')->get();
        $homeScore = (float) ($cards->firstWhere('participant_id', $match->home_participant_id)?->score ?? 0);
        $awayScore = (float) ($cards->firstWhere('participant_id', $match->away_participant_id)?->score ?? 0);
        $lowerBetter = CompetitionCategory::query()->find($match->category_id)?->ranking_model === 'LOWER_BETTER';
        $homeWins = $lowerBetter ? $homeScore < $awayScore : $homeScore > $awayScore;
        $draw = abs($homeScore - $awayScore) < 0.0001;
        $match->forceFill([
            'score_a' => $homeScore,
            'score_b' => $awayScore,
            'is_draw' => $draw,
            'winner_participant_id' => $draw ? null : ($homeWins ? $match->home_participant_id : $match->away_participant_id),
            'winner_team_id' => $draw ? null : ($homeWins ? $match->home_team_id : $match->away_team_id),
            'result_summary' => $draw ? 'Draw' : ($homeWins ? 'Home wins' : 'Away wins'),
        ])->save();
    }

    private function renderBracket(array $stages): array
    {
        $render = [];
        foreach ($stages as $stage => $slots) {
            $render[] = [
                'round' => $stage,
                'name' => $stage === count($stages) ? 'Final' : ($stage === count($stages) - 1 ? 'Semifinal' : 'Round '.$stage),
                'slots' => array_values(array_map(fn (CompetitionBracketSlot $slot) => [
                    'slot_id' => $slot->id,
                    'position' => $slot->position,
                    'participant_id' => $slot->participant_id,
                    'team_id' => $slot->team_id,
                    'seed' => $slot->seed,
                    'status' => $slot->status,
                    'bye' => $slot->is_bye,
                ], $slots)),
            ];
        }

        return $render;
    }

    private function nextPowerOfTwo(int $value): int
    {
        $power = 1;
        while ($power < $value) {
            $power *= 2;
        }

        return $power;
    }

    private function assertDistinctParticipants(?int $home, ?int $away): void
    {
        if ($home !== null && $home === $away) {
            throw ValidationException::withMessages(['participants' => 'Peserta yang sama tidak dapat bertanding melawan dirinya sendiri.']);
        }
    }

    private function overlap($startA, $endA, $startB, $endB): bool
    {
        $endA ??= $startA->copy()->addHour();
        $endB ??= $startB->copy()->addHour();

        return $startA < $endB && $startB < $endA;
    }
}
