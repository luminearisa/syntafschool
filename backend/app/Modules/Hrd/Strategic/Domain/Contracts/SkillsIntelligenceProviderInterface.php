<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Domain\Contracts;

use App\Modules\Hrd\Strategic\Skills\Models\HrSkillAssessment;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillEvidence;

/**
 * Kecerdasan keterampilan: bukti, keyakinan, gap, heatmap, permintaan.
 *
 * Kontrak ini yang dipakai modul lain dan AI; implementasinya didaftarkan di
 * HrdPhase3ServiceProvider. Mengganti implementasi tidak menyentuh layanan.
 */
interface SkillsIntelligenceProviderInterface
{
    public function recordEvidence(int $employeeId, string $skillCode, array $data, mixed $actor = null): HrSkillEvidence;

    public function assess(int $employeeId, string $skillCode, string $level, string $method = 'MANAGER', ?int $assessorEmployeeId = null, mixed $actor = null): HrSkillAssessment;

    /** @return array{gaps:array<int, array<string, mixed>>, summary:array<string, mixed>} */
    public function gapForEmployee(int $employeeId, string $scopeType = 'POSITION', ?int $scopeId = null, ?int $planId = null): array;

    /** @return array<string, mixed> */
    public function heatmap(?int $organizationId = null, ?int $departmentId = null, int $limit = 12): array;

    /** @return array{codes:array<int, string>, rows:array<int, array<string, mixed>>} */
    public function criticalSkills(?int $organizationId = null): array;

    /** @return array<string, mixed> */
    public function supplyVsDemand(?int $organizationId = null, ?string $period = null): array;

    /** @return array<int, mixed> */
    public function forecastDemand(?int $organizationId = null, ?string $period = null, array $assumptions = []): array;
}
