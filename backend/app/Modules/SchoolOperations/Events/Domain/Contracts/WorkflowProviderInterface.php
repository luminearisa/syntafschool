<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * WorkflowProviderInterface
 *
 * Workflow Engine (dipakai, bukan dibuat ulang) (§11).
 *
 * Modul Event hanya melakukan orkestrasi: seluruh data pokok tetap dimiliki
 * modul asalnya. Bila sumber belum terpasang, implementasi wajib menyatakan
 * `available() === false` beserta alasannya — bukan mengarang data.
 */
interface WorkflowProviderInterface
{
    /** Memulai alur persetujuan. */
    public function start(string $workflowCode, array $context): array;

    /** Mengajukan langkah berikutnya. */
    public function submit(int $instanceId, array $payload = []): array;

    /** Menyetujui langkah saat ini. */
    public function approve(int $instanceId, array $payload = []): array;

    /** Menolak langkah saat ini. */
    public function reject(int $instanceId, string $reason): array;

    /** Status alur persetujuan. */
    public function status(int $instanceId): array;

    /** Apakah mesin workflow tersedia. */
    public function isAvailable(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
