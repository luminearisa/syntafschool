<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Domain\Contracts;

use App\Modules\Hrd\Strategic\People\Models\HrCompensationScenario;
use App\Modules\Hrd\Strategic\People\Models\HrIncentiveSimulation;

/**
 * Kompensasi: distribusi, ekuitas, simulasi kenaikan & insentif.
 *
 * Kontrak ini yang dipakai modul lain dan AI; implementasinya didaftarkan di
 * HrdPhase3ServiceProvider. Mengganti implementasi tidak menyentuh layanan.
 */
interface CompensationIntelligenceProviderInterface
{
    /** @return array<string, mixed> */
    public function analytics(?int $organizationId = null, int $limit = 500, mixed $user = null): array;

    /** @return array<string, mixed> */
    public function payEquity(?int $organizationId = null, array $options = [], mixed $actor = null): array;

    public function simulate(array $data, mixed $actor = null): HrCompensationScenario;

    public function simulateIncentive(array $data, mixed $actor = null): HrIncentiveSimulation;

    /** @return array<string, mixed> */
    public function budgetVariance(?int $organizationId = null, ?string $period = null): array;
}
