<?php

namespace App\Modules\Hrd\Recruitment\Services;

use App\Modules\Hrd\Recruitment\Models\HrCandidateApplication;
use App\Modules\Hrd\Recruitment\Models\HrInterviewSchedule;
use App\Modules\Hrd\Recruitment\Models\HrJobOffer;
use App\Modules\Hrd\Recruitment\Models\HrJobOpening;
use App\Modules\Hrd\Recruitment\Models\HrRecruitmentRequisition;
use Carbon\Carbon;

/**
 * Analitik rekrutmen (§85): corong, sumber, waktu, biaya.
 *
 * Semua angka dihitung dari baris yang ada — tidak ada tabel ringkasan yang
 * dapat berbeda dari kenyataan.
 */
class RecruitmentAnalyticsService
{
    /** @return array<string, mixed> */
    public function dashboard(?int $organizationId = null, ?string $from = null, ?string $to = null): array
    {
        $from = $from ?? now()->startOfYear()->toDateString();
        $to = $to ?? now()->toDateString();

        return [
            'open_requisitions' => $this->requisitions($organizationId, $from, $to),
            'funnel' => $this->funnel($organizationId, $from, $to),
            'sources' => $this->sourceEffectiveness($organizationId, $from, $to),
            'time_to_hire_days' => $this->averageTimeToHire($organizationId, $from, $to),
            'time_to_fill_days' => $this->averageTimeToFill($organizationId, $from, $to),
            'offers' => $this->offerStats($organizationId, $from, $to),
        ];
    }

    /** @return array<string, int> */
    public function requisitions(?int $organizationId, string $from, string $to): array
    {
        $query = HrRecruitmentRequisition::query()->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()])
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId));

        return $query->get()->groupBy('status')->map->count()->all()
            + ['total' => (clone $query)->count(), 'headcount' => (int) (clone $query)->sum('headcount')];
    }

    /**
     * Corong: jumlah lamaran per tahap, termasuk yang sudah final.
     *
     * @return array<string, int>
     */
    public function funnel(?int $organizationId, string $from, string $to): array
    {
        $applications = HrCandidateApplication::query()
            ->whereBetween('applied_at', [$from, Carbon::parse($to)->endOfDay()])
            ->when($organizationId !== null, fn ($q) => $q->whereHas('opening', fn ($o) => $o->where('organization_id', $organizationId)))
            ->get();

        $funnel = [];
        foreach (config('hrd_phase2.recruitment.pipeline', []) as $stage) {
            $funnel[$stage] = $applications->where('stage_code', $stage)->count();
        }

        foreach (['REJECTED', 'WITHDRAWN', 'HIRED'] as $terminal) {
            $funnel[$terminal] = $applications->where('stage_code', $terminal)->count();
        }

        $funnel['total'] = $applications->count();
        $funnel['interviews_scheduled'] = HrInterviewSchedule::query()
            ->whereIn('application_id', $applications->pluck('id'))
            ->count();

        return $funnel;
    }

    /** @return array<string, array{applications: int, interviews: int, hires: int, conversion_rate: float, cost_per_hire: float|null}> */
    public function sourceEffectiveness(?int $organizationId, string $from, string $to): array
    {
        $applications = HrCandidateApplication::query()
            ->whereBetween('applied_at', [$from, Carbon::parse($to)->endOfDay()])
            ->when($organizationId !== null, fn ($q) => $q->whereHas('opening', fn ($o) => $o->where('organization_id', $organizationId)))
            ->get();

        $interviewed = HrInterviewSchedule::query()->whereIn('application_id', $applications->pluck('id'))->pluck('application_id')->unique();

        $result = [];

        foreach ($applications->groupBy(fn (HrCandidateApplication $application) => $application->source_code ?? 'UNKNOWN') as $source => $group) {
            $hires = $group->where('status', 'HIRED')->count();

            $result[(string) $source] = [
                'applications' => $group->count(),
                'interviews' => $group->whereIn('id', $interviewed)->count(),
                'hires' => $hires,
                'conversion_rate' => round($hires / max($group->count(), 1) * 100, 2),
                'cost_per_hire' => null,
            ];
        }

        return $result;
    }

    public function averageTimeToHire(?int $organizationId, string $from, string $to): ?float
    {
        $query = HrCandidateApplication::query()
            ->where('status', 'HIRED')
            ->whereNotNull('converted_at')
            ->whereBetween('converted_at', [$from, Carbon::parse($to)->endOfDay()])
            ->when($organizationId !== null, fn ($q) => $q->whereHas('opening', fn ($o) => $o->where('organization_id', $organizationId)));

        $applications = $query->get();

        if ($applications->isEmpty()) {
            return null;
        }

        $days = $applications->map(fn (HrCandidateApplication $application) => (int) $application->applied_at->diffInDays($application->converted_at));

        return round($days->avg(), 1);
    }

    public function averageTimeToFill(?int $organizationId, string $from, string $to): ?float
    {
        $openings = HrJobOpening::query()
            ->where('status', 'CLOSED')
            ->whereBetween('published_at', [$from, Carbon::parse($to)->endOfDay()])
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereColumn('filled_count', '>=', 'openings_count')
            ->get();

        if ($openings->isEmpty()) {
            return null;
        }

        $days = $openings->map(fn (HrJobOpening $opening) => $opening->published_at !== null && $opening->closing_at !== null
            ? (int) $opening->published_at->diffInDays($opening->closing_at)
            : null)->filter();

        return $days->isEmpty() ? null : round($days->avg(), 1);
    }

    /** @return array<string, int|float|null> */
    public function offerStats(?int $organizationId, string $from, string $to): array
    {
        $offers = HrJobOffer::query()
            ->whereBetween('created_at', [$from, Carbon::parse($to)->endOfDay()])
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->get();

        return [
            'total' => $offers->count(),
            'sent' => $offers->whereIn('status', ['SENT', 'ACCEPTED', 'DECLINED'])->count(),
            'accepted' => $offers->where('status', 'ACCEPTED')->count(),
            'declined' => $offers->where('status', 'DECLINED')->count(),
            'acceptance_rate' => $offers->whereIn('status', ['ACCEPTED', 'DECLINED'])->count() > 0
                ? round($offers->where('status', 'ACCEPTED')->count() / $offers->whereIn('status', ['ACCEPTED', 'DECLINED'])->count() * 100, 2)
                : null,
            'average_offered_salary' => $offers->whereNotNull('offered_salary')->avg('offered_salary'),
        ];
    }
}
