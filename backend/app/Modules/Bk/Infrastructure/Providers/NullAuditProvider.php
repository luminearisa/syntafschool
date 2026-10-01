<?php

namespace App\Modules\Bk\Infrastructure\Providers;

use App\Modules\Bk\Domain\Contracts\AuditProviderInterface;

/**
 * Penerusan audit ke ERP: tidak melakukan apa pun.
 *
 * BK sudah memiliki jejak auditnya sendiri (`bk_audit_logs`) yang lengkap; kelas
 * ini menjadi titik sambung bila sekolah kelak memasang audit terpusat.
 */
class NullAuditProvider implements AuditProviderInterface
{
    public function record(string $action, string $subjectType, ?int $subjectId, array $context = []): void
    {
        // Sengaja kosong: jejak audit BK ditulis BkAuditService ke tabelnya sendiri.
    }
}
