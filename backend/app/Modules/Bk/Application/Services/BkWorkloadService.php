<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\CaseStatus;
use App\Modules\Bk\Domain\Enums\ConferenceActionStatus;
use App\Modules\Bk\Domain\Enums\DevelopmentGoalStatus;
use App\Modules\Bk\Domain\Enums\FollowUpStatus;
use App\Modules\Bk\Domain\Enums\SlaStatus;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseConferenceAction;
use App\Modules\Bk\Models\BkDevelopmentGoal;
use App\Modules\Bk\Models\BkFollowUp;
use App\Modules\Bk\Models\BkSlaEvent;
use Illuminate\Support\Facades\DB;

/**
 * Beban kerja dan pemantauan operasional.
 *
 * Dua tingkat sengaja dipisah:
 *   - **Dasbor konselor**: pekerjaannya sendiri. Tidak ada angka rekan di sini.
 *   - **Dasbor koordinator**: sebaran seluruh tim, kasus tanpa pemilik, SLA
 *     terlampaui, dan umur kasus.
 *
 * Pemisahan itu bukan soal tampilan: memberi konselor daftar peringkat rekan
 * kerjanya mengubah dukungan menjadi kompetisi, dan itu bertentangan dengan
 * maksud modul ini.
 */
class BkWorkloadService
{
    public function __construct(private readonly BkAccessService $access)
    {
    }

    /** Dasbor konselor: hanya pekerjaan orang ini. */
    public function counselorDashboard(User $actor, ?int $counselorUserId = null): array
    {
        $userId = $counselorUserId ?? $actor->id;

        if ($userId !== $actor->id && ! $this->access->canMonitorWorkload($actor)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Anda hanya dapat melihat beban kerja Anda sendiri.');
        }

        $openStatuses = [CaseStatus::OPEN->value, CaseStatus::ASSESSMENT->value, CaseStatus::INTERVENTION->value, CaseStatus::MONITORING->value, CaseStatus::ON_HOLD->value, CaseStatus::REOPENED->value];

        $cases = BkCase::query()->where('assigned_counselor_id', $userId);

        return [
            'counselor_user_id' => $userId,
            'assigned_cases' => (clone $cases)->count(),
            'active_cases' => (clone $cases)->whereIn('status', $openStatuses)->count(),
            'high_priority_cases' => (clone $cases)->whereIn('status', $openStatuses)->whereIn('priority', ['HIGH', 'URGENT'])->count(),
            'upcoming_sessions' => DB::table('bk_counseling_sessions')
                ->where('counselor_user_id', $userId)
                ->where('scheduled_at', '>=', now())
                ->where('status', 'SCHEDULED')
                ->count(),
            'pending_follow_ups' => BkFollowUp::query()
                ->where('assigned_to', $userId)
                ->whereIn('status', [FollowUpStatus::PENDING->value, FollowUpStatus::RESCHEDULED->value])
                ->count(),
            'overdue_follow_ups' => BkFollowUp::query()
                ->where('assigned_to', $userId)
                ->whereIn('status', [FollowUpStatus::PENDING->value, FollowUpStatus::MISSED->value])
                ->whereDate('due_date', '<', today())
                ->count(),
            'cases_near_sla' => $this->nearSlaCount($userId),
            'open_conference_actions' => BkCaseConferenceAction::query()
                ->where('responsible_user_id', $userId)
                ->whereIn('status', [ConferenceActionStatus::PENDING->value, ConferenceActionStatus::IN_PROGRESS->value, ConferenceActionStatus::OVERDUE->value])
                ->count(),
            'at_risk_development_goals' => BkDevelopmentGoal::query()
                ->where('owner_user_id', $userId)
                ->where('status', DevelopmentGoalStatus::AT_RISK->value)
                ->count(),
        ];
    }

    /** Dasbor koordinator: sebaran tim, tanpa isi kasus. */
    public function coordinatorDashboard(User $actor): array
    {
        if (! $this->access->canMonitorWorkload($actor)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Dasbor beban kerja hanya untuk koordinator atau admin BK.');
        }

        $openStatuses = [CaseStatus::OPEN->value, CaseStatus::ASSESSMENT->value, CaseStatus::INTERVENTION->value, CaseStatus::MONITORING->value, CaseStatus::ON_HOLD->value, CaseStatus::REOPENED->value];

        $perCounselor = BkCase::query()
            ->join('users', 'users.id', '=', 'bk_cases.assigned_counselor_id')
            ->whereIn('bk_cases.status', $openStatuses)
            ->select(
                'users.id as user_id',
                'users.name as name',
                DB::raw('count(*) as active_cases'),
                DB::raw("sum(case when bk_cases.priority in ('HIGH','URGENT') then 1 else 0 end) as high_priority"),
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('active_cases')
            ->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'name' => (string) $row->name,
                'active_cases' => (int) $row->active_cases,
                'high_priority' => (int) $row->high_priority,
                'overdue_follow_ups' => BkFollowUp::query()
                    ->where('assigned_to', $row->user_id)
                    ->whereIn('status', [FollowUpStatus::PENDING->value, FollowUpStatus::MISSED->value])
                    ->whereDate('due_date', '<', today())
                    ->count(),
            ])
            ->all();

        return [
            'counselors' => $perCounselor,
            'unassigned_cases' => BkCase::query()->whereNull('assigned_counselor_id')->whereIn('status', $openStatuses)->count(),
            'unassigned_urgent_cases' => BkCase::query()
                ->whereNull('assigned_counselor_id')
                ->whereIn('status', $openStatuses)
                ->whereIn('priority', ['HIGH', 'URGENT'])
                ->count(),
            'sla_breached' => BkSlaEvent::query()->breached()->count(),
            'sla_escalated' => BkSlaEvent::query()->where('status', SlaStatus::ESCALATED->value)->count(),
            'sla_running' => BkSlaEvent::query()->active()->count(),
            'overdue_follow_ups_total' => BkFollowUp::query()
                ->whereIn('status', [FollowUpStatus::PENDING->value, FollowUpStatus::MISSED->value])
                ->whereDate('due_date', '<', today())
                ->count(),
            'open_conference_actions' => BkCaseConferenceAction::query()
                ->whereIn('status', [ConferenceActionStatus::PENDING->value, ConferenceActionStatus::IN_PROGRESS->value, ConferenceActionStatus::OVERDUE->value])
                ->count(),
            'escalations_open' => DB::table('bk_escalations')->whereIn('status', ['PENDING', 'ACKNOWLEDGED'])->count(),
            'note' => 'Angka beban kerja untuk mengatur alur kerja, bukan untuk menilai kinerja per orang.',
        ];
    }

    /** Kandidat penugasan: konselor dengan kapasitas tersisa, diurutkan paling longgar. */
    public function assignmentCandidates(?int $excludeUserId = null): array
    {
        $openStatuses = [CaseStatus::OPEN->value, CaseStatus::ASSESSMENT->value, CaseStatus::INTERVENTION->value, CaseStatus::MONITORING->value, CaseStatus::ON_HOLD->value, CaseStatus::REOPENED->value];

        return DB::table('bk_counselors')
            ->join('users', 'users.id', '=', 'bk_counselors.user_id')
            ->when($excludeUserId !== null, fn ($query) => $query->where('users.id', '!=', $excludeUserId))
            ->select(
                'users.id as user_id',
                'users.name as name',
                'bk_counselors.max_active_cases',
                'bk_counselors.is_available',
                DB::raw('(select count(*) from bk_cases where bk_cases.assigned_counselor_id = users.id and bk_cases.status in ("'.implode('","', $openStatuses).'") and bk_cases.deleted_at is null) as active_cases'),
            )
            ->orderBy('active_cases')
            ->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'name' => (string) $row->name,
                'active_cases' => (int) $row->active_cases,
                'max_active_cases' => $row->max_active_cases !== null ? (int) $row->max_active_cases : null,
                'is_available' => (bool) $row->is_available,
                'has_capacity' => $row->max_active_cases === null || (int) $row->active_cases < (int) $row->max_active_cases,
            ])
            ->all();
    }

    /** Jumlah pekerjaan yang mendekati tenggat SLA untuk satu pengguna. */
    protected function nearSlaCount(int $userId): int
    {
        return BkSlaEvent::query()
            ->active()
            ->where('assigned_user_id', $userId)
            ->whereNotNull('warn_at')
            ->where('warn_at', '<=', now()->addHours(24))
            ->count();
    }
}
