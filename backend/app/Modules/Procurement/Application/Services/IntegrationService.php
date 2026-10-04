<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Support\IntegrationResult;
use App\Modules\Procurement\Models\ProcurementIntegration;
use Illuminate\Database\Eloquent\Model;

/**
 * Outbox/inbox integrasi (§33, §50, §63).
 *
 * Semua pemanggilan penyedia luar (Accounting, Budget, Inventory, Asset,
 * Workflow, Notification) dicatat di sini. `idempotency_key` unik menjamin
 * percobaan ulang **tidak** menghasilkan posting/penerimaan ganda.
 */
class IntegrationService
{
    /**
     * Catat (atau ambil) catatan integrasi berdasarkan kunci idempotensi.
     *
     * @param array<string, mixed> $payload
     */
    public function record(
        Model $subject,
        string $provider,
        string $reference,
        string $idempotencyKey,
        array $payload,
        string $direction = 'OUT',
    ): ProcurementIntegration {
        return ProcurementIntegration::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'provider' => $provider,
                'direction' => $direction,
                'reference' => $reference,
                'payload' => $payload,
                'status' => 'PENDING',
            ],
        );
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?ProcurementIntegration
    {
        return ProcurementIntegration::query()->where('idempotency_key', $idempotencyKey)->first();
    }

    /** Tandai sudah dikirim ke penyedia. */
    public function markSent(ProcurementIntegration $integration): void
    {
        $integration->forceFill([
            'status' => 'SENT',
            'sent_at' => now(),
        ])->save();
    }

    /**
     * Simpan hasil pemanggilan penyedia.
     *
     * Bila penyedia melaporkan dirinya belum terpasang, status tetap PENDING
     * (dapat dikirim ulang), bukan FAILED — supaya antrean tidak penuh alarm
     * palsu saat modul tujuan belum dipasang.
     */
    public function markResult(ProcurementIntegration $integration, IntegrationResult $result): ProcurementIntegration
    {
        $status = match (true) {
            $result->ok => 'ACCEPTED',
            $result->errorCode === 'PROVIDER_NOT_INSTALLED' => 'PENDING',
            default => 'FAILED',
        };

        $integration->forceFill([
            'status' => $status,
            'external_id' => $result->externalId ?? $integration->external_id,
            'response' => $result->toArray(),
            'error_message' => $result->ok ? null : $result->message,
            'responded_at' => now(),
        ])->save();

        return $integration;
    }

    /** Naikkan penghitung percobaan ulang dan tandai sedang mencoba. */
    public function markRetry(ProcurementIntegration $integration): void
    {
        $integration->forceFill([
            'status' => 'RETRYING',
            'retry_count' => (int) $integration->retry_count + 1,
        ])->save();
    }

    /**
     * Apakah penyedia sudah benar-benar terpasang (bukan implementasi Null*).
     */
    public function isInstalled(string $provider): bool
    {
        $contract = match ($provider) {
            'ACCOUNTING' => \App\Modules\Procurement\Domain\Contracts\AccountingProviderInterface::class,
            'BUDGET' => \App\Modules\Procurement\Domain\Contracts\BudgetProviderInterface::class,
            'INVENTORY' => \App\Modules\Procurement\Domain\Contracts\InventoryProviderInterface::class,
            'ASSET' => \App\Modules\Procurement\Domain\Contracts\AssetProviderInterface::class,
            'WORKFLOW' => \App\Modules\Procurement\Domain\Contracts\WorkflowProviderInterface::class,
            'NOTIFICATION' => \App\Modules\Procurement\Domain\Contracts\NotificationProviderInterface::class,
            default => null,
        };

        if ($contract === null) {
            return false;
        }

        $implementation = app($contract);

        return ! str_starts_with(class_basename($implementation), 'Null');
    }
}
