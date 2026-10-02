<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/**
 * Fondasi optimasi rute (§66). Implementasi bawaan tidak menghitung rute
 * (Phase 2 hanya menyiapkan data & batasan) — mesin nyata dapat dipasang
 * belakangan tanpa membongkar core.
 */
interface RouteOptimizationProviderInterface
{
    public function capabilities(): array;

    public function optimize(array $stops, array $constraints = []): array;

    public function isAvailable(): bool;
}
