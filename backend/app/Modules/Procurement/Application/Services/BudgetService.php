<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Contracts\BudgetProviderInterface;
use App\Modules\Procurement\Domain\Enums\CommitmentStatus;
use App\Modules\Procurement\Domain\Exceptions\ProcurementException;
use App\Modules\Procurement\Domain\Support\IntegrationResult;
use App\Modules\Procurement\Models\Commitment;
use App\Modules\Procurement\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/**
 * Integrasi anggaran (§35).
 *
 * Procurement **tidak** menjadi sumber kebenaran anggaran: ia membaca
 * ketersediaan dari BudgetProvider, lalu meminta komitmen/pelepasan. Komitmen
 * lokal disimpan sebagai jejak, bukan sebagai saldo otoritatif.
 */
class BudgetService
{
    public function __construct(
        private readonly BudgetProviderInterface $budget,
        private readonly IntegrationService $integrations,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function check(array $payload): array
    {
        return $this->budget->checkAvailability($payload);
    }

    /** Komit anggaran saat PO diterbitkan. */
    public function commitForOrder(PurchaseOrder $order): Commitment
    {
        return DB::transaction(function () use ($order): Commitment {
            $payload = [
                'reference' => $order->number,
                'amount' => (float) $order->grand_total,
                'cost_center_id' => $order->cost_center_id,
                'department_id' => $order->department_id,
                'currency' => $order->currency,
            ];

            $key = 'PROC-COMMIT-'.$order->id;

            $integration = $this->integrations->record($order, 'BUDGET', $order->number, $key, $payload);
            $this->integrations->markSent($integration);

            $result = $this->budget->commit($payload);
            $this->integrations->markResult($integration, $result);

            return Commitment::query()->updateOrCreate(
                ['purchase_order_id' => $order->id],
                [
                    'reference' => $order->number,
                    'amount' => (float) $order->grand_total,
                    'status' => $result->ok ? CommitmentStatus::COMMITTED->value : CommitmentStatus::RESERVED->value,
                ],
            );
        });
    }

    /** Lepaskan komitmen saat PO dibatalkan/ditutup tanpa realisasi. */
    public function releaseForOrder(PurchaseOrder $order, string $reason): ?Commitment
    {
        $commitment = Commitment::query()->where('purchase_order_id', $order->id)->first();

        if ($commitment === null) {
            return null;
        }

        $payload = ['reference' => $order->number, 'amount' => (float) $commitment->amount, 'reason' => $reason];
        $key = 'PROC-RELEASE-'.$order->id;

        $integration = $this->integrations->record($order, 'BUDGET', $order->number, $key, $payload);
        $this->integrations->markSent($integration);
        $result = $this->budget->release($payload);
        $this->integrations->markResult($integration, $result);

        $commitment->forceFill([
            'status' => $result->ok ? CommitmentStatus::RELEASED->value : CommitmentStatus::RESERVED->value,
            'released_at' => now(),
            'notes' => $reason,
        ])->save();

        return $commitment->fresh();
    }

    /** Pastikan anggaran mencukupi (kecuali di-override dengan izin). */
    public function assertAvailable(PurchaseOrder $order, bool $overridden = false): void
    {
        $result = $this->check([
            'cost_center_id' => $order->cost_center_id,
            'department_id' => $order->department_id,
            'amount' => (float) $order->grand_total,
            'currency' => $order->currency,
        ]);

        if (($result['sufficient'] ?? true) === false && ! $overridden) {
            throw ProcurementException::because('Anggaran tidak mencukupi untuk PO ini.', 'BUDGET_INSUFFICIENT');
        }
    }

    /** Hasil mentah untuk ditampilkan di UI. */
    public function availability(array $payload): array
    {
        return $this->check($payload);
    }
}
