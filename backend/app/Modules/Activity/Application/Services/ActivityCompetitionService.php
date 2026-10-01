<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Enums\CompetitionStatus;
use App\Modules\Activity\Domain\Enums\ParticipantStatus;
use App\Modules\Activity\Domain\Enums\ResultStatus;
use App\Modules\Activity\Domain\Enums\RoundType;
use App\Modules\Activity\Domain\Enums\ScoreStatus;
use App\Modules\Activity\Domain\Enums\SelectionMethod;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityCompetition;
use App\Modules\Activity\Models\ActivityCompetitionCategory;
use App\Modules\Activity\Models\ActivityCompetitionJudge;
use App\Modules\Activity\Models\ActivityCompetitionMatch;
use App\Modules\Activity\Models\ActivityCompetitionMember;
use App\Modules\Activity\Models\ActivityCompetitionParticipant;
use App\Modules\Activity\Models\ActivityCompetitionResult;
use App\Modules\Activity\Models\ActivityCompetitionRound;
use App\Modules\Activity\Models\ActivityCompetitionScore;
use App\Modules\Activity\Models\ActivityCompetitionTeam;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Kompetisi (§46–51, §95).
 *
 * Alur lomba dibuat eksplisit supaya hasilnya dapat dipertanggungjawabkan:
 *
 *  ​ pendaftaran peserta → verifikasi → babak → pertandingan → skor juri →
 *   **penguncian skor** → perhitungan hasil → verifikasi → penerbitan → prestasi.
 *
 * Skor yang sudah dikunci tidak dapat disunting; koreksi membuat baris baru yang
 * menunjuk baris lama beserta alasannya. Juri hanya melihat dan menilai apa yang
 * ditugaskan kepadanya — bukan seluruh kompetisi.
 */
class ActivityCompetitionService
{
    public function __construct(
        private readonly ActivityAuditService $audit,
        private readonly ActivityAccessService $access,
        private readonly ActivityAchievementService $achievements,
        private readonly ActivityNotificationService $notifications,
    ) {}

    /** @return LengthAwarePaginator */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return ActivityCompetition::query()
            ->with(['program:id,name', 'categories:id,competition_id,name'])
            ->withCount(['participants', 'teams', 'matches'])
            ->when(isset($filters['level']), fn ($q) => $q->where('level', $filters['level']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['program_id']), fn ($q) => $q->where('program_id', $filters['program_id']))
            ->orderByDesc('starts_on')
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function detail(ActivityCompetition $competition): ActivityCompetition
    {
        return $competition->load([
            'program:id,name',
            'categories',
            'rounds.matches.participantA.student:id,full_name',
            'rounds.matches.participantB.student:id,full_name',
            'teams.members.student:id,full_name',
            'judges.user:id,name',
            'results.participant.student:id,full_name',
            'results.team:id,name',
        ]);
    }

    public function create(array $attributes, User $actor): ActivityCompetition
    {
        if (! $actor->can('activity.competition.manage')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang membuat kompetisi.');
        }

        return DB::transaction(function () use ($attributes, $actor) {
            $competition = ActivityCompetition::query()->create([
                'code' => $attributes['code'] ?? $this->nextCode(),
                'name' => $attributes['name'],
                'program_id' => $attributes['program_id'] ?? null,
                'level' => $attributes['level'] ?? 'SCHOOL',
                'participant_type' => $attributes['participant_type'] ?? 'INDIVIDUAL',
                'organizer' => $attributes['organizer'] ?? null,
                'location' => $attributes['location'] ?? null,
                'description' => $attributes['description'] ?? null,
                'registration_opens' => $attributes['registration_opens'] ?? null,
                'registration_closes' => $attributes['registration_closes'] ?? null,
                'starts_on' => $attributes['starts_on'] ?? null,
                'ends_on' => $attributes['ends_on'] ?? null,
                'fee_amount' => $attributes['fee_amount'] ?? 0,
                'status' => $attributes['status'] ?? CompetitionStatus::DRAFT->value,
                'created_by' => $actor->id,
                'metadata' => $attributes['metadata'] ?? null,
            ]);

            foreach ($attributes['categories'] ?? [] as $index => $category) {
                ActivityCompetitionCategory::query()->create([
                    'competition_id' => $competition->id,
                    'code' => $category['code'] ?? 'CAT-'.($index + 1),
                    'name' => $category['name'],
                    'gender' => $category['gender'] ?? 'ANY',
                    'minimum_grade_level' => $category['minimum_grade_level'] ?? null,
                    'maximum_grade_level' => $category['maximum_grade_level'] ?? null,
                    'maximum_participants' => $category['maximum_participants'] ?? null,
                    'maximum_team_size' => $category['maximum_team_size'] ?? null,
                ]);
            }

            foreach ($attributes['judges'] ?? [] as $judge) {
                $this->assignJudge($competition, $judge, $actor);
            }

            $this->audit->record(AuditAction::COMPETITION_CREATED, [
                'subject' => $competition,
                'program_id' => $competition->program_id === null ? null : (int) $competition->program_id,
                'after' => $competition,
                'actor' => $actor->id,
            ]);

            return $competition->load('categories');
        });
    }

    public function openRegistration(ActivityCompetition $competition, User $actor): ActivityCompetition
    {
        $this->assertManage($competition, $actor);

        $competition->forceFill(['status' => CompetitionStatus::REGISTRATION_OPEN->value])->save();

        return $competition->refresh();
    }

    // ------------------------------------------------------------- peserta

    /** Daftarkan peserta individu (idempoten). */
    public function registerIndividual(ActivityCompetition $competition, int $studentId, int $categoryId, User $actor, array $payload = []): ActivityCompetitionParticipant
    {
        if (! $this->registrationOpen($competition)) {
            throw ActivityConflictException::make('Pendaftaran kompetisi ini sedang ditutup.');
        }

        $existing = ActivityCompetitionParticipant::query()
            ->where('competition_id', $competition->id)
            ->where('category_id', $categoryId)
            ->where('student_id', $studentId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($competition, $studentId, $categoryId, $actor, $payload) {
            $category = ActivityCompetitionCategory::query()
                ->lockForUpdate()
                ->where('competition_id', $competition->id)
                ->findOrFail($categoryId);

            if ($category->maximum_participants !== null) {
                $count = ActivityCompetitionParticipant::query()
                    ->where('competition_id', $competition->id)
                    ->where('category_id', $category->id)
                    ->whereNotIn('status', [ParticipantStatus::WITHDRAWN->value, ParticipantStatus::REJECTED->value])
                    ->count();

                if ($count >= (int) $category->maximum_participants) {
                    throw ActivityConflictException::make('Kuota kategori '.$category->name.' sudah penuh.');
                }
            }

            $participant = ActivityCompetitionParticipant::query()->create([
                'competition_id' => $competition->id,
                'category_id' => $category->id,
                'student_id' => $studentId,
                'registration_type' => 'INDIVIDUAL',
                'status' => ParticipantStatus::REGISTERED->value,
                'registered_at' => now(),
                'idempotency_key' => $payload['idempotency_key'] ?? null,
            ]);

            $this->audit->record('competition.participant_registered', [
                'subject' => $participant,
                'program_id' => $competition->program_id === null ? null : (int) $competition->program_id,
                'student_id' => $studentId,
                'after' => $participant,
                'actor' => $actor->id,
            ]);

            return $participant->refresh();
        });
    }

    /** Bentuk tim beserta anggotanya (§48). */
    public function createTeam(ActivityCompetition $competition, array $attributes, User $actor): ActivityCompetitionTeam
    {
        $this->assertManage($competition, $actor);

        return DB::transaction(function () use ($competition, $attributes, $actor) {
            $category = isset($attributes['category_id'])
                ? ActivityCompetitionCategory::query()->find($attributes['category_id'])
                : null;

            $members = $attributes['members'] ?? [];

            if ($category?->maximum_team_size !== null && count($members) > (int) $category->maximum_team_size) {
                throw ActivityValidationException::withErrors([
                    'Jumlah anggota melampaui batas '.$category->maximum_team_size.' orang untuk kategori '.$category->name.'.',
                ]);
            }

            $team = ActivityCompetitionTeam::query()->create([
                'competition_id' => $competition->id,
                'category_id' => $attributes['category_id'] ?? null,
                'name' => $attributes['name'],
                'captain_student_id' => $attributes['captain_student_id'] ?? null,
                'coach_employee_id' => $attributes['coach_employee_id'] ?? null,
                'school_class_id' => $attributes['school_class_id'] ?? null,
                'status' => 'ACTIVE',
                'notes' => $attributes['notes'] ?? null,
            ]);

            $this->syncTeamMembers($team, $members);

            return $team->load('members.student:id,full_name');
        });
    }

    /** @param list<array{student_id: int, role?: string, receives_achievement?: bool}> $members */
    public function syncTeamMembers(ActivityCompetitionTeam $team, array $members): void
    {
        foreach ($members as $member) {
            ActivityCompetitionMember::query()->updateOrCreate(
                ['team_id' => $team->id, 'student_id' => $member['student_id']],
                [
                    'role' => $member['role'] ?? 'MEMBER',
                    'receives_achievement' => (bool) ($member['receives_achievement'] ?? true),
                ],
            );
        }
    }

    public function verifyParticipant(ActivityCompetitionParticipant $participant, User $actor, string $status = 'VERIFIED'): ActivityCompetitionParticipant
    {
        $this->assertManage($participant->competition ?? ActivityCompetition::query()->findOrFail($participant->competition_id), $actor);

        $participant->forceFill([
            'status' => $status,
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ])->save();

        return $participant->refresh();
    }

    // ------------------------------------------------------------ babak & jadwal

    public function createRound(ActivityCompetition $competition, array $attributes, User $actor): ActivityCompetitionRound
    {
        $this->assertManage($competition, $actor);

        return ActivityCompetitionRound::query()->create([
            'competition_id' => $competition->id,
            'category_id' => $attributes['category_id'] ?? null,
            'name' => $attributes['name'] ?? (RoundType::tryFrom($attributes['type'] ?? 'QUALIFICATION')?->label() ?? 'Babak'),
            'type' => $attributes['type'] ?? RoundType::QUALIFICATION->value,
            'sequence' => $attributes['sequence'] ?? ((int) $competition->rounds()->max('sequence') + 1),
            'scheduled_date' => $attributes['scheduled_date'] ?? null,
            'location' => $attributes['location'] ?? null,
            'status' => $attributes['status'] ?? 'SCHEDULED',
            'notes' => $attributes['notes'] ?? null,
        ]);
    }

    public function createMatch(ActivityCompetitionRound $round, array $attributes, User $actor): ActivityCompetitionMatch
    {
        $competition = ActivityCompetition::query()->findOrFail($round->competition_id);
        $this->assertManage($competition, $actor);

        return ActivityCompetitionMatch::query()->create([
            'round_id' => $round->id,
            'competition_id' => $competition->id,
            'match_number' => $attributes['match_number'] ?? ((int) $round->matches()->max('match_number') + 1),
            'participant_a_id' => $attributes['participant_a_id'] ?? null,
            'participant_b_id' => $attributes['participant_b_id'] ?? null,
            'scheduled_at' => $attributes['scheduled_at'] ?? null,
            'venue' => $attributes['venue'] ?? null,
            'status' => $attributes['status'] ?? 'SCHEDULED',
            'notes' => $attributes['notes'] ?? null,
        ]);
    }

    // ---------------------------------------------------------------- juri

    public function assignJudge(ActivityCompetition $competition, array $attributes, User $actor): ActivityCompetitionJudge
    {
        if (! $actor->can('activity.competition.manage')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang menugaskan juri.');
        }

        if (empty($attributes['user_id']) && empty($attributes['employee_id'])) {
            throw ActivityValidationException::withErrors(['Juri harus memiliki akun pengguna atau pegawai.']);
        }

        $judge = ActivityCompetitionJudge::query()->firstOrNew([
            'competition_id' => $competition->id,
            'user_id' => $attributes['user_id'] ?? null,
        ]);

        $judge->fill([
            'employee_id' => $attributes['employee_id'] ?? null,
            'name' => $attributes['name'] ?? null,
            'category_id' => $attributes['category_id'] ?? null,
            'round_id' => $attributes['round_id'] ?? null,
            'status' => 'ACTIVE',
            'assigned_by' => $actor->id,
            'notes' => $attributes['notes'] ?? null,
        ])->save();

        return $judge->refresh();
    }

    /** Penampilan yang tersedia untuk juri ini (sesuai cakupan tugasnya). */
    public function matchesForJudge(ActivityCompetitionJudge $judge): array
    {
        return ActivityCompetitionMatch::query()
            ->with(['round:id,name,type', 'participantA.student:id,full_name', 'participantB.student:id,full_name'])
            ->where('competition_id', $judge->competition_id)
            ->when($judge->round_id !== null, fn ($query) => $query->where('round_id', $judge->round_id))
            ->when($judge->category_id !== null, fn ($query) => $query->whereHas('round', fn ($round) => $round->where('category_id', $judge->category_id)))
            ->orderBy('match_number')
            ->get()
            ->map(fn (ActivityCompetitionMatch $match) => [
                'id' => (int) $match->id,
                'match_number' => (int) $match->match_number,
                'round' => $match->round?->name,
                'participant_a' => $match->participantA?->student?->full_name,
                'participant_b' => $match->participantB?->student?->full_name,
                'scheduled_at' => $match->scheduled_at?->toIso8601String(),
                'status' => $match->status?->value,
                'my_score' => ActivityCompetitionScore::query()
                    ->where('match_id', $match->id)
                    ->where('judge_id', $judge->id)
                    ->get(['participant_id', 'total', 'status'])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Kirim skor juri (idempoten). Skor yang sudah dikunci tidak dapat ditimpa.
     *
     * @param  array{participant_id: int, criteria_scores?: array|null, total?: float|null, comment?: string|null, idempotency_key?: string|null}  $payload
     */
    public function submitScore(ActivityCompetitionMatch $match, User $actor, array $payload): ActivityCompetitionScore
    {
        $competition = ActivityCompetition::query()->findOrFail($match->competition_id);
        $judge = $this->access->judgeFor($actor, $competition);

        if ($judge === null || ! $this->access->canScoreMatch($actor, $match)) {
            throw ActivityValidationException::forbidden('Anda bukan juri yang ditugaskan untuk penampilan ini.');
        }

        return DB::transaction(function () use ($match, $judge, $payload, $actor, $competition) {
            $existing = ActivityCompetitionScore::query()
                ->where('match_id', $match->id)
                ->where('judge_id', $judge->id)
                ->where('participant_id', $payload['participant_id'])
                ->first();

            if ($existing !== null && $existing->status === ScoreStatus::LOCKED) {
                throw ActivityConflictException::make(
                    'Skor ini sudah dikunci. Koreksi harus melalui alur koreksi bersebab.',
                    ['score_id' => (int) $existing->id],
                );
            }

            $score = ActivityCompetitionScore::query()->updateOrCreate(
                [
                    'match_id' => $match->id,
                    'judge_id' => $judge->id,
                    'participant_id' => $payload['participant_id'],
                ],
                [
                    'competition_id' => $competition->id,
                    'criteria_scores' => $payload['criteria_scores'] ?? null,
                    'total' => $payload['total'] ?? null,
                    'comment' => $payload['comment'] ?? null,
                    'status' => ScoreStatus::SUBMITTED->value,
                    'submitted_at' => now(),
                    'idempotency_key' => $payload['idempotency_key'] ?? null,
                ],
            );

            $this->audit->record(AuditAction::COMPETITION_SCORE_SUBMITTED, [
                'subject' => $score,
                'program_id' => $competition->program_id === null ? null : (int) $competition->program_id,
                'after' => $score,
                'actor' => $actor->id,
            ]);

            return $score->refresh();
        });
    }

    /** Kunci seluruh skor satu pertandingan (setelah penilaian selesai). */
    public function lockMatchScores(ActivityCompetitionMatch $match, User $actor): int
    {
        $competition = ActivityCompetition::query()->findOrFail($match->competition_id);

        if (! $actor->can('activity.competition.manage')) {
            throw ActivityValidationException::forbidden('Hanya pengelola kompetisi yang dapat mengunci skor.');
        }

        $locked = ActivityCompetitionScore::query()
            ->where('match_id', $match->id)
            ->where('status', ScoreStatus::SUBMITTED->value)
            ->update(['status' => ScoreStatus::LOCKED->value, 'locked_at' => now(), 'updated_at' => now()]);

        $this->recalculateMatchResult($match);

        $this->audit->record(AuditAction::COMPETITION_SCORE_LOCKED, [
            'subject' => $match,
            'program_id' => $competition->program_id === null ? null : (int) $competition->program_id,
            'metadata' => ['locked' => $locked],
            'actor' => $actor->id,
        ]);

        return $locked;
    }

    /**
     * Koreksi skor yang sudah dikunci (§50).
     *
     * Satu juri — satu penampilan — satu baris skor: identitas baris dipertahankan
     * supaya rekap dan hasil tetap dapat dibaca, sedangkan **nilai sebelum**,
     * alasan, dan pelakunya disimpan di jejak audit yang tidak dapat disunting.
     * Dengan begitu koreksi tidak menghapus sejarah, tetapi juga tidak
     * menggandakan baris skor yang membuat perhitungan hasil menjadi ambigu.
     *
     * @param  array{reason: string, total?: float|null, criteria_scores?: array|null, comment?: string|null}  $payload
     */
    public function correctScore(ActivityCompetitionScore $score, User $actor, array $payload): ActivityCompetitionScore
    {
        if (! $actor->can('activity.competition.score.correct')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang mengoreksi skor.');
        }

        if (trim((string) ($payload['reason'] ?? '')) === '') {
            throw ActivityValidationException::withErrors(['Alasan koreksi wajib diisi.']);
        }

        return DB::transaction(function () use ($score, $actor, $payload) {
            $locked = ActivityCompetitionScore::query()->lockForUpdate()->findOrFail($score->id);

            if ($locked->status === ScoreStatus::VOID) {
                throw ActivityConflictException::make('Skor ini sudah dibatalkan dan tidak dapat dikoreksi.');
            }

            $before = [
                'total' => $locked->total === null ? null : (float) $locked->total,
                'criteria_scores' => $locked->criteria_scores,
                'comment' => $locked->comment,
                'status' => $locked->status?->value,
            ];

            $locked->forceFill([
                'criteria_scores' => $payload['criteria_scores'] ?? $locked->criteria_scores,
                'total' => $payload['total'] ?? $locked->total,
                'comment' => $payload['comment'] ?? $locked->comment,
                'status' => ScoreStatus::LOCKED->value,
                'submitted_at' => $locked->submitted_at ?? now(),
                'locked_at' => $locked->locked_at ?? now(),
                'correction_reason' => $payload['reason'],
                'corrected_by' => $actor->id,
                'corrected_at' => now(),
            ])->save();

            $this->audit->record(AuditAction::COMPETITION_SCORE_CORRECTED, [
                'subject' => $locked,
                'before' => $before,
                'after' => [
                    'total' => $locked->total === null ? null : (float) $locked->total,
                    'criteria_scores' => $locked->criteria_scores,
                    'comment' => $locked->comment,
                ],
                'metadata' => ['reason' => $payload['reason']],
                'actor' => $actor->id,
            ]);

            $match = ActivityCompetitionMatch::query()->find($locked->match_id);

            if ($match !== null) {
                $this->recalculateMatchResult($match);
            }

            return $locked->refresh();
        });
    }

    /** Hitung pemenang pertandingan dari rata-rata skor juri yang sah. */
    public function recalculateMatchResult(ActivityCompetitionMatch $match): ActivityCompetitionMatch
    {
        $totals = ActivityCompetitionScore::query()
            ->where('match_id', $match->id)
            ->whereIn('status', [ScoreStatus::SUBMITTED->value, ScoreStatus::LOCKED->value])
            ->get()
            ->groupBy('participant_id')
            ->map(fn ($group) => round((float) $group->avg('total'), 2));

        $scoreA = $match->participant_a_id === null ? null : $totals->get((int) $match->participant_a_id);
        $scoreB = $match->participant_b_id === null ? null : $totals->get((int) $match->participant_b_id);

        $winner = null;

        if ($scoreA !== null && $scoreB !== null) {
            $winner = $scoreA > $scoreB ? $match->participant_a_id : ($scoreB > $scoreA ? $match->participant_b_id : null);
        }

        $match->forceFill([
            'score_a' => $scoreA,
            'score_b' => $scoreB,
            'winner_participant_id' => $winner,
            'status' => $scoreA !== null && $scoreB !== null ? 'COMPLETED' : $match->status?->value,
        ])->save();

        return $match->refresh();
    }

    /** Susun hasil akhir satu kategori dari skor terkumpul. */
    public function buildResults(ActivityCompetition $competition, User $actor, ?int $categoryId = null): array
    {
        if (! $actor->can('activity.competition.manage')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang menyusun hasil kompetisi.');
        }

        $totals = ActivityCompetitionScore::query()
            ->where('competition_id', $competition->id)
            ->whereIn('status', [ScoreStatus::SUBMITTED->value, ScoreStatus::LOCKED->value])
            ->when($categoryId !== null, fn ($query) => $query->whereHas('participant', fn ($participant) => $participant->where('category_id', $categoryId)))
            ->get()
            ->groupBy('participant_id')
            ->map(fn ($group) => round((float) $group->avg('total'), 2))
            ->sortDesc();

        $rank = 0;
        $results = [];

        foreach ($totals as $participantId => $score) {
            $rank++;
            $participant = ActivityCompetitionParticipant::query()->find($participantId);

            $results[] = ActivityCompetitionResult::query()->updateOrCreate(
                [
                    'competition_id' => $competition->id,
                    'category_id' => $participant?->category_id,
                    'participant_id' => $participantId,
                    'team_id' => null,
                ],
                [
                    'rank' => $rank,
                    'score' => $score,
                    'award' => $this->awardFor($rank),
                    'status' => ResultStatus::DRAFT->value,
                ],
            );
        }

        $this->audit->record('competition.results_built', [
            'subject' => $competition,
            'program_id' => $competition->program_id === null ? null : (int) $competition->program_id,
            'after' => ['count' => count($results)],
            'actor' => $actor->id,
        ]);

        return $results;
    }

    public function verifyResult(ActivityCompetitionResult $result, User $actor, ?string $notes = null): ActivityCompetitionResult
    {
        if (! $actor->can('activity.competition.result.publish')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang memverifikasi hasil kompetisi.');
        }

        $result->forceFill([
            'status' => ResultStatus::VERIFIED->value,
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'notes' => $notes,
        ])->save();

        return $result->refresh();
    }

    /**
     * Terbitkan hasil, kunci kompetisi, dan **cetak prestasi** bagi pemenang (§51, §102).
     *
     * @return array{results: int, achievements: list<int>}
     */
    public function publishResults(ActivityCompetition $competition, User $actor): array
    {
        if (! $actor->can('activity.competition.result.publish')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang menerbitkan hasil kompetisi.');
        }

        return DB::transaction(function () use ($competition, $actor) {
            $results = ActivityCompetitionResult::query()
                ->where('competition_id', $competition->id)
                ->whereIn('status', [ResultStatus::DRAFT->value, ResultStatus::VERIFIED->value])
                ->get();

            if ($results->isEmpty()) {
                throw ActivityConflictException::make('Belum ada hasil yang dapat diterbitkan.');
            }

            $achievementIds = [];

            foreach ($results as $result) {
                $result->forceFill([
                    'status' => ResultStatus::PUBLISHED->value,
                    'published_at' => now(),
                ])->save();

                if ($result->award === null) {
                    continue;
                }

                $achievement = $this->achievements->create([
                    'title' => $competition->name.' — '.$result->award->label().' '.($result->participant?->student?->full_name ?? ''),
                    'description' => 'Hasil kompetisi '.$competition->name.' tingkat '.$competition->level->label().'.',
                    'type' => $this->achievementTypeFor($competition),
                    'ownership' => $result->team_id === null ? 'INDIVIDUAL' : 'TEAM',
                    'level' => $competition->level->value,
                    'rank' => $result->rank === 1 ? 'FIRST' : ($result->rank === 2 ? 'SECOND' : ($result->rank === 3 ? 'THIRD' : 'PARTICIPANT')),
                    'awarded_date' => $result->published_at?->toDateString(),
                    'organizer' => $competition->organizer,
                    'location' => $competition->location,
                    'program_id' => $competition->program_id === null ? null : (int) $competition->program_id,
                    'competition_id' => (int) $competition->id,
                    'result_id' => (int) $result->id,
                    'student_id' => $result->participant?->student_id,
                    'team_id' => $result->team_id,
                    'verification_status' => 'PENDING',
                ], $actor);

                $achievementIds[] = (int) $achievement->id;

                // Tim: seluruh anggota yang ditandai menerima prestasi mendapat kredit.
                if ($result->team_id !== null) {
                    $members = ActivityCompetitionMember::query()
                        ->where('team_id', $result->team_id)
                        ->get();

                    $achievement->members()->delete();

                    foreach ($members as $member) {
                        $achievement->members()->create([
                            'student_id' => $member->student_id,
                            'role' => $member->role,
                            'receives_credit' => (bool) $member->receives_achievement,
                            'certificate_eligible' => (bool) $member->receives_achievement,
                        ]);
                    }

                    $achievement->forceFill(['ownership' => 'TEAM', 'student_id' => null])->save();
                }
            }

            $competition->forceFill([
                'status' => CompetitionStatus::PUBLISHED->value,
                'results_published' => true,
                'scores_locked' => true,
            ])->save();

            ActivityCompetitionScore::query()
                ->where('competition_id', $competition->id)
                ->where('status', ScoreStatus::SUBMITTED->value)
                ->update(['status' => ScoreStatus::LOCKED->value, 'locked_at' => now(), 'updated_at' => now()]);

            $this->audit->record(AuditAction::RESULT_PUBLISHED, [
                'subject' => $competition,
                'program_id' => $competition->program_id === null ? null : (int) $competition->program_id,
                'after' => ['results' => $results->count(), 'achievements' => count($achievementIds)],
                'actor' => $actor->id,
            ]);

            $this->notifications->notify([
                'event' => 'COMPETITION_UPCOMING',
                'program_id' => $competition->program_id === null ? null : (int) $competition->program_id,
                'body' => 'Hasil kompetisi '.$competition->name.' telah diterbitkan.',
                'audience' => 'COACHES',
                'marker' => 'results|'.$competition->id,
            ]);

            return ['results' => $results->count(), 'achievements' => $achievementIds];
        });
    }

    protected function registrationOpen(ActivityCompetition $competition): bool
    {
        if ($competition->status !== CompetitionStatus::REGISTRATION_OPEN
            && $competition->status !== CompetitionStatus::ONGOING
            && ! config('activity.competition.allow_registration_outside_window', false)) {
            return false;
        }

        $today = now()->toDateString();

        if ($competition->registration_opens !== null && $competition->registration_opens->toDateString() > $today) {
            return false;
        }

        return ! ($competition->registration_closes !== null && $competition->registration_closes->toDateString() < $today);
    }

    protected function awardFor(int $rank): ?string
    {
        return match ($rank) {
            1 => 'WINNER',
            2 => 'RUNNER_UP',
            3 => 'BRONZE',
            default => null,
        };
    }

    protected function achievementTypeFor(ActivityCompetition $competition): string
    {
        $program = $competition->program;

        return match (true) {
            str_contains(strtolower((string) $program?->name), 'futsal') => 'SPORT',
            str_contains(strtolower((string) $program?->name), 'programming') => 'TECHNOLOGY',
            str_contains(strtolower((string) $program?->name), 'tahfidz') => 'RELIGIOUS',
            str_contains(strtolower((string) $program?->name), 'hadroh') => 'ART',
            default => 'OTHER',
        };
    }

    protected function assertManage(ActivityCompetition $competition, User $actor): void
    {
        if (! $actor->can('activity.competition.manage')) {
            throw ActivityValidationException::forbidden('Anda tidak berwenang mengelola kompetisi ini.');
        }
    }

    protected function nextCode(): string
    {
        $prefix = 'COMP-'.now()->format('Y').'-';

        $last = ActivityCompetition::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('code');

        $sequence = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
