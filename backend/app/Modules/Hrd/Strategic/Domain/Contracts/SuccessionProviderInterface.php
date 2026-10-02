<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Domain\Contracts;

use App\Modules\Hrd\Strategic\Succession\Models\HrCriticalPosition;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionCandidate;
use App\Modules\Hrd\Strategic\Succession\Models\HrSuccessionPlan;

/**
 * Suksesi: posisi kritis, kandidat, kesiapan, risiko, cakupan.
 *
 * Kontrak ini yang dipakai modul lain dan AI; implementasinya didaftarkan di
 * HrdPhase3ServiceProvider. Mengganti implementasi tidak menyentuh layanan.
 */
interface SuccessionProviderInterface
{
    public function flagCriticalPosition(int $positionId, array $data = [], mixed $actor = null): HrCriticalPosition;

    public function plan(HrCriticalPosition $criticalPosition, array $data = [], mixed $actor = null): HrSuccessionPlan;

    public function nominate(HrSuccessionPlan $plan, int $employeeId, string $candidateType = 'SECONDARY', mixed $actor = null): HrSuccessionCandidate;

    /** @return array<int, mixed> */
    public function assessRisks(HrSuccessionPlan $plan, mixed $actor = null): array;

    /** @return array<string, mixed> */
    public function scenario(HrCriticalPosition $criticalPosition, string $departureType = 'RESIGN', mixed $actor = null): array;

    /** @return array<string, mixed> */
    public function coverage(?int $organizationId = null): array;
}
