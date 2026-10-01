<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\CaseStatus;
use App\Modules\Bk\Models\BkAppointment;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCounselingSession;
use App\Modules\Bk\Models\BkFollowUp;
use App\Modules\Bk\Models\BkIntervention;
use App\Modules\Bk\Models\BkReferral;
use App\Modules\Bk\Models\BkSupportRequest;
use Illuminate\Support\Facades\DB;

/**
 * Dasbor BK — angka yang mengikuti wewenang pemakainya.
 *
 * Konselor melihat beban kerjanya sendiri; koordinator dan administrator melihat
 * seluruh modul. Angka yang ditampilkan adalah **agregat** (jumlah, sebaran),
 * bukan isi penanganan, sehingga dasbor dapat dibuka pemegang izin `bk.view`
 * tanpa membocorkan apa pun.
 */
class BkDashboardService
{
    public function __construct(private readonly BkAccessService $access)
    {
    }

    /** @return array<string, mixed> */
    public function widgets(User $actor): array
    {
        $isStaff = $this->access->isBkStaff($actor);
        $scoped = ! $this->access->canMonitorWorkload($actor);

        $caseQuery = BkCase::query();
        $referralQuery = BkReferral::query();
        $appointmentQuery = BkAppointment::query();
        $followUpQuery = BkFollowUp::query();
        $interventionQuery = BkIntervention::query();

        if (! $isStaff) {
            $studentIds = $this->access->visibleStudentIds($actor);
            $caseQuery->whereIn('student_id', $studentIds);
            $referralQuery->whereIn('student_id', $studentIds);
            $appointmentQuery->whereIn('student_id', $studentIds);
            $followUpQuery->whereRaw('1 = 0');
            $interventionQuery->whereRaw('1 = 0');
        } elseif ($scoped) {
            $caseQuery->where('assigned_counselor_id', $actor->id);
            $referralQuery->where(fn ($q) => $q->where('referred_by', $actor->id)->orWhereIn('id', $caseQuery->pluck('source_referral_id')->filter()->all() ?: [-1]));
            $appointmentQuery->where('counselor_id', $actor->id);
            $followUpQuery->where('assigned_to', $actor->id);
            $interventionQuery->where('owner_user_id', $actor->id);
        }

        $today = now()->toDateString();

        return [
            'active_cases' => (clone $caseQuery)->open()->count(),
            'new_referrals' => (clone $referralQuery)->whereDate('referred_at', $today)->count(),
            'pending_screening' => (clone $referralQuery)->queue()->count(),
            'unassigned_cases' => (clone $caseQuery)->open()->whereNull('assigned_counselor_id')->count(),
            'today_sessions' => (clone $appointmentQuery)->whereDate('scheduled_start', $today)->whereIn('status', ['SCHEDULED', 'CONFIRMED'])->count(),
            'upcoming_sessions' => (clone $appointmentQuery)->upcoming()->count(),
            'overdue_follow_ups' => (clone $followUpQuery)->where('is_overdue', true)->open()->count(),
            'open_interventions' => (clone $interventionQuery)->open()->count(),
            'pending_support_requests' => BkSupportRequest::query()->queued()->count(),
            'review_due_soon' => (clone $caseQuery)->open()
                ->whereNotNull('target_review_date')
                ->whereBetween('target_review_date', [$today, now()->addDays(7)->toDateString()])
                ->count(),
            'cases_by_category' => $this->casesBy((clone $caseQuery), 'category'),
            'cases_by_priority' => $this->casesBy((clone $caseQuery), 'priority'),
            'cases_by_counselor' => $this->casesByCounselor((clone $caseQuery)),
            'cases_by_status' => $this->casesBy((clone $caseQuery), 'status'),
            'average_case_duration_days' => $this->averageDuration((clone $caseQuery)),
            'sessions_this_week' => BkCounselingSession::query()
                ->whereBetween('scheduled_at', [now()->startOfWeek(), now()->endOfWeek()])
                ->when($scoped && $isStaff, fn ($q) => $q->where('counselor_id', $actor->id))
                ->count(),
        ];
    }

    /** @return list<array{key: string, total: int}> */
    private function casesBy(\Illuminate\Database\Eloquent\Builder $query, string $column): array
    {
        return $query->selectRaw($column.', COUNT(*) as total')
            ->groupBy($column)
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['key' => (string) $row->{$column}, 'total' => (int) $row->total])
            ->all();
    }

    /** @return list<array{counselor: string, total: int}> */
    private function casesByCounselor(\Illuminate\Database\Eloquent\Builder $query): array
    {
        return $query->leftJoin('users', 'users.id', '=', 'bk_cases.assigned_counselor_id')
            ->selectRaw('COALESCE(users.name, "Belum ditugaskan") as counselor, COUNT(*) as total')
            ->groupBy('counselor')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['counselor' => (string) $row->counselor, 'total' => (int) $row->total])
            ->all();
    }

    /** Rata-rata durasi kasus yang sudah ditutup (hari). */
    private function averageDuration(\Illuminate\Database\Eloquent\Builder $query): ?float
    {
        $closed = (clone $query)->whereIn('status', [CaseStatus::CLOSED->value, CaseStatus::RESOLVED->value])
            ->whereNotNull('closed_at')
            ->get(['opened_at', 'closed_at']);

        if ($closed->isEmpty()) {
            return null;
        }

        $total = $closed->sum(fn (BkCase $case): int => (int) $case->opened_at?->diffInDays($case->closed_at));

        return round($total / $closed->count(), 1);
    }

    /** Antrean kerja hari ini untuk konselor yang sedang bertugas. */
    public function todaysWorkload(User $actor): array
    {
        $appointments = BkAppointment::query()
            ->with(['student:id,full_name'])
            ->where('counselor_id', $actor->id)
            ->whereDate('scheduled_start', now()->toDateString())
            ->orderBy('scheduled_start')
            ->get();

        $dueFollowUps = BkFollowUp::query()
            ->with(['case:id,case_number'])
            ->where('assigned_to', $actor->id)
            ->open()
            ->whereDate('due_date', '<=', now()->toDateString())
            ->orderBy('due_date')
            ->get();

        return [
            'appointments' => $appointments->map(fn (BkAppointment $row): array => [
                'id' => $row->id,
                'number' => $row->appointment_number,
                'student' => $row->student?->full_name,
                'time' => $row->scheduled_start?->format('H:i'),
                'status' => $row->status->value,
            ])->all(),
            'follow_ups' => $dueFollowUps->map(fn (BkFollowUp $row): array => [
                'id' => $row->id,
                'title' => $row->title,
                'case_number' => $row->case?->case_number,
                'due_date' => $row->due_date?->toDateString(),
                'is_overdue' => (bool) $row->is_overdue,
            ])->all(),
        ];
    }

    /** Kasus yang paling lama menunggu tindakan (untuk papan monitoring). */
    public function agingCases(int $limit = 10): array
    {
        return BkCase::query()
            ->open()
            ->with(['student:id,full_name', 'counselor:id,name'])
            ->orderBy('opened_at')
            ->limit($limit)
            ->get()
            ->map(fn (BkCase $case): array => [
                'id' => $case->id,
                'case_number' => $case->case_number,
                'student' => $case->student?->full_name,
                'counselor' => $case->counselor?->name,
                'status' => $case->status->value,
                'opened_at' => $case->opened_at?->toDateString(),
                'age_days' => (int) $case->opened_at?->diffInDays(now()),
                'review_overdue' => $case->target_review_date !== null && $case->target_review_date->isPast(),
            ])
            ->all();
    }
}
