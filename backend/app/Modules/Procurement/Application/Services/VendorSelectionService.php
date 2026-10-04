<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Domain\Enums\ProcurementEventStatus;
use App\Modules\Procurement\Domain\Enums\QuotationStatus;
use App\Modules\Procurement\Domain\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\ProcurementEvent;
use App\Modules\Procurement\Models\Quotation;
use App\Modules\Procurement\Models\Rfq;
use App\Modules\Procurement\Models\VendorSelection;
use Illuminate\Support\Facades\DB;

/**
 * Pemilihan vendor (§16).
 *
 * Menyimpan alasan (`rationale`) dan ringkasan penolakan vendor lain sehingga
 * pertanyaan "mengapa vendor ini dipilih?" selalu dapat dijawab.
 */
class VendorSelectionService
{
    public function __construct(
        private readonly ProcurementNumberingService $numbering,
        private readonly ProcurementAuditService $audit,
        private readonly ProcurementEventService $events,
        private readonly ProcurementControlService $control,
    ) {
    }

    /**
     * @param array<string, mixed> $data {quotation_id, rationale, alternative_vendor_id, recommendation, rejection_summary, overridden}
     */
    public function select(int $rfqId, array $data): VendorSelection
    {
        return DB::transaction(function () use ($rfqId, $data): VendorSelection {
            $rfq = Rfq::query()->with(['quotations', 'event'])->findOrFail($rfqId);
            $quotation = Quotation::query()->findOrFail($data['quotation_id']);

            if ($quotation->rfq_id !== $rfq->id) {
                throw ProcurementException::because('Penawaran tidak termasuk RFQ ini.', 'QUOTATION_MISMATCH');
            }

            $method = $rfq->event?->method;
            $methodEnum = $method instanceof \BackedEnum ? $method : \App\Modules\Procurement\Domain\Enums\ProcurementMethod::tryFrom((string) ($method ?? 'RFQ'));

            $this->control->assertMinQuotations(
                $methodEnum ?? \App\Modules\Procurement\Domain\Enums\ProcurementMethod::RFQ,
                $rfq->quotations->count(),
                (bool) ($data['overridden'] ?? false),
            );

            $selection = VendorSelection::query()->create([
                'number' => $this->numbering->next(ProcurementNumberingService::CODE_SELECTION),
                'event_id' => $rfq->event_id,
                'rfq_id' => $rfq->id,
                'selected_quotation_id' => $quotation->id,
                'selected_vendor_id' => $quotation->vendor_id,
                'alternative_vendor_id' => $data['alternative_vendor_id'] ?? null,
                'rationale' => $data['rationale'],
                'recommendation' => $data['recommendation'] ?? null,
                'rejection_summary' => $data['rejection_summary'] ?? $this->defaultRejectionSummary($rfq, $quotation->id),
                'status' => 'DRAFT',
                'created_by' => \Illuminate\Support\Facades\Auth::id(),
            ]);

            // Tandai penawaran terpilih dan sisanya tidak terpilih.
            foreach ($rfq->quotations as $row) {
                $row->forceFill([
                    'status' => $row->id === $quotation->id ? QuotationStatus::SELECTED->value : QuotationStatus::NOT_SELECTED->value,
                ])->save();
            }

            if ($rfq->event !== null) {
                $rfq->event->forceFill(['status' => ProcurementEventStatus::SELECTED->value])->save();
            }

            $this->audit->record(ProcurementAuditAction::CREATE, $selection, null, $selection->toArray(), 'Pemilihan vendor');
            $this->events->dispatch('VendorSelected', $selection, ['vendor_id' => $selection->selected_vendor_id, 'quotation_id' => $selection->selected_quotation_id]);

            return $selection->fresh(['selectedVendor', 'selectedQuotation', 'alternativeVendor']);
        });
    }

    public function approve(VendorSelection $selection, ?int $userId = null): VendorSelection
    {
        $selection->forceFill([
            'status' => 'APPROVED',
            'approved_by' => $userId ?? \Illuminate\Support\Facades\Auth::id(),
            'approved_at' => now(),
        ])->save();

        $this->audit->record(ProcurementAuditAction::APPROVE, $selection, null, ['status' => 'APPROVED']);

        return $selection->fresh();
    }

    /** @return array<int, array<string, mixed>> */
    protected function defaultRejectionSummary(Rfq $rfq, int $selectedQuotationId): array
    {
        return $rfq->quotations
            ->where('id', '!=', $selectedQuotationId)
            ->map(fn (Quotation $q): array => [
                'quotation_id' => $q->id,
                'vendor_id' => $q->vendor_id,
                'total' => (float) $q->total,
                'reason' => 'Tidak terpilih pada evaluasi.',
            ])
            ->values()
            ->all();
    }
}
