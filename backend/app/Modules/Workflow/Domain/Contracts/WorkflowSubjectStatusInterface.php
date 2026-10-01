<?php

namespace App\Modules\Workflow\Domain\Contracts;

/**
 * Kontrak opsional untuk provider konteks yang juga mampu **menerapkan** status
 * bisnis setelah workflow selesai (§43: UpdateBusinessStatusAction).
 *
 * Provider yang mengimplementasikan ini mengizinkan aksi `subject.update_status`
 * dipakai pada definisi workflow — mis. menandai pengajuan sebagai `APPROVED`
 * atau `REJECTED` di tabel modul pemiliknya. Engine tetap tidak tahu tabelnya:
 * yang dipanggil adalah metode milik modul.
 */
interface WorkflowSubjectStatusInterface
{
    /**
     * Terapkan status pada entitas bisnis.
     *
     * @param  array<string, mixed>  $meta
     */
    public function applyStatus(string|int $subjectId, string $status, array $meta = []): void;
}
