<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Workflow Engine.
 *
 * Mesin workflow belum terpasang; persetujuan dicatat berurutan di modul Event dan dapat dialihkan ke Workflow Engine setelah tersedia (§11).
 */
class NullWorkflowProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Mesin workflow belum terpasang; persetujuan dicatat berurutan di modul Event dan dapat dialihkan ke Workflow Engine setelah tersedia (§11).';

    protected const TARGET_CONTRACT = null;

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function start(string $workflowCode, array $context): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function submit(int $instanceId, array $payload = []): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function approve(int $instanceId, array $payload = []): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function reject(int $instanceId, string $reason): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function status(int $instanceId): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }
}
