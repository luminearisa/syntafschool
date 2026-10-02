<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak audit lintas modul (§51, §66).
 *
 * HRD memiliki `hr_audit_logs` sendiri, tetapi peristiwa yang menyangkut modul
 * lain (mis. "status pegawai diubah karena permintaan Keuangan") juga dicatatkan
 * ke audit terpusat bila modulnya terpasang. Audit HRD tetap ditulis lebih dulu,
 * jadi tidak ada perubahan penting yang bergantung pada modul lain.
 */
interface AuditProviderInterface
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, string $entityType, int|string|null $entityId, array $before = [], array $after = [], array $context = []): void;

    /** Apakah audit lintas modul tersedia di instalasi ini? */
    public function isAvailable(): bool;
}
