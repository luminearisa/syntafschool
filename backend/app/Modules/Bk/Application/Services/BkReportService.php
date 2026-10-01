<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\ClosureReason;
use App\Modules\Bk\Domain\Enums\FollowUpStatus;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkFollowUp;
use App\Modules\Bk\Models\BkIntervention;
use App\Modules\Bk\Models\BkReferral;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Laporan BK.
 *
 * Semua laporan bersifat **agregat**: jumlah kasus, sebaran jenis, kepatuhan
 * tindak lanjut, dan durasi penanganan. Tidak ada laporan yang mencetak isi
 * catatan konseling — ekspor pun mengikuti aturan itu, sehingga berkas yang
 * dibagikan ke rapat manajemen tidak pernah memuat hal yang bersifat pribadi.
 */
class BkReportService
{
    public function __construct(private readonly BkAccessService $access)
    {
    }

    /**
     * @return array{type: string, generated_at: string, range: array{from: string, to: string}, rows: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function generate(User $actor, string $type, ?string $from = null, ?string $to = null): array
    {
        $from = $from ?? now()->subDays(90)->toDateString();
        $to = $to ?? now()->toDateString();

        $scoped = ! $this->access->canMonitorWorkload($actor);

        $rows = match ($type) {
            'caseload' => $this->caseload($scoped ? $actor->id : null),
            'referrals' => $this->referrals($from, $to),
            'referral_sources' => $this->referralSources($from, $to),
            'case_types' => $this->caseTypes($from, $to),
            'case_outcomes' => $this->caseOutcomes($from, $to),
            'closure_reasons' => $this->closureReasons($from, $to),
            'duration' => $this->duration($from, $to),
            'interventions' => $this->interventions($from, $to),
            'follow_up_compliance' => $this->followUpCompliance($from, $to),
            'counselor_activity' => $this->counselorActivity($from, $to, $scoped ? $actor->id : null),
            'priority_mix' => $this->priorityMix($from, $to),
            'confidentiality_mix' => $this->confidentialityMix(),
            default => throw new \App\Modules\Bk\Exceptions\BkValidationException(['type' => ['Jenis laporan tidak dikenal.']]),
        };

        return [
            'type' => $type,
            'generated_at' => now()->toDateTimeString(),
            'range' => ['from' => $from, 'to' => $to],
            'rows' => $rows,
            'meta' => ['scope' => $scoped ? 'SELF' : 'SCHOOL'],
        ];
    }

    /** Nilai skalar dari kolom yang mungkin di-cast ke enum. */
    private function scalar(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof \BackedEnum => (string) $value->value,
            default => (string) $value,
        };
    }

    /** @return list<string> */
    public static function types(): array
    {
        return ['caseload', 'referrals', 'referral_sources', 'case_types', 'case_outcomes', 'closure_reasons',
            'duration', 'interventions', 'follow_up_compliance', 'counselor_activity', 'priority_mix', 'confidentiality_mix'];
    }

    /** Nilai skalar dari kolom yang mungkin di-cast ke enum. */
    private function caseload(?int $counselorUserId): array
    {
        return BkCase::query()
            ->open()
            ->when($counselorUserId !== null, fn ($q) => $q->where('assigned_counselor_id', $counselorUserId))
            ->selectRaw('assigned_counselor_id, COUNT(*) as active_cases, SUM(CASE WHEN priority IN ("URGENT","HIGH") THEN 1 ELSE 0 END) as urgent_cases')
            ->groupBy('assigned_counselor_id')
            ->with('counselor:id,name')
            ->get()
            ->map(fn ($row): array => [
                'counselor' => $row->counselor?->name ?? 'Belum ditugaskan',
                'active_cases' => (int) $row->active_cases,
                'urgent_cases' => (int) $row->urgent_cases,
            ])
            ->all();
    }

    private function referrals(string $from, string $to): array
    {
        return BkReferral::query()
            ->whereBetween(DB::raw('date(referred_at)'), [$from, $to])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->get()
            ->map(fn ($row): array => ['status' => $this->scalar($row->status), 'total' => (int) $row->total])
            ->all();
    }

    private function referralSources(string $from, string $to): array
    {
        return BkReferral::query()
            ->whereBetween(DB::raw('date(referred_at)'), [$from, $to])
            ->selectRaw('source_type, COUNT(*) as total')
            ->groupBy('source_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['source' => $this->scalar($row->source_type), 'total' => (int) $row->total])
            ->all();
    }

    private function caseTypes(string $from, string $to): array
    {
        return BkCase::query()
            ->whereBetween(DB::raw('date(opened_at)'), [$from, $to])
            ->selectRaw('case_type, COUNT(*) as total')
            ->groupBy('case_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['case_type' => $this->scalar($row->case_type), 'total' => (int) $row->total])
            ->all();
    }

    private function caseOutcomes(string $from, string $to): array
    {
        return BkCase::query()
            ->whereBetween(DB::raw('date(opened_at)'), [$from, $to])
            ->selectRaw('status, COUNT(*) as total, SUM(reopen_count) as reopens')
            ->groupBy('status')
            ->get()
            ->map(fn ($row): array => ['status' => $this->scalar($row->status), 'total' => (int) $row->total, 'reopens' => (int) $row->reopens])
            ->all();
    }

    private function closureReasons(string $from, string $to): array
    {
        return BkCase::query()
            ->whereNotNull('closure_reason')
            ->whereBetween(DB::raw('date(closed_at)'), [$from, $to])
            ->selectRaw('closure_reason, COUNT(*) as total')
            ->groupBy('closure_reason')
            ->get()
            ->map(fn ($row): array => [
                'reason' => $this->scalar($row->closure_reason),
                'label' => ClosureReason::tryFrom($this->scalar($row->closure_reason) ?? '')?->label(),
                'total' => (int) $row->total,
            ])
            ->all();
    }

    private function duration(string $from, string $to): array
    {
        return BkCase::query()
            ->whereNotNull('closed_at')
            ->whereBetween(DB::raw('date(closed_at)'), [$from, $to])
            ->get(['case_type', 'opened_at', 'closed_at'])
            ->groupBy('case_type')
            ->map(function ($group, $type): array {
                $average = $group->avg(fn (BkCase $case): int => (int) $case->opened_at?->diffInDays($case->closed_at));

                return ['case_type' => $this->scalar($type), 'closed_cases' => $group->count(), 'average_days' => round((float) $average, 1)];
            })
            ->values()
            ->all();
    }

    private function interventions(string $from, string $to): array
    {
        return BkIntervention::query()
            ->whereBetween(DB::raw('date(start_date)'), [$from, $to])
            ->selectRaw('type, status, COUNT(*) as total')
            ->groupBy('type', 'status')
            ->get()
            ->map(fn ($row): array => ['type' => $this->scalar($row->type), 'status' => $this->scalar($row->status), 'total' => (int) $row->total])
            ->all();
    }

    private function followUpCompliance(string $from, string $to): array
    {
        $rows = BkFollowUp::query()
            ->whereBetween(DB::raw('date(due_date)'), [$from, $to])
            ->selectRaw('status, COUNT(*) as total, SUM(reschedule_count) as reschedules')
            ->groupBy('status')
            ->get();

        $total = (int) $rows->sum('total');
        $completed = (int) $rows->firstWhere('status', FollowUpStatus::COMPLETED->value)?->total;

        return [
            ['metric' => 'total', 'value' => $total],
            ['metric' => 'completed', 'value' => $completed],
            ['metric' => 'compliance_percent', 'value' => $total === 0 ? null : round($completed / $total * 100, 1)],
            ['metric' => 'missed', 'value' => (int) $rows->firstWhere('status', FollowUpStatus::MISSED->value)?->total],
            ['metric' => 'reschedules', 'value' => (int) $rows->sum('reschedules')],
        ];
    }

    private function counselorActivity(string $from, string $to, ?int $counselorUserId): array
    {
        $sessions = DB::table('bk_counseling_sessions')
            ->whereBetween(DB::raw('date(scheduled_at)'), [$from, $to])
            ->when($counselorUserId !== null, fn ($q) => $q->where('counselor_id', $counselorUserId))
            ->selectRaw('counselor_id, COUNT(*) as sessions')
            ->groupBy('counselor_id')
            ->pluck('sessions', 'counselor_id');

        $followUps = BkFollowUp::query()
            ->whereBetween(DB::raw('date(due_date)'), [$from, $to])
            ->when($counselorUserId !== null, fn ($q) => $q->where('assigned_to', $counselorUserId))
            ->selectRaw('assigned_to, SUM(CASE WHEN status = "COMPLETED" THEN 1 ELSE 0 END) as completed, COUNT(*) as total')
            ->groupBy('assigned_to')
            ->get()
            ->keyBy('assigned_to');

        $users = \App\Models\User::query()
            ->whereIn('id', $sessions->keys()->merge($followUps->keys())->unique())
            ->pluck('name', 'id');

        return collect($sessions->keys()->merge($followUps->keys())->unique())
            ->map(function ($userId) use ($sessions, $followUps, $users): array {
                $row = $followUps->get($userId);

                return [
                    'counselor' => $users[$userId] ?? ('#'.$userId),
                    'sessions' => (int) ($sessions[$userId] ?? 0),
                    'follow_ups_total' => (int) ($row->total ?? 0),
                    'follow_ups_completed' => (int) ($row->completed ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    private function priorityMix(string $from, string $to): array
    {
        return BkCase::query()
            ->whereBetween(DB::raw('date(opened_at)'), [$from, $to])
            ->selectRaw('priority, COUNT(*) as total')
            ->groupBy('priority')
            ->get()
            ->map(fn ($row): array => ['priority' => $this->scalar($row->priority), 'total' => (int) $row->total])
            ->all();
    }

    private function confidentialityMix(): array
    {
        return BkCase::query()
            ->open()
            ->selectRaw('confidentiality_level, COUNT(*) as total')
            ->groupBy('confidentiality_level')
            ->get()
            ->map(fn ($row): array => ['level' => $this->scalar($row->confidentiality_level), 'total' => (int) $row->total])
            ->all();
    }
}
