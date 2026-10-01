<?php

namespace App\Modules\Bk\Domain\Contracts;

/**
 * Jejak audit tingkat ERP (bila ada).

BK selalu menulis jejak auditnya sendiri (`bk_audit_logs`); kontrak ini hanya
untuk meneruskan ringkasan ke audit ERP bila sekolah memilikinya.
 */
interface AuditProviderInterface
{

    /**
     * Teruskan ringkasan audit ke audit ERP (bila ada).
     *
     * @param  array<string, mixed>  $context  metadata aman, tanpa isi catatan
     */
    public function record(string $action, string $subjectType, ?int $subjectId, array $context = []): void;
}
