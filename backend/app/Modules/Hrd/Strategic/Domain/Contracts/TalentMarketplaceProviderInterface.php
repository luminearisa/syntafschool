<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Domain\Contracts;

use App\Modules\Hrd\Strategic\Talent\Models\HrEmployeeOpportunityMatch;
use App\Modules\Hrd\Strategic\Talent\Models\HrProjectAssignment;
use App\Modules\Hrd\Strategic\Talent\Models\HrTalentOpportunity;

/**
 * Marketplace internal: peluang, pencocokan, alokasi, penugasan.
 *
 * Kontrak ini yang dipakai modul lain dan AI; implementasinya didaftarkan di
 * HrdPhase3ServiceProvider. Mengganti implementasi tidak menyentuh layanan.
 */
interface TalentMarketplaceProviderInterface
{
    public function publish(array $data, mixed $actor = null): HrTalentOpportunity;

    /** @return array<string, mixed> */
    public function match(HrTalentOpportunity $opportunity, array $options = []): array;

    public function review(int $matchId, string $status, mixed $actor = null, ?string $notes = null): HrEmployeeOpportunityMatch;

    /** @return array<string, mixed> */
    public function mobilityCandidates(string $skillCode, ?int $organizationId = null, int $limit = 20): array;

    public function assignProject(array $data, mixed $actor = null): HrProjectAssignment;

    /** @return array<string, mixed> */
    public function allocationAnalysis(?int $organizationId = null, int $limit = 200): array;
}
