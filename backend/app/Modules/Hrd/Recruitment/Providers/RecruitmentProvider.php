<?php

namespace App\Modules\Hrd\Recruitment\Providers;

use App\Modules\Hrd\Core\Domain\Contracts\RecruitmentProviderInterface;
use App\Modules\Hrd\Recruitment\Models\HrCandidateProfile;
use App\Modules\Hrd\Recruitment\Models\HrJobOpening;
use App\Modules\Hrd\Recruitment\Models\HrRecruitmentRequisition;
use App\Modules\Hrd\Recruitment\Services\RecruitmentAnalyticsService;

/** Kontrak rekrutmen untuk modul lain: membaca, tidak menulis. */
class RecruitmentProvider implements RecruitmentProviderInterface
{
    public function __construct(private readonly RecruitmentAnalyticsService $analytics) {}

    public function requisition(int $id): ?array
    {
        $requisition = HrRecruitmentRequisition::query()->find($id);

        return $requisition?->only(['code', 'headcount', 'status', 'organization_id', 'department_id', 'position_id', 'priority']);
    }

    public function openHeadcount(int $organizationId): array
    {
        return HrJobOpening::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'OPEN')
            ->get()
            ->map(fn (HrJobOpening $opening) => [
                'code' => $opening->code,
                'title' => $opening->title,
                'openings' => max(0, (int) $opening->openings_count - (int) $opening->filled_count),
            ])
            ->all();
    }

    public function pipelineSummary(int $requisitionId): array
    {
        $requisition = HrRecruitmentRequisition::query()->find($requisitionId);

        return $this->analytics->funnel($requisition?->organization_id, now()->subYear()->toDateString(), now()->toDateString());
    }

    public function candidateByEmail(string $email): ?array
    {
        $candidate = HrCandidateProfile::query()->whereRaw('lower(email) = ?', [strtolower($email)])->first();

        return $candidate?->only(['code', 'full_name', 'email', 'phone', 'status', 'person_id']);
    }

    public function openingsForInternalMobility(int $employeeId): array
    {
        return HrJobOpening::query()
            ->where('status', 'OPEN')
            ->whereIn('visibility', ['INTERNAL', 'INTERNAL_AND_EXTERNAL'])
            ->get()
            ->map(fn (HrJobOpening $opening) => [
                'opening_id' => $opening->getKey(),
                'code' => $opening->code,
                'title' => $opening->title,
                'position_id' => $opening->position_id,
                'closing_at' => $opening->closing_at?->toDateString(),
            ])
            ->all();
    }
}
