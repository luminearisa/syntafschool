<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Domain\Contracts;

use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforcePlan;
use App\Modules\Hrd\Strategic\Workforce\Models\HrWorkforceSupply;

/**
 * Perencanaan tenaga kerja strategis (permintaan, pasokan, gap, biaya, posisi).
 *
 * Kontrak ini yang dipakai modul lain dan AI; implementasinya didaftarkan di
 * HrdPhase3ServiceProvider. Mengganti implementasi tidak menyentuh layanan.
 */
interface WorkforcePlanningProviderInterface
{
    public function createPlan(array $data, mixed $actor = null): HrWorkforcePlan;

    public function captureSupply(HrWorkforcePlan $plan, array $filter = [], mixed $actor = null): HrWorkforceSupply;

    /** @return array{gaps:array<int, array<string, mixed>>, summary:array<string, mixed>} */
    public function analyseGaps(HrWorkforcePlan $plan, mixed $actor = null): array;

    /** @return array{rows:array<int, array<string, mixed>>, totals:array<string, mixed>} */
    public function forecastCost(HrWorkforcePlan $plan, array $assumptions = [], mixed $actor = null): array;

    /** @return array<string, mixed> */
    public function optimisePositions(?int $organizationId = null): array;

    /** @return array<string, mixed> */
    public function capacity(?int $organizationId = null, ?string $period = null, mixed $user = null): array;

    /** @return array<string, mixed> */
    public function dashboard(HrWorkforcePlan $plan): array;
}
