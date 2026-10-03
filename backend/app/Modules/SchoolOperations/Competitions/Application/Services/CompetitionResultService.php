<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\AchievementProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\CertificateProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\ScoringProviderInterface;
use App\Modules\SchoolOperations\Competitions\Models\Competition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionAdvancement;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionAward;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionAwardRecipient;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCategory;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCertificate;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEdition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionLevel;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionMatch;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionParticipant;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionPenalty;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRanking;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionResult;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRound;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionScore;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionScorecard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Ranking and publication are derived only from finalized, immutable scorecards. */
class CompetitionResultService
{
    public function __construct(
        private readonly CompetitionAuditService $audit,
        private readonly CompetitionScopeService $scope,
        private readonly AchievementProviderInterface $achievements,
        private readonly CertificateProviderInterface $certificates,
        private readonly ScoringProviderInterface $scoring,
    ) {}

    public function results(CompetitionEdition $edition, array $filters, User $actor)
    {
        $this->scope->assertEdition($actor, $edition);

        return CompetitionResult::query()->where('edition_id', $edition->id)
            ->when(! empty($filters['category_id']), fn (Builder $query) => $query->where('category_id', $filters['category_id']))
            ->when(! empty($filters['round_id']), fn (Builder $query) => $query->where('round_id', $filters['round_id']))
            ->when(isset($filters['published']), fn (Builder $query) => $query->where('is_published', filter_var($filters['published'], FILTER_VALIDATE_BOOL)))
            ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')->orderBy('position')->orderByDesc('final_score')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 50))));
    }

    public function finalize(CompetitionEdition $edition, int $categoryId, User $actor, ?int $roundId = null): array
    {
        if (! $actor->can('result.finalize')) {
            abort(403);
        }
        $this->scope->assertEdition($actor, $edition);
        $category = CompetitionCategory::query()->where('edition_id', $edition->id)->findOrFail($categoryId);
        $round = $this->resolveFinalRound($edition, $category, $roundId);
        $matches = CompetitionMatch::query()->where('round_id', $round->id);
        if ($matches->exists() && $matches->whereNotIn('status', ['COMPLETED', 'VOID', 'CANCELLED'])->exists()) {
            throw ValidationException::withMessages(['round' => 'Semua pertandingan wajib selesai sebelum result final.']);
        }
        if (CompetitionScorecard::query()->where('edition_id', $edition->id)->where('category_id', $category->id)->where('round_id', $round->id)->whereNotIn('status', ['LOCKED', 'AMENDED', 'VOID'])->exists()) {
            throw ValidationException::withMessages(['scores' => 'Masih ada scorecard draft/submitted yang belum difinalisasi.']);
        }
        if (CompetitionResult::query()->where('edition_id', $edition->id)->where('category_id', $category->id)->where('round_id', $round->id)->where('is_published', true)->exists()) {
            throw ValidationException::withMessages(['results' => 'Hasil yang sudah dipublikasikan terkunci. Ajukan appeal/amendment melalui alur audit.']);
        }

        $finalized = DB::transaction(function () use ($edition, $category, $round, $actor): array {
            $participants = CompetitionParticipant::query()->where('edition_id', $edition->id)->where('category_id', $category->id)->whereNotIn('status', ['WITHDRAWN', 'DISQUALIFIED'])->get();
            $nextRound = CompetitionRound::query()->where('edition_id', $edition->id)->where('category_id', $category->id)->where('sequence', '>', $round->sequence)->orderBy('sequence')->first();
            $cards = CompetitionScorecard::query()->where('edition_id', $edition->id)->where('category_id', $category->id)->where('round_id', $round->id)->whereIn('status', ['LOCKED', 'AMENDED'])->get()->groupBy('participant_id');
            $rows = [];
            foreach ($participants as $participant) {
                $participantCards = $cards->get($participant->id, collect());
                $scores = $participantCards->map(fn (CompetitionScorecard $card) => (float) $card->final_score)->all();
                $aggregate = $this->aggregate($participantCards, (string) $category->aggregation_method);
                $criterionScores = $this->aggregateCriteria($participantCards);
                $penalties = (float) CompetitionPenalty::query()->where('edition_id', $edition->id)->where('category_id', $category->id)
                    ->where('round_id', $round->id)->where('participant_id', $participant->id)->whereNull('scorecard_id')->where('status', 'APPLIED')->sum('penalty_value');
                $rows[] = [
                    'participant' => $participant, 'aggregate' => $aggregate, 'penalty' => $penalties,
                    'score' => $aggregate === null ? null : $aggregate - $penalties,
                    'judge_scores' => $scores, 'criteria' => $criterionScores,
                    'tie_score' => $this->tieScore($criterionScores, (array) ($category->tie_break_strategy ?? [])),
                    'scorecard_count' => $participantCards->count(),
                ];
            }
            $lowerBetter = $category->ranking_model === 'LOWER_BETTER';
            usort($rows, function (array $a, array $b) use ($lowerBetter): int {
                if ($a['score'] === null && $b['score'] !== null) {
                    return 1;
                }
                if ($a['score'] !== null && $b['score'] === null) {
                    return -1;
                }
                if ($a['score'] !== null && $b['score'] !== null) {
                    $comparison = (float) $a['score'] <=> (float) $b['score'];
                    if (abs($comparison) > 0.0001) {
                        return $lowerBetter ? $comparison : -$comparison;
                    }
                }
                if (($a['tie_score'] ?? null) !== ($b['tie_score'] ?? null)) {
                    return ((float) ($b['tie_score'] ?? 0)) <=> ((float) ($a['tie_score'] ?? 0));
                }

                return (int) $a['participant']->id <=> (int) $b['participant']->id;
            });
            $previousScore = null;
            $previousTie = null;
            $lastRank = 0;
            foreach ($rows as $index => &$row) {
                $same = $row['score'] !== null && $previousScore !== null
                    && abs((float) $row['score'] - (float) $previousScore) < 0.0001
                    && (($row['tie_score'] ?? null) === $previousTie);
                if (! $same) {
                    $lastRank = $index + 1;
                }
                $row['position'] = $row['score'] === null ? null : $lastRank;
                $previousScore = $row['score'];
                $previousTie = $row['tie_score'];
                $row['qualification_status'] = $this->qualificationStatus($row, $round, $index + 1);
                $row['status'] = $row['score'] === null ? 'NO_SCORE' : 'FINALIZED';
            }
            unset($row);

            $previousResults = CompetitionResult::query()->where('edition_id', $edition->id)->where('category_id', $category->id)->where('round_id', $round->id)
                ->get(['participant_id', 'team_id', 'final_score', 'position', 'status', 'version'])->toArray();
            $resultVersion = (int) CompetitionResult::withTrashed()->where('edition_id', $edition->id)->where('category_id', $category->id)->where('round_id', $round->id)->max('version') + 1;
            CompetitionResult::query()->where('edition_id', $edition->id)->where('category_id', $category->id)->where('round_id', $round->id)->delete();
            CompetitionRanking::query()->where('edition_id', $edition->id)->where('category_id', $category->id)->where('round_id', $round->id)->delete();

            foreach ($rows as $row) {
                /** @var CompetitionParticipant $participant */
                $participant = $row['participant'];
                CompetitionResult::query()->create([
                    'edition_id' => $edition->id, 'category_id' => $category->id, 'round_id' => $round->id,
                    'participant_id' => $participant->id, 'team_id' => $participant->team_id,
                    'raw_score' => $row['aggregate'], 'penalty_total' => $row['penalty'], 'final_score' => $row['score'],
                    'aggregate_score' => $row['aggregate'], 'rank' => $row['position'], 'position' => $row['position'],
                    'qualification_status' => $row['qualification_status'], 'status' => $row['status'],
                    'is_winner' => $row['position'] === 1, 'tie_break_applied' => (array) ($category->tie_break_strategy ?? []) !== [],
                    'tie_break_detail' => ['strategy' => $category->tie_break_strategy, 'tie_score' => $row['tie_score']],
                    'score_breakdown' => ['judge_scores' => $row['judge_scores'], 'criteria' => $row['criteria'], 'scorecards' => $row['scorecard_count']],
                    'result_summary' => $row['score'] === null ? 'Belum ada skor final.' : null,
                    'finalized_by' => $actor->id, 'finalized_at' => now(), 'version' => $resultVersion,
                ]);
                if ($row['score'] !== null) {
                    CompetitionRanking::query()->create([
                        'edition_id' => $edition->id, 'category_id' => $category->id, 'round_id' => $round->id,
                        'scope' => 'CATEGORY', 'participant_id' => $participant->id, 'team_id' => $participant->team_id,
                        'rank' => $row['position'], 'score' => $row['score'], 'tie_break_score' => $row['tie_score'],
                        'is_final' => true, 'calculation_version' => (string) $resultVersion,
                        'breakdown' => ['aggregate' => $row['aggregate'], 'penalty' => $row['penalty'], 'criteria' => $row['criteria']],
                        'calculated_at' => now(),
                    ]);
                }
                $participant->forceFill(['qualification_status' => $row['qualification_status']])->save();
                if ($nextRound !== null && $row['score'] !== null) {
                    CompetitionAdvancement::query()->updateOrCreate(
                        ['from_round_id' => $round->id, 'participant_id' => $participant->id],
                        [
                            'round_id' => $round->id,
                            'team_id' => $participant->team_id,
                            'to_round_id' => $nextRound->id,
                            'status' => $row['qualification_status'] === 'ADVANCED' ? 'ADVANCED' : 'ELIMINATED',
                            'rank_at_advancement' => $row['position'],
                            'score_at_advancement' => $row['score'],
                            'method' => 'RANKING',
                            'decided_by' => $actor->id,
                            'decided_at' => now(),
                        ],
                    );
                }
            }
            $round->forceFill(['status' => 'COMPLETED', 'ends_on' => $round->ends_on ?? now()])->save();
            $this->audit->record('ResultFinalized', $actor, $round, $edition, before: ['result_version' => $resultVersion - 1, 'previous_results' => $previousResults], after: ['result_version' => $resultVersion, 'category_id' => $category->id, 'round_id' => $round->id, 'results' => count($rows)]);

            return ['edition_id' => $edition->id, 'category_id' => $category->id, 'round_id' => $round->id, 'results' => $rows];
        });
        $this->autoAssignAwards($edition, $category->id, $actor);

        return $finalized;
    }

    public function publish(CompetitionEdition $edition, User $actor, ?int $categoryId = null): array
    {
        if (! $actor->can('result.publish')) {
            abort(403);
        }
        $this->scope->assertEdition($actor, $edition);
        $query = CompetitionResult::query()->where('edition_id', $edition->id)->where('status', 'FINALIZED')->where('is_published', false)
            ->when($categoryId, fn (Builder $builder) => $builder->where('category_id', $categoryId));
        if (! $query->exists()) {
            throw ValidationException::withMessages(['results' => 'Tidak ada hasil terfinalisasi yang siap dipublikasikan.']);
        }

        return DB::transaction(function () use ($query, $actor, $edition): array {
            $results = (clone $query)->lockForUpdate()->get();
            foreach ($results as $result) {
                $result->forceFill(['is_published' => true, 'status' => 'PUBLISHED', 'published_at' => now(), 'published_by' => $actor->id])->save();
            }
            if (! CompetitionResult::query()->where('edition_id', $edition->id)->where('is_published', false)->exists()) {
                CompetitionEdition::query()->whereKey($edition->id)->update(['status' => 'FINAL']);
            }
            $this->audit->record('ResultPublished', $actor, $edition, $edition, after: ['result_count' => $results->count()]);

            return ['published' => $results->count(), 'results' => $results];
        });
    }

    public function createAward(CompetitionEdition $edition, array $data, User $actor): CompetitionAward
    {
        if (! $actor->can('award.manage')) {
            abort(403);
        }
        $this->scope->assertEdition($actor, $edition);
        $category = isset($data['category_id']) ? CompetitionCategory::query()->where('edition_id', $edition->id)->findOrFail($data['category_id']) : null;
        $award = CompetitionAward::query()->create([
            'edition_id' => $edition->id, 'category_id' => $category?->id,
            'code' => strtoupper((string) ($data['code'] ?? 'AWD-'.Str::upper(Str::random(6)))),
            'name' => trim($data['name']), 'award_type' => strtoupper((string) ($data['award_type'] ?? 'PLACEMENT')),
            'award_level' => $data['award_level'] ?? null, 'criteria_type' => strtoupper((string) ($data['criteria_type'] ?? 'RANKING')),
            'criteria_definition' => $data['criteria_definition'] ?? [], 'position_from' => $data['position_from'] ?? null,
            'position_to' => $data['position_to'] ?? null, 'quantity' => $data['quantity'] ?? null,
            'medal_type' => $data['medal_type'] ?? null, 'description' => $data['description'] ?? null,
            'certificate_template_code' => $data['certificate_template_code'] ?? null, 'is_active' => true,
        ]);
        $this->audit->record('AwardConfigured', $actor, $award, $edition, after: $award->only(['id', 'name', 'criteria_type', 'position_from', 'position_to']));
        if ($award->criteria_type === 'RANKING') {
            $this->autoAssignAwards($edition, $award->category_id, $actor, $award);
        }

        return $award;
    }

    public function assignAward(CompetitionAward $award, CompetitionResult $result, User $actor): CompetitionAwardRecipient
    {
        if (! $actor->can('award.manage')) {
            abort(403);
        }
        $this->scope->assertEdition($actor, CompetitionEdition::query()->findOrFail($award->edition_id));
        if ((int) $award->edition_id !== (int) $result->edition_id || ($award->category_id && (int) $award->category_id !== (int) $result->category_id)) {
            throw ValidationException::withMessages(['award' => 'Penghargaan dan hasil harus berada dalam edition/kategori yang sama.']);
        }
        if (! in_array($result->status, ['FINALIZED', 'PUBLISHED'], true)) {
            throw ValidationException::withMessages(['result' => 'Award hanya dapat diberikan dari hasil final.']);
        }
        if ($award->criteria_type === 'RANKING' && ($award->position_from === null || $result->position < $award->position_from || ($award->position_to !== null && $result->position > $award->position_to))) {
            throw ValidationException::withMessages(['result' => 'Hasil ini tidak memenuhi rentang posisi award.']);
        }
        $recipient = CompetitionAwardRecipient::query()->firstOrCreate(
            ['award_id' => $award->id, 'result_id' => $result->id],
            [
                'participant_id' => $result->participant_id, 'team_id' => $result->team_id,
                'student_id' => CompetitionParticipant::query()->find($result->participant_id)?->student_id,
                'is_automatic' => $award->criteria_type === 'RANKING', 'awarded_by' => $actor->id, 'awarded_at' => now(),
            ],
        );
        $this->audit->record('AwardAssigned', $actor, $recipient, CompetitionEdition::query()->find($award->edition_id), after: ['award_id' => $award->id, 'result_id' => $result->id]);
        $this->syncAchievement($recipient, $award, $result, $actor);

        return $recipient;
    }

    public function generateCertificate(CompetitionAwardRecipient $recipient, User $actor): CompetitionCertificate
    {
        if (! $actor->can('certificate.generate')) {
            abort(403);
        }
        $award = CompetitionAward::query()->findOrFail($recipient->award_id);
        $this->scope->assertEdition($actor, CompetitionEdition::query()->findOrFail($award->edition_id));
        $result = $recipient->result_id ? CompetitionResult::query()->findOrFail($recipient->result_id) : null;
        if ($result === null || ! in_array($result->status, ['FINALIZED', 'PUBLISHED'], true)) {
            throw ValidationException::withMessages(['result' => 'Sertifikat hanya dapat dibuat dari hasil final.']);
        }
        $existing = CompetitionCertificate::query()->where('award_id', $award->id)->where('participant_id', $recipient->participant_id)->whereNotIn('status', ['REVOKED'])->first();
        if ($existing !== null) {
            return $existing;
        }
        $participant = CompetitionParticipant::query()->findOrFail($recipient->participant_id);
        $payload = [
            'external_reference' => 'competition-certificate-recipient:'.$recipient->id,
            'actor_id' => (int) $actor->id,
            'student_id' => $participant->student_id,
            'participant_type' => $participant->participant_type,
            'competition_edition_id' => $award->edition_id,
            'award_id' => $award->id,
            'result_id' => $result->id,
            'recipient_name' => $participant->display_name ?: 'Participant '.$participant->id,
            'award_name' => $award->name,
            'position' => $result->position,
            'template_code' => $award->certificate_template_code,
        ];
        $rendered = $this->certificates->generate($payload);
        $providerStatus = strtoupper((string) ($rendered['status'] ?? 'PENDING_PROVIDER'));
        if ($this->certificates->isAvailable() && in_array($providerStatus, ['ISSUED', 'GENERATED', 'READY'], true)
            && empty($rendered['certificate_number'])) {
            throw ValidationException::withMessages(['certificate' => 'Certificate provider belum mengembalikan nomor sertifikat.']);
        }
        $issued = $this->certificates->isAvailable() && in_array($providerStatus, ['ISSUED', 'GENERATED', 'READY'], true);
        $certificate = CompetitionCertificate::query()->create([
            'edition_id' => $award->edition_id, 'category_id' => $award->category_id, 'award_id' => $award->id,
            'result_id' => $result->id, 'recipient_type' => $participant->participant_type, 'participant_id' => $participant->id,
            'team_id' => $participant->team_id, 'student_id' => $participant->student_id, 'employee_id' => $participant->employee_id,
            'certificate_number' => $rendered['certificate_number'] ?? null, 'certificate_type' => $award->award_type,
            'holder_name' => $payload['recipient_name'], 'issued_on' => $issued ? ($rendered['issued_on'] ?? now()->toDateString()) : null,
            'verification_code' => $rendered['verification_code'] ?? null, 'qr_payload' => $rendered['qr_payload'] ?? null,
            'template_code' => $award->certificate_template_code, 'file_path' => $rendered['file_path'] ?? null,
            'status' => $issued ? 'ISSUED' : 'PENDING_PROVIDER', 'issued_by' => $issued ? $actor->id : null,
            'issued_at' => $issued ? now() : null, 'external_provider_reference' => $rendered['reference'] ?? null,
            'metadata' => ['award' => $award->name, 'position' => $result->position, 'provider_available' => $this->certificates->isAvailable(), 'provider_status' => $providerStatus],
        ]);
        $recipient->forceFill(['certificate_id' => $certificate->id])->save();
        $this->audit->record('CertificateGenerated', $actor, $certificate, CompetitionEdition::query()->find($award->edition_id), after: ['certificate_number' => $certificate->certificate_number, 'provider_status' => $certificate->status]);

        return $certificate->refresh();
    }

    private function resolveFinalRound(CompetitionEdition $edition, CompetitionCategory $category, ?int $roundId): CompetitionRound
    {
        if ($roundId !== null) {
            return CompetitionRound::query()->where('edition_id', $edition->id)->where('category_id', $category->id)->findOrFail($roundId);
        }
        $round = CompetitionRound::query()->where('edition_id', $edition->id)->where('category_id', $category->id)->orderByDesc('is_final')->orderByDesc('sequence')->first();
        if ($round === null) {
            throw ValidationException::withMessages(['round_id' => 'Kategori belum memiliki babak.']);
        }

        return $round;
    }

    private function aggregate($cards, string $method): ?float
    {
        $scores = $cards->map(fn (CompetitionScorecard $card) => (float) $card->final_score)->values()->all();
        $headJudge = $cards->first(fn (CompetitionScorecard $card) => (bool) $card->is_head_judge_override);

        return $this->scoring->aggregate($scores, $method, $headJudge ? (float) $headJudge->final_score : null);
    }

    private function aggregateCriteria($cards): array
    {
        $result = [];
        foreach ($cards as $card) {
            foreach (CompetitionScore::query()->where('scorecard_id', $card->id)->get() as $score) {
                $result[$score->criterion_id][] = (float) $score->raw_score;
            }
        }
        foreach ($result as $criterionId => $scores) {
            sort($scores);
            $result[$criterionId] = $this->median($scores);
        }

        return $result;
    }

    private function tieScore(array $criterionScores, array $strategy): ?float
    {
        $items = (array) ($strategy['criteria'] ?? $strategy['criterion_ids'] ?? []);
        foreach ($items as $criterionId) {
            $key = is_numeric($criterionId) ? (int) $criterionId : (int) preg_replace('/\D+/', '', (string) $criterionId);
            if (isset($criterionScores[$key])) {
                return (float) $criterionScores[$key];
            }
        }

        return null;
    }

    private function qualificationStatus(array $row, CompetitionRound $round, int $ordinal): string
    {
        if ($row['score'] === null) {
            return 'NO_SCORE';
        }
        if ($round->qualification_threshold !== null) {
            return (float) $row['score'] >= (float) $round->qualification_threshold ? 'ADVANCED' : 'ELIMINATED';
        }
        if ($round->qualification_count !== null) {
            return $ordinal <= (int) $round->qualification_count ? 'ADVANCED' : 'ELIMINATED';
        }

        return $round->is_final ? 'FINALIST' : 'RANKED';
    }

    private function median(array $scores): float
    {
        sort($scores);
        $count = count($scores);
        if ($count === 0) {
            return 0.0;
        }
        $middle = intdiv($count, 2);

        return $count % 2 ? (float) $scores[$middle] : ((float) $scores[$middle - 1] + (float) $scores[$middle]) / 2;
    }

    private function autoAssignAwards(CompetitionEdition $edition, ?int $categoryId, User $actor, ?CompetitionAward $onlyAward = null): void
    {
        $awards = $onlyAward !== null
            ? collect([$onlyAward])
            : CompetitionAward::query()->where('edition_id', $edition->id)->where('criteria_type', 'RANKING')->where('is_active', true)
                ->when($categoryId, fn (Builder $query) => $query->where('category_id', $categoryId))->get();
        foreach ($awards as $award) {
            if ($award->criteria_type !== 'RANKING' || $award->position_from === null) {
                continue;
            }
            $query = CompetitionResult::query()->where('edition_id', $edition->id)->whereIn('status', ['FINALIZED', 'PUBLISHED'])
                ->whereNotNull('position')->where('position', '>=', $award->position_from)
                ->when($award->position_to !== null, fn (Builder $builder) => $builder->where('position', '<=', $award->position_to))
                ->when($award->category_id !== null, fn (Builder $builder) => $builder->where('category_id', $award->category_id))
                ->orderBy('position');
            foreach ($query->get() as $result) {
                if ($award->quantity !== null && CompetitionAwardRecipient::query()->where('award_id', $award->id)->count() >= (int) $award->quantity) {
                    break;
                }
                $recipient = CompetitionAwardRecipient::query()->firstOrCreate(
                    ['award_id' => $award->id, 'result_id' => $result->id],
                    [
                        'participant_id' => $result->participant_id,
                        'team_id' => $result->team_id,
                        'student_id' => CompetitionParticipant::query()->find($result->participant_id)?->student_id,
                        'is_automatic' => true,
                        'awarded_by' => $actor->id,
                        'awarded_at' => now(),
                    ],
                );
                if ($recipient->wasRecentlyCreated) {
                    $this->audit->record('AwardAssigned', $actor, $recipient, $edition, after: ['award_id' => $award->id, 'result_id' => $result->id, 'automatic' => true]);
                }
                $this->syncAchievement($recipient, $award, $result, $actor);
            }
        }
    }

    private function syncAchievement(CompetitionAwardRecipient $recipient, CompetitionAward $award, CompetitionResult $result, User $actor): void
    {
        if ($recipient->student_id === null) {
            return;
        }
        $edition = CompetitionEdition::query()->findOrFail($award->edition_id);
        $competition = Competition::query()->find($edition->competition_id);
        $level = $edition->level_id ? CompetitionLevel::query()->find($edition->level_id)?->code : null;
        $sync = $this->achievements->record([
            'external_reference' => 'competition-award:'.$recipient->id,
            'actor_id' => (int) $actor->id,
            'student_id' => (int) $recipient->student_id,
            'competition_edition_id' => (int) $award->edition_id,
            'competition_name' => $competition?->name,
            'domain' => $competition?->competitionDomain?->code ?? 'OTHER',
            'level' => $level ?? 'SCHOOL',
            'category_id' => $award->category_id,
            'result_id' => (int) $result->id,
            'title' => trim(($competition?->name ? $competition->name.' — ' : '').$award->name),
            'description' => $award->description,
            'organizer' => $edition->organizer_name,
            'position' => $result->position,
            'score' => $result->final_score,
            'awarded_at' => now()->toDateString(),
        ]);
        if (($sync['created'] ?? false) && isset($sync['reference'])) {
            $recipient->forceFill(['achievement_reference' => $sync['reference'], 'achievement_synced_at' => now()])->save();
            $result->forceFill(['achievement_reference' => $sync['reference'], 'achievement_synced_at' => now()])->save();
        }
    }
}
