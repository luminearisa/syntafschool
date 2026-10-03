<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Application\Services;

use App\Models\Student;
use App\Models\User;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\ParentProviderInterface;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionAppeal;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionAppealCommittee;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionAppealCommitteeMember;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEdition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionIncident;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionMatch;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionParticipant;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionProtest;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Protest, appeal committee and incident workflows with append-only decisions. */
class CompetitionGovernanceService
{
    public function __construct(
        private readonly CompetitionAuditService $audit,
        private readonly CompetitionScopeService $scope,
        private readonly ParentProviderInterface $parents,
    ) {}

    public function protests(CompetitionEdition $edition, array $filters, User $actor)
    {
        $this->scope->assertEdition($actor, $edition);

        return CompetitionProtest::query()->where('edition_id', $edition->id)
            ->when(! empty($filters['status']), fn (Builder $query) => $query->where('status', strtoupper((string) $filters['status'])))
            ->orderByDesc('submitted_at')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 50))));
    }

    public function createProtest(CompetitionEdition $edition, array $data, User $actor): CompetitionProtest
    {
        $this->scope->assertEdition($actor, $edition);
        $participant = isset($data['participant_id']) ? CompetitionParticipant::query()->where('edition_id', $edition->id)->findOrFail($data['participant_id']) : null;
        $match = isset($data['match_id']) ? CompetitionMatch::query()->findOrFail($data['match_id']) : null;
        $session = isset($data['session_id']) ? CompetitionSession::query()->findOrFail($data['session_id']) : null;
        if ($match && (int) $match->competitionRound->edition_id !== (int) $edition->id) {
            throw ValidationException::withMessages(['match_id' => 'Pertandingan bukan milik edition ini.']);
        }
        if ($session && (int) $session->edition_id !== (int) $edition->id) {
            throw ValidationException::withMessages(['session_id' => 'Sesi bukan milik edition ini.']);
        }
        $this->assertParticipantOrManager($participant, $actor, 'appeal.create');
        if ($edition->status === 'ARCHIVED') {
            throw ValidationException::withMessages(['edition' => 'Protest tidak dapat dibuat setelah edition diarsipkan.']);
        }

        return DB::transaction(function () use ($edition, $data, $actor, $participant, $match, $session): CompetitionProtest {
            $protest = CompetitionProtest::query()->create([
                'edition_id' => $edition->id,
                'category_id' => $participant?->category_id ?? $match?->category_id ?? $session?->category_id,
                'round_id' => $match?->round_id ?? $session?->round_id ?? ($data['round_id'] ?? null),
                'match_id' => $match?->id,
                'session_id' => $session?->id,
                'participant_id' => $participant?->id,
                'team_id' => $participant?->team_id,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'protest_type' => strtoupper((string) ($data['protest_type'] ?? 'SCORE_REVIEW')),
                'subject' => trim($data['subject']),
                'description' => trim($data['description']),
                'evidence' => $data['evidence'] ?? [],
                'status' => 'SUBMITTED',
                'appeal_deadline' => $data['appeal_deadline'] ?? now()->addHours(24),
            ]);
            $this->audit->record('AppealSubmitted', $actor, $protest, $edition, after: ['protest_type' => $protest->protest_type, 'status' => $protest->status]);

            return $protest;
        });
    }

    public function createAppeal(CompetitionProtest $protest, array $data, User $actor): CompetitionAppeal
    {
        $edition = CompetitionEdition::query()->findOrFail($protest->edition_id);
        $this->scope->assertEdition($actor, $edition);
        $participant = $protest->participant_id ? CompetitionParticipant::query()->find($protest->participant_id) : null;
        $this->assertParticipantOrManager($participant, $actor, 'appeal.create');
        if ($protest->appeal_deadline !== null && $protest->appeal_deadline->isPast()) {
            throw ValidationException::withMessages(['appeal_deadline' => 'Batas waktu pengajuan appeal telah lewat.']);
        }
        $appeal = CompetitionAppeal::query()->create([
            'edition_id' => $edition->id,
            'protest_id' => $protest->id,
            'result_id' => $data['result_id'] ?? null,
            'participant_id' => $participant?->id,
            'team_id' => $participant?->team_id,
            'submitted_by' => $actor->id,
            'appeal_type' => strtoupper((string) ($data['appeal_type'] ?? $protest->protest_type)),
            'grounds' => trim($data['grounds']),
            'description' => $data['description'] ?? null,
            'evidence' => $data['evidence'] ?? $protest->evidence,
            'requested_remedy' => $data['requested_remedy'] ?? null,
            'status' => 'SUBMITTED',
            'submitted_at' => now(),
            'deadline_at' => $protest->appeal_deadline,
            'is_late' => false,
            'revision_number' => 1,
        ]);
        $this->audit->record('AppealSubmitted', $actor, $appeal, $edition, after: ['protest_id' => $protest->id, 'appeal_type' => $appeal->appeal_type]);

        return $appeal;
    }

    public function appeals(CompetitionEdition $edition, array $filters, User $actor)
    {
        $this->scope->assertEdition($actor, $edition);

        return CompetitionAppeal::query()->where('edition_id', $edition->id)
            ->when(! empty($filters['status']), fn (Builder $query) => $query->where('status', strtoupper((string) $filters['status'])))
            ->when(! empty($filters['decision']), fn (Builder $query) => $query->where('decision', strtoupper((string) $filters['decision'])))->orderByDesc('submitted_at')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 50))));
    }

    public function decideAppeal(CompetitionAppeal $appeal, User $actor, string $decision, string $reason): CompetitionAppeal
    {
        if (! $actor->can('appeal.decide')) {
            abort(403);
        }
        $edition = CompetitionEdition::query()->findOrFail($appeal->edition_id);
        $this->scope->assertEdition($actor, $edition);
        $decision = strtoupper($decision);
        if (! in_array($decision, ['UPHOLD', 'MODIFY', 'REVERSE', 'REJECT'], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan harus UPHOLD, MODIFY, REVERSE, atau REJECT.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan keputusan wajib dicatat.']);
        }
        if ($appeal->is_final) {
            throw ValidationException::withMessages(['appeal' => 'Keputusan final tidak dapat ditimpa; buat appeal lanjutan dengan referensi baru.']);
        }

        return DB::transaction(function () use ($appeal, $actor, $decision, $reason, $edition): CompetitionAppeal {
            $appeal = CompetitionAppeal::query()->whereKey($appeal->id)->lockForUpdate()->firstOrFail();
            $before = ['status' => $appeal->status, 'decision' => $appeal->decision, 'decision_reason' => $appeal->decision_reason];
            $appeal->forceFill([
                'status' => 'DECIDED',
                'decision' => $decision,
                'decision_reason' => $reason,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'is_final' => true,
            ])->save();
            if ($appeal->protest_id !== null) {
                CompetitionProtest::query()->whereKey($appeal->protest_id)->update([
                    'status' => 'RESOLVED', 'decision' => $decision, 'resolution' => $reason,
                    'resolved_by' => $actor->id, 'resolved_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit->record('AppealDecided', $actor, $appeal, $edition, before: $before, after: ['status' => 'DECIDED', 'decision' => $decision, 'reason' => $reason], reason: $reason);

            return $appeal->refresh();
        });
    }

    public function incidents(CompetitionEdition $edition, array $filters, User $actor)
    {
        $this->scope->assertEdition($actor, $edition);

        return CompetitionIncident::query()->where('edition_id', $edition->id)
            ->when(! empty($filters['status']), fn (Builder $query) => $query->where('status', strtoupper((string) $filters['status'])))
            ->when(! empty($filters['severity']), fn (Builder $query) => $query->where('severity', strtoupper((string) $filters['severity'])))
            ->orderByDesc('reported_at')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 50))));
    }

    public function createIncident(CompetitionEdition $edition, array $data, User $actor): CompetitionIncident
    {
        if (! $actor->can('incident.manage')) {
            abort(403);
        }
        $this->scope->assertEdition($actor, $edition);
        $incident = CompetitionIncident::query()->create([
            'edition_id' => $edition->id,
            'category_id' => $data['category_id'] ?? null,
            'round_id' => $data['round_id'] ?? null,
            'session_id' => $data['session_id'] ?? null,
            'match_id' => $data['match_id'] ?? null,
            'participant_id' => $data['participant_id'] ?? null,
            'team_id' => $data['team_id'] ?? null,
            'incident_type' => strtoupper((string) ($data['incident_type'] ?? 'OTHER')),
            'severity' => strtoupper((string) ($data['severity'] ?? 'LOW')),
            'title' => trim($data['title']),
            'description' => trim($data['description']),
            'evidence' => $data['evidence'] ?? [],
            'action_taken' => $data['action_taken'] ?? null,
            'status' => 'OPEN',
            'reported_by' => $actor->id,
            'reported_at' => now(),
            'requires_follow_up' => (bool) ($data['requires_follow_up'] ?? false),
        ]);
        $this->audit->record('CompetitionIncidentReported', $actor, $incident, $edition, after: ['severity' => $incident->severity, 'status' => $incident->status]);

        return $incident;
    }

    public function resolveIncident(CompetitionIncident $incident, User $actor, string $resolution): CompetitionIncident
    {
        if (! $actor->can('incident.manage')) {
            abort(403);
        }
        $edition = CompetitionEdition::query()->findOrFail($incident->edition_id);
        $this->scope->assertEdition($actor, $edition);
        if ($incident->status === 'RESOLVED') {
            return $incident;
        }
        if (trim($resolution) === '') {
            throw ValidationException::withMessages(['resolution' => 'Tindakan penyelesaian wajib dicatat.']);
        }
        $incident->forceFill(['status' => 'RESOLVED', 'resolution' => $resolution, 'resolved_by' => $actor->id, 'resolved_at' => now()])->save();
        $this->audit->record('CompetitionIncidentResolved', $actor, $incident, $edition, after: ['status' => 'RESOLVED'], reason: $resolution);

        return $incident->refresh();
    }

    public function createAppealCommittee(CompetitionEdition $edition, array $data, User $actor): CompetitionAppealCommittee
    {
        if (! $actor->can('appeal.review')) {
            abort(403);
        }
        $this->scope->assertEdition($actor, $edition);

        return CompetitionAppealCommittee::query()->create([
            'edition_id' => $edition->id, 'name' => trim($data['name']), 'chair_employee_id' => $data['chair_employee_id'] ?? null,
            'status' => 'ACTIVE', 'constituted_at' => now(), 'notes' => $data['notes'] ?? null,
        ]);
    }

    public function addCommitteeMember(CompetitionAppealCommittee $committee, array $data, User $actor): CompetitionAppealCommitteeMember
    {
        if (! $actor->can('appeal.review')) {
            abort(403);
        }
        $this->scope->assertEdition($actor, CompetitionEdition::query()->findOrFail($committee->edition_id));

        return CompetitionAppealCommitteeMember::query()->create([
            'committee_id' => $committee->id, 'employee_id' => $data['employee_id'] ?? null,
            'user_id' => $data['user_id'] ?? null, 'member_name' => $data['member_name'] ?? null,
            'member_role' => strtoupper((string) ($data['member_role'] ?? 'MEMBER')),
            'is_voting' => (bool) ($data['is_voting'] ?? true), 'notes' => $data['notes'] ?? null,
        ]);
    }

    private function assertParticipantOrManager(?CompetitionParticipant $participant, User $actor, string $permission): void
    {
        if ($actor->can($permission) || $actor->can('registration.review')) {
            return;
        }
        if ($participant === null) {
            abort(403);
        }
        $studentId = $participant->student_id;
        if ($studentId !== null) {
            $userable = $actor->userable;
            if ($userable instanceof Student && (int) $actor->userable_id === (int) $studentId) {
                return;
            }
            if ($this->parents->canActFor((int) $actor->id, (int) $studentId)) {
                return;
            }
        }
        abort(403, 'Protest hanya dapat dibuat oleh peserta terkait atau pengelola kompetisi.');
    }
}
