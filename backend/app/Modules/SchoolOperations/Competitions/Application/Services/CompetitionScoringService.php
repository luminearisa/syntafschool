<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\ScoringProviderInterface;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEdition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionJudge;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionJudgeAssignment;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionMatch;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionParticipant;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionPenalty;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRound;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRubric;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRubricCriterion;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionScore;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionScoreAmendment;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionScorecard;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionScoreVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Immutable score lifecycle: draft -> submitted -> locked -> versioned amendment. */
class CompetitionScoringService
{
    public function __construct(
        private readonly CompetitionAuditService $audit,
        private readonly CompetitionScopeService $scope,
        private readonly ScoringProviderInterface $scoring,
    ) {}

    public function scorecards(CompetitionEdition $edition, array $filters, User $actor)
    {
        $this->scope->assertEdition($actor, $edition);

        return CompetitionScorecard::query()->where('edition_id', $edition->id)
            ->when(! empty($filters['category_id']), fn (Builder $q) => $q->where('category_id', $filters['category_id']))
            ->when(! empty($filters['round_id']), fn (Builder $q) => $q->where('round_id', $filters['round_id']))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', strtoupper((string) $filters['status'])))
            ->orderByDesc('updated_at')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 50))));
    }

    /** Save/edit a draft scorecard. `submit=true` validates all mandatory criteria. */
    public function saveScorecard(CompetitionMatch $match, User $actor, array $data, bool $submit = false): CompetitionScorecard
    {
        $round = CompetitionRound::query()->findOrFail($match->round_id);
        $edition = CompetitionEdition::query()->findOrFail($round->edition_id);
        $this->scope->assertEdition($actor, $edition);
        if (! in_array($match->status, ['READY', 'RUNNING', 'PAUSED'], true)) {
            throw ValidationException::withMessages(['match' => 'Skor hanya dapat dimasukkan saat pertandingan READY/RUNNING/PAUSED.']);
        }
        $participantId = (int) ($data['participant_id'] ?? 0);
        $participant = CompetitionParticipant::query()->where('edition_id', $edition->id)->findOrFail($participantId);
        if (! in_array($participantId, array_map('intval', array_filter([$match->home_participant_id, $match->away_participant_id])), true)) {
            throw ValidationException::withMessages(['participant_id' => 'Peserta bukan bagian dari pertandingan ini.']);
        }
        $judge = $this->authorizedJudge($actor, $edition, $match, $data);
        $rubric = $this->rubricFor((int) $match->category_id, (int) $round->id, $data['rubric_id'] ?? null);
        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey !== null) {
            $byKey = CompetitionScorecard::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($byKey && ! in_array($byKey->status, ['DRAFT'], true)) {
                return $byKey;
            }
        }

        return DB::transaction(function () use ($match, $actor, $data, $submit, $round, $edition, $participant, $judge, $rubric, $idempotencyKey): CompetitionScorecard {
            $scorecard = null;
            if ($idempotencyKey !== null) {
                $byKey = CompetitionScorecard::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($byKey !== null) {
                    if ((int) $byKey->match_id !== (int) $match->id || (int) $byKey->participant_id !== (int) $participant->id || (int) $byKey->rubric_id !== (int) $rubric->id || (int) ($byKey->judge_id ?? 0) !== (int) ($judge?->id ?? 0)) {
                        throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key sudah digunakan untuk scorecard lain.']);
                    }
                    if (! in_array($byKey->status, ['DRAFT'], true)) {
                        return $byKey;
                    }
                    $scorecard = $byKey;
                }
            }
            $scorecard ??= CompetitionScorecard::query()->where('rubric_id', $rubric->id)
                ->where('match_id', $match->id)->where('participant_id', $participant->id)
                ->where('judge_id', $judge?->id)->lockForUpdate()->first();
            if ($scorecard === null) {
                $scorecard = CompetitionScorecard::query()->create([
                    'rubric_id' => $rubric->id,
                    'judge_id' => $judge?->id,
                    'edition_id' => $edition->id,
                    'category_id' => $match->category_id,
                    'round_id' => $round->id,
                    'session_id' => $match->session_id,
                    'match_id' => $match->id,
                    'participant_id' => $participant->id,
                    'team_id' => $participant->team_id,
                    'status' => 'DRAFT',
                    'current_version' => 1,
                    'penalty_total' => 0,
                    'idempotency_key' => $idempotencyKey,
                    'device_id' => $data['device_id'] ?? null,
                    'offline_created_at' => $data['offline_created_at'] ?? null,
                ]);
            }
            if (in_array($scorecard->status, ['LOCKED', 'AMENDED', 'VOID'], true)) {
                throw ValidationException::withMessages(['scorecard' => 'Scorecard sudah terkunci. Perubahan memerlukan amendment berizin.']);
            }
            $criteria = CompetitionRubricCriterion::query()->where('rubric_id', $rubric->id)->orderBy('sort_order')->get()->keyBy('id');
            $incoming = collect((array) ($data['scores'] ?? []))->keyBy(fn (array $row) => (int) $row['criterion_id']);
            foreach ($incoming as $criterionId => $row) {
                $criterion = $criteria->get((int) $criterionId);
                if ($criterion === null) {
                    throw ValidationException::withMessages(['scores' => 'Kriteria skor tidak termasuk rubric yang ditugaskan.']);
                }
                $this->validateCriterionScore($criterion, $row);
            }
            if ($submit) {
                foreach ($criteria as $criterion) {
                    if ($criterion->is_required && ! $incoming->has((int) $criterion->id)) {
                        throw ValidationException::withMessages(["scores.{$criterion->id}" => 'Kriteria '.$criterion->name.' wajib diberi skor.']);
                    }
                }
            }

            foreach ($incoming as $criterionId => $row) {
                $criterion = $criteria->get((int) $criterionId);
                $raw = (float) $row['score'];
                $weighted = $this->scoring->weightedCriterion($raw, (float) $criterion->weight, (float) $criterion->min_score, (float) $criterion->max_score);
                CompetitionScore::query()->updateOrCreate(
                    ['scorecard_id' => $scorecard->id, 'criterion_id' => $criterion->id],
                    [
                        'raw_score' => $raw,
                        'weighted_score' => $weighted,
                        'comment' => $row['comment'] ?? null,
                        'entered_at' => now(),
                        'entered_by' => $actor->id,
                        'idempotency_key' => $row['idempotency_key'] ?? null,
                    ],
                );
            }
            $scoreRows = CompetitionScore::query()->where('scorecard_id', $scorecard->id)->get();
            $weightedTotal = (float) $scoreRows->sum(fn (CompetitionScore $score) => (float) $score->weighted_score);
            $rawTotal = (float) $scoreRows->sum(fn (CompetitionScore $score) => (float) $score->raw_score);
            $penalty = (float) CompetitionPenalty::query()->where('scorecard_id', $scorecard->id)->where('status', 'APPLIED')->sum('penalty_value');
            $scorecard->forceFill([
                'total_raw_score' => $rawTotal,
                'weighted_score' => $weightedTotal,
                'penalty_total' => $penalty,
                'final_score' => $weightedTotal - $penalty,
                'normalized_score' => $weightedTotal,
                'comment' => $data['comment'] ?? $scorecard->comment,
                'status' => $submit ? 'SUBMITTED' : 'DRAFT',
                'submitted_at' => $submit ? now() : null,
            ])->save();
            if ($submit) {
                $this->audit->record('ScoreSubmitted', $actor, $scorecard, $edition, after: ['scorecard_id' => $scorecard->id, 'participant_id' => $participant->id, 'final_score' => $scorecard->final_score]);
            }

            return $scorecard->refresh();
        });
    }

    public function submitScorecard(CompetitionScorecard $scorecard, User $actor): CompetitionScorecard
    {
        $this->assertAuthorizedJudgeOrManager($scorecard, $actor, 'score.submit');
        if ($scorecard->edition_id !== null) {
            $this->scope->assertEdition($actor, CompetitionEdition::query()->findOrFail($scorecard->edition_id));
        }
        if ($scorecard->status === 'SUBMITTED') {
            return $scorecard;
        }
        if ($scorecard->status !== 'DRAFT') {
            throw ValidationException::withMessages(['scorecard' => 'Hanya scorecard draft yang dapat dikirim.']);
        }
        $rubric = CompetitionRubric::query()->findOrFail($scorecard->rubric_id);
        $criteria = CompetitionRubricCriterion::query()->where('rubric_id', $rubric->id)->where('is_required', true)->pluck('id');
        $scored = CompetitionScore::query()->where('scorecard_id', $scorecard->id)->pluck('criterion_id');
        if ($criteria->diff($scored)->isNotEmpty()) {
            throw ValidationException::withMessages(['scores' => 'Scorecard belum memenuhi seluruh kriteria wajib.']);
        }
        $scorecard->forceFill(['status' => 'SUBMITTED', 'submitted_at' => now()])->save();
        $this->audit->record('ScoreSubmitted', $actor, $scorecard, after: ['final_score' => $scorecard->final_score]);

        return $scorecard->refresh();
    }

    public function finalizeScorecard(CompetitionScorecard $scorecard, User $actor, ?string $reviewNote = null): CompetitionScorecard
    {
        if (! $actor->can('score.finalize') && ! $actor->can('score.review')) {
            abort(403);
        }

        return DB::transaction(function () use ($scorecard, $actor, $reviewNote): CompetitionScorecard {
            $locked = CompetitionScorecard::query()->whereKey($scorecard->id)->lockForUpdate()->firstOrFail();
            if ($locked->edition_id !== null) {
                $this->scope->assertEdition($actor, CompetitionEdition::query()->findOrFail($locked->edition_id));
            }
            if ($locked->status === 'LOCKED' || $locked->status === 'AMENDED') {
                return $locked;
            }
            if ($locked->status !== 'SUBMITTED') {
                throw ValidationException::withMessages(['scorecard' => 'Scorecard harus disubmit sebelum difinalisasi.']);
            }
            $snapshot = $this->scoreSnapshot($locked);
            CompetitionScoreVersion::query()->create([
                'scorecard_id' => $locked->id,
                'version' => (int) $locked->current_version,
                'previous_total' => null,
                'new_total' => $locked->final_score,
                'previous_payload' => null,
                'new_payload' => $snapshot,
                'reason' => 'Initial score finalization',
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'approver_id' => $actor->id,
                'approved_at' => now(),
                'created_at' => now(),
            ]);
            $locked->forceFill([
                'status' => 'LOCKED',
                'locked_at' => now(),
                'locked_by' => $actor->id,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $reviewNote,
            ])->save();
            $edition = CompetitionEdition::query()->find($locked->edition_id);
            $this->audit->record('ScoreFinalized', $actor, $locked, $edition, after: ['final_score' => $locked->final_score, 'version' => $locked->current_version]);

            return $locked->refresh();
        });
    }

    public function createAmendment(CompetitionScorecard $scorecard, User $actor, array $data): CompetitionScoreAmendment
    {
        if (! in_array($scorecard->status, ['LOCKED', 'AMENDED'], true)) {
            throw ValidationException::withMessages(['scorecard' => 'Amendment hanya dibuat untuk scorecard terkunci.']);
        }
        $this->assertAuthorizedJudgeOrManager($scorecard, $actor, 'score.amend');
        if ($scorecard->edition_id !== null) {
            $this->scope->assertEdition($actor, CompetitionEdition::query()->findOrFail($scorecard->edition_id));
        }
        $payload = $data['scores'] ?? null;
        if (! is_array($payload) || $payload === []) {
            throw ValidationException::withMessages(['scores' => 'Amendment harus berisi skor usulan.']);
        }
        $this->validatePayloadForRubric((int) $scorecard->rubric_id, $payload, true);
        $amendment = CompetitionScoreAmendment::query()->create([
            'scorecard_id' => $scorecard->id,
            'requested_by' => $actor->id,
            'requested_at' => now(),
            'reason' => trim($data['reason']),
            'proposed_payload' => ['scores' => $payload, 'comment' => $data['comment'] ?? null],
            'status' => 'PENDING',
        ]);
        $this->audit->record('ScoreAmendmentRequested', $actor, $amendment, after: ['scorecard_id' => $scorecard->id, 'reason' => $amendment->reason]);

        return $amendment;
    }

    public function decideAmendment(CompetitionScoreAmendment $amendment, User $actor, string $decision, ?string $reason = null): CompetitionScorecard
    {
        if (! $actor->can('score.amend')) {
            abort(403);
        }
        $decision = strtoupper($decision);
        if (! in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan amendment harus APPROVE atau REJECT.']);
        }

        return DB::transaction(function () use ($amendment, $actor, $decision, $reason): CompetitionScorecard {
            $amendment = CompetitionScoreAmendment::query()->whereKey($amendment->id)->lockForUpdate()->firstOrFail();
            if ($amendment->status !== 'PENDING') {
                throw ValidationException::withMessages(['amendment' => 'Amendment sudah diputuskan.']);
            }
            $scorecard = CompetitionScorecard::query()->whereKey($amendment->scorecard_id)->lockForUpdate()->firstOrFail();
            if ($scorecard->edition_id !== null) {
                $this->scope->assertEdition($actor, CompetitionEdition::query()->findOrFail($scorecard->edition_id));
            }
            if ($decision === 'REJECT') {
                if (trim((string) $reason) === '') {
                    throw ValidationException::withMessages(['reason' => 'Alasan penolakan amendment wajib diisi.']);
                }
                $amendment->forceFill(['status' => 'REJECTED', 'authorized_by' => $actor->id, 'authorized_at' => now(), 'rejected_reason' => $reason])->save();
                $this->audit->record('ScoreAmendmentRejected', $actor, $amendment, after: ['status' => 'REJECTED'], reason: $reason);

                return $scorecard;
            }

            $previous = $this->scoreSnapshot($scorecard);
            $payload = (array) $amendment->proposed_payload;
            $this->validatePayloadForRubric((int) $scorecard->rubric_id, (array) ($payload['scores'] ?? []), true);
            $criteria = CompetitionRubricCriterion::query()->where('rubric_id', $scorecard->rubric_id)->get()->keyBy('id');
            CompetitionScore::query()->where('scorecard_id', $scorecard->id)->delete();
            foreach ((array) $payload['scores'] as $row) {
                $criterion = $criteria->get((int) $row['criterion_id']);
                $raw = (float) $row['score'];
                $weighted = $this->scoring->weightedCriterion($raw, (float) $criterion->weight, (float) $criterion->min_score, (float) $criterion->max_score);
                CompetitionScore::query()->create([
                    'scorecard_id' => $scorecard->id,
                    'criterion_id' => $criterion->id,
                    'raw_score' => $raw,
                    'weighted_score' => $weighted,
                    'comment' => $row['comment'] ?? null,
                    'entered_at' => now(),
                    'entered_by' => $actor->id,
                ]);
            }
            $scores = CompetitionScore::query()->where('scorecard_id', $scorecard->id)->get();
            $weighted = (float) $scores->sum(fn (CompetitionScore $score) => (float) $score->weighted_score);
            $raw = (float) $scores->sum(fn (CompetitionScore $score) => (float) $score->raw_score);
            $penalty = (float) CompetitionPenalty::query()->where('scorecard_id', $scorecard->id)->where('status', 'APPLIED')->sum('penalty_value');
            $nextVersion = (int) $scorecard->current_version + 1;
            $scorecard->forceFill([
                'current_version' => $nextVersion,
                'total_raw_score' => $raw,
                'weighted_score' => $weighted,
                'penalty_total' => $penalty,
                'final_score' => $weighted - $penalty,
                'normalized_score' => $weighted,
                'comment' => $payload['comment'] ?? $scorecard->comment,
                'status' => 'AMENDED',
                'locked_at' => now(),
                'locked_by' => $actor->id,
            ])->save();
            $new = $this->scoreSnapshot($scorecard->refresh());
            CompetitionScoreVersion::query()->create([
                'scorecard_id' => $scorecard->id,
                'version' => $nextVersion,
                'previous_total' => $previous['final_score'] ?? null,
                'new_total' => $scorecard->final_score,
                'previous_payload' => $previous,
                'new_payload' => $new,
                'reason' => $amendment->reason,
                'amendment_request_id' => $amendment->id,
                'actor_id' => $amendment->requested_by,
                'actor_name' => User::query()->whereKey($amendment->requested_by)->value('name'),
                'approver_id' => $actor->id,
                'approved_at' => now(),
                'created_at' => now(),
            ]);
            $amendment->forceFill(['status' => 'APPROVED', 'authorized_by' => $actor->id, 'authorized_at' => now(), 'applied_at' => now()])->save();
            $edition = CompetitionEdition::query()->find($scorecard->edition_id);
            $this->audit->record('ScoreAmended', $actor, $scorecard, $edition, before: ['version' => $nextVersion - 1, 'score' => $previous['final_score'] ?? null], after: ['version' => $nextVersion, 'score' => $scorecard->final_score], reason: $amendment->reason);

            return $scorecard->refresh();
        });
    }

    public function applyPenalty(array $data, User $actor): CompetitionPenalty
    {
        if (! $actor->can('score.review') && ! $actor->can('score.finalize')) {
            abort(403);
        }
        $edition = CompetitionEdition::query()->findOrFail($data['edition_id']);
        $this->scope->assertEdition($actor, $edition);
        if (! empty($data['scorecard_id'])) {
            $scorecard = CompetitionScorecard::query()->findOrFail($data['scorecard_id']);
            if (in_array($scorecard->status, ['LOCKED', 'AMENDED'], true)) {
                throw ValidationException::withMessages(['scorecard_id' => 'Penalty tidak dapat ditambahkan langsung ke scorecard terkunci. Buat amendment/penalti berizin.']);
            }
        }
        $penalty = CompetitionPenalty::query()->create([
            'edition_id' => $edition->id,
            'category_id' => $data['category_id'] ?? null,
            'participant_id' => $data['participant_id'] ?? null,
            'team_id' => $data['team_id'] ?? null,
            'round_id' => $data['round_id'] ?? null,
            'session_id' => $data['session_id'] ?? null,
            'match_id' => $data['match_id'] ?? null,
            'scorecard_id' => $data['scorecard_id'] ?? null,
            'penalty_type' => strtoupper((string) ($data['penalty_type'] ?? 'RULE_VIOLATION')),
            'rule_code' => $data['rule_code'] ?? null,
            'value_type' => strtoupper((string) ($data['value_type'] ?? 'POINTS')),
            'penalty_value' => $data['penalty_value'],
            'reason' => trim($data['reason']),
            'evidence' => $data['evidence'] ?? null,
            'status' => 'APPLIED',
            'applied_by' => $actor->id,
            'applied_at' => now(),
            'authorized_by' => $actor->id,
        ]);
        $this->audit->record('PenaltyApplied', $actor, $penalty, $edition, after: ['penalty_value' => $penalty->penalty_value, 'participant_id' => $penalty->participant_id], reason: $penalty->reason);

        return $penalty;
    }

    private function rubricFor(int $categoryId, int $roundId, mixed $requestedId): CompetitionRubric
    {
        if ($requestedId !== null) {
            return CompetitionRubric::query()->where('is_active', true)->where('status', 'PUBLISHED')->findOrFail($requestedId);
        }
        $rubric = CompetitionRubric::query()->where('is_active', true)->where('status', 'PUBLISHED')
            ->where(function (Builder $query) use ($categoryId): void {
                $query->where('category_id', $categoryId)->orWhereNull('category_id');
            })
            ->where(function (Builder $query) use ($roundId): void {
                $query->where('round_id', $roundId)->orWhereNull('round_id');
            })
            ->orderByRaw('CASE WHEN category_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN round_id IS NULL THEN 1 ELSE 0 END')
            ->first();
        if ($rubric === null) {
            throw ValidationException::withMessages(['rubric_id' => 'Belum ada rubric aktif untuk kategori/babak ini.']);
        }

        return $rubric;
    }

    private function authorizedJudge(User $actor, CompetitionEdition $edition, CompetitionMatch $match, array $data): ?CompetitionJudge
    {
        $judge = CompetitionJudge::query()->where('user_id', $actor->id)->where('is_active', true)->where('status', 'ACTIVE')->first();
        if ($judge === null) {
            if ($actor->can('score.review')) {
                return null;
            }
            abort(403, 'Akun juri belum tertaut pada profil juri ERP.');
        }
        $assignmentQuery = CompetitionJudgeAssignment::query()->where('edition_id', $edition->id)->where('judge_id', $judge->id)
            ->whereNotIn('status', ['DECLINED', 'CANCELLED']);
        $assignmentQuery->where(function (Builder $query) use ($match): void {
            $query->where('match_id', $match->id)
                ->orWhere(fn (Builder $q) => $q->whereNull('match_id')->where('round_id', $match->round_id));
            if ($match->session_id !== null) {
                $query->orWhere(fn (Builder $q) => $q->whereNull('match_id')->whereNull('round_id')->where('session_id', $match->session_id));
            }
            $query->orWhere(fn (Builder $q) => $q->whereNull('match_id')->whereNull('round_id')->whereNull('session_id')->where('category_id', $match->category_id));
        });
        if (! $assignmentQuery->exists()) {
            abort(403, 'Juri hanya dapat menilai penugasan yang ditetapkan kepadanya.');
        }

        return $judge;
    }

    private function validateCriterionScore(CompetitionRubricCriterion $criterion, array $row): void
    {
        if (! isset($row['score']) || ! is_numeric($row['score'])) {
            throw ValidationException::withMessages(['scores.'.$criterion->id => 'Skor angka wajib diisi.']);
        }
        $score = (float) $row['score'];
        if ($score < (float) $criterion->min_score || $score > (float) $criterion->max_score) {
            throw ValidationException::withMessages(['scores.'.$criterion->id => "Skor harus berada pada rentang {$criterion->min_score}–{$criterion->max_score}."]);
        }
        if ($criterion->step && fmod(abs($score - (float) $criterion->min_score), (float) $criterion->step) > 0.0001) {
            throw ValidationException::withMessages(['scores.'.$criterion->id => 'Skor tidak sesuai interval rubric.']);
        }
        if ($criterion->required_comment && trim((string) ($row['comment'] ?? '')) === '') {
            throw ValidationException::withMessages(['scores.'.$criterion->id.'.comment' => 'Komentar wajib untuk kriteria ini.']);
        }
    }

    private function validatePayloadForRubric(int $rubricId, array $payload, bool $requireAll): void
    {
        $criteria = CompetitionRubricCriterion::query()->where('rubric_id', $rubricId)->get()->keyBy('id');
        $incoming = collect($payload)->keyBy(fn (array $row) => (int) $row['criterion_id']);
        foreach ($incoming as $id => $row) {
            $criterion = $criteria->get((int) $id);
            if ($criterion === null) {
                throw ValidationException::withMessages(['scores' => 'Amendment berisi criterion di luar rubric.']);
            }
            $this->validateCriterionScore($criterion, $row);
        }
        if ($requireAll) {
            foreach ($criteria as $criterion) {
                if ($criterion->is_required && ! $incoming->has((int) $criterion->id)) {
                    throw ValidationException::withMessages(['scores.'.$criterion->id => 'Kriteria wajib belum disertakan.']);
                }
            }
        }
    }

    private function assertAuthorizedJudgeOrManager(CompetitionScorecard $scorecard, User $actor, string $permission): void
    {
        if ($actor->can('score.review') || $actor->can('score.finalize')) {
            return;
        }
        $judge = CompetitionJudge::query()->where('user_id', $actor->id)->where('is_active', true)->first();
        if (! $actor->can($permission) || $judge === null || (int) $scorecard->judge_id !== (int) $judge->id) {
            abort(403, 'Hanya juri pemilik scorecard atau reviewer berwenang yang dapat mengubah status skor.');
        }
    }

    private function scoreSnapshot(CompetitionScorecard $scorecard): array
    {
        return [
            'scorecard_id' => $scorecard->id,
            'participant_id' => $scorecard->participant_id,
            'judge_id' => $scorecard->judge_id,
            'rubric_id' => $scorecard->rubric_id,
            'final_score' => (float) $scorecard->final_score,
            'total_raw_score' => (float) $scorecard->total_raw_score,
            'penalty_total' => (float) $scorecard->penalty_total,
            'comment' => $scorecard->comment,
            'criteria' => CompetitionScore::query()->where('scorecard_id', $scorecard->id)->orderBy('criterion_id')->get()->map(fn (CompetitionScore $row) => [
                'criterion_id' => $row->criterion_id,
                'raw_score' => (float) $row->raw_score,
                'weighted_score' => (float) $row->weighted_score,
                'comment' => $row->comment,
            ])->all(),
        ];
    }
}
