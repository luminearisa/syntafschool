<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Domain\Contracts;

use App\Modules\Hrd\Strategic\Analytics\Models\HrPeopleAnalyticsSnapshot;
use App\Modules\Hrd\Strategic\Analytics\Models\HrWorkforceSnapshot;

/**
 * People analytics: dashboard, snapshot, tren, cakupan domain.
 *
 * Kontrak ini yang dipakai modul lain dan AI; implementasinya didaftarkan di
 * HrdPhase3ServiceProvider. Mengganti implementasi tidak menyentuh layanan.
 */
interface PeopleAnalyticsProviderInterface
{
    /** @return array<string, mixed> */
    public function dashboard(string $code, array $filters = [], mixed $user = null): array;

    public function captureWorkforceSnapshot(string $granularity = 'MONTHLY', ?int $organizationId = null, mixed $actor = null): HrWorkforceSnapshot;

    public function captureDomainSnapshot(string $domain, ?int $organizationId = null, mixed $actor = null): HrPeopleAnalyticsSnapshot;

    /** @return array<string, mixed> */
    public function trends(array $metricCodes, int $periods = 12, array $filters = [], mixed $user = null): array;

    /** @return array<string, mixed> */
    public function coverage(): array;
}
