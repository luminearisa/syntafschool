<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Domain\Contracts;

use App\Modules\Hrd\Strategic\Forecasting\Models\HrForecastModel;

/**
 * Prakiraan tenaga kerja (headcount, attrition, biaya, pensiun).
 *
 * Kontrak ini yang dipakai modul lain dan AI; implementasinya didaftarkan di
 * HrdPhase3ServiceProvider. Mengganti implementasi tidak menyentuh layanan.
 */
interface WorkforceForecastProviderInterface
{
    public function defineModel(array $data): HrForecastModel;

    /** @return array<string, mixed> */
    public function run(string $modelCode, array $inputs = [], mixed $actor = null): array;

    /** @return array<string, mixed> */
    public function history(string $purpose, int $months = 12, ?int $organizationId = null): array;

    /** @return array<string, mixed> */
    public function dashboard(): array;
}
