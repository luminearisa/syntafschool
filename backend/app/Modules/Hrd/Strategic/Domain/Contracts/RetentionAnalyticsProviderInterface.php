<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Domain\Contracts;


/**
 * Retensi & attrition: sinyal, analisis dimensi, deret turnover.
 *
 * Kontrak ini yang dipakai modul lain dan AI; implementasinya didaftarkan di
 * HrdPhase3ServiceProvider. Mengganti implementasi tidak menyentuh layanan.
 */
interface RetentionAnalyticsProviderInterface
{
    /** @return array<string, mixed> */
    public function signals(?int $organizationId = null, int $limit = 200, mixed $user = null): array;

    /** @return array<string, mixed> */
    public function attritionAnalytics(?int $organizationId = null, ?string $period = null, string $dimension = 'DEPARTMENT'): array;

    /** @return array<string, mixed> */
    public function turnoverSeries(int $months = 12, ?int $organizationId = null): array;

    /** @return array<string, mixed> */
    public function dashboard(?int $organizationId = null): array;
}
