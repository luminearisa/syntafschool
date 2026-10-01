<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Klinik — peralatan medis memerlukan jadwal inspeksi/ kalibrasi tersendiri.
 *
 * Data medis pasien TIDAK pernah masuk ke modul ini; yang dipertukarkan hanya
 * keberadaan alat dan jadwal pemeliharaannya.
 */
interface ClinicProviderInterface
{
    public function equipment(int $assetId): ?array;

    /** Peralatan medis yang menuntut jadwal inspeksi/kalibrasi. */
    public function equipmentRequiringInspection(int $daysAhead): array;
}
