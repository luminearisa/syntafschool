<?php

namespace App\Modules\SchoolOperations\Religious\Domain\Contracts;

/**
 * Workflow & Approval Engine (§79). Khutbah, event, dan pemesanan melewati mesin ini.
 *
 * Kontrak yang DIBUTUHKAN modul Religious dari modul lain.
 */
interface WorkflowGatewayInterface
{
    /** Mulai satu proses persetujuan. */
    public function start(array $payload): array;

    /** Setujui satu tahap persetujuan. */
    public function approve(int|string $instanceId, array $payload = []): array;

    /** Tolak satu proses persetujuan. */
    public function reject(int|string $instanceId, string $reason): array;

    /** Status proses persetujuan. */
    public function status(int|string $instanceId): ?array;

    /** Ketersediaan mesin workflow. */
    public function available(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
