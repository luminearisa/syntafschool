<?php

namespace App\Modules\Bk\Domain\Contracts;

/**
 * Integrasi opsional dengan Workflow Engine ERP.

Bila modul Workflow tidak terpasang, `available()` mengembalikan `false` dan BK
tetap berjalan dengan alur internalnya.
 */
interface WorkflowProviderInterface
{

    /** Apakah Workflow Engine tersedia di instalasi ini. */
    public function available(): bool;

    /**
     * Mulai definisi workflow (mis. `bk_external_referral`).
     *
     * @param  array<string, mixed>  $payload
     * @return array{instance_id: int|string, status: ?string}|null
     */
    public function start(string $definitionCode, array $payload, int $actorId): ?array;
}
