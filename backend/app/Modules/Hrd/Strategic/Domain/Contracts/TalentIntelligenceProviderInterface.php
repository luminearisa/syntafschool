<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Domain\Contracts;


/**
 * Talenta: portofolio pertumbuhan, profil, segmen, kesiapan, matriks.
 *
 * Kontrak ini yang dipakai modul lain dan AI; implementasinya didaftarkan di
 * HrdPhase3ServiceProvider. Mengganti implementasi tidak menyentuh layanan.
 */
interface TalentIntelligenceProviderInterface
{
    /** @return array<string, mixed> */
    public function growthPortfolio(int $employeeId): array;

    /** @return array<string, mixed> */
    public function profile(int $employeeId): array;

    /** @return array<string, mixed> */
    public function segment(int $employeeId): array;

    /** @return array<string, mixed> */
    public function readiness(int $employeeId, ?int $targetPositionId = null): array;

    /** @return array<string, mixed> */
    public function matrix(string $xAxis = 'PERFORMANCE', string $yAxis = 'POTENTIAL', ?int $organizationId = null, int $limit = 200): array;
}
