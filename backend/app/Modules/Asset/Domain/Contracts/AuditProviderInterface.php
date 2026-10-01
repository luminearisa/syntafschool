<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Jejak audit — modul lain boleh menitipkan catatan auditnya.
 *
 * Modul Asset selalu mencatat sendiri setiap perubahan siklus hidup aset lewat
 * `AssetAuditService`; kontrak ini hanya untuk menerima catatan dari luar agar
 * satu riwayat aset tidak terbelah di dua tempat.
 */
interface AuditProviderInterface
{
    public function record(string $action, string $subjectType, int $subjectId, array $context = []): void;

    public function history(string $subjectType, int $subjectId, int $limit = 100): array;
}
