<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Domain\Enums\RfqStatus;
use App\Modules\Procurement\Domain\Enums\VendorStatus;
use App\Modules\Procurement\Domain\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\ProcurementEvent;
use App\Modules\Procurement\Models\Rfq;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** RFQ / permintaan penawaran (§12). */
class RfqService
{
    public function __construct(
        private readonly ProcurementNumberingService $numbering,
        private readonly ProcurementAuditService $audit,
        private readonly ProcurementEventService $events,
        private readonly ProcurementControlService $control,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return Rfq::query()
            ->with(['event:id,number,title', 'vendors.vendor:id,legal_name', 'items'])
            ->withCount('quotations')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['event_id'] ?? null, fn ($q, $id) => $q->where('event_id', (int) $id))
            ->when($filters['search'] ?? null, fn ($q, $t) => $q->where('number', 'like', "%{$t}%")->orWhere('title', 'like', "%{$t}%"))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Rfq
    {
        return DB::transaction(function () use ($data): Rfq {
            $items = (array) ($data['items'] ?? []);
            $vendors = (array) ($data['vendors'] ?? []);
            unset($data['items'], $data['vendors']);

            $data['number'] ??= $this->numbering->next(ProcurementNumberingService::CODE_RFQ);
            $data['status'] ??= RfqStatus::DRAFT->value;

            $rfq = Rfq::query()->create($data);

            foreach ($items as $row) {
                $rfq->items()->create($row);
            }

            $this->invite($rfq, $vendors);

            $this->audit->record(ProcurementAuditAction::CREATE, $rfq, null, $rfq->toArray());

            return $rfq->fresh(['items', 'vendors.vendor']);
        });
    }

    /** Undang vendor; hanya vendor yang layak yang boleh diundang (§52.16). */
    public function invite(Rfq $rfq, array $vendorIds): Rfq
    {
        foreach ($vendorIds as $vendorId) {
            $vendor = \App\Modules\Procurement\Models\Vendor::query()->find($vendorId);

            if ($vendor === null) {
                continue;
            }

            if (! $vendor->isUsable()) {
                throw ProcurementException::because("Vendor {$vendor->legal_name} tidak layak diundang.", 'VENDOR_NOT_USABLE');
            }

            $rfq->vendors()->updateOrCreate(
                ['vendor_id' => $vendor->id],
                ['status' => 'INVITED', 'invited_at' => now()],
            );
        }

        return $rfq->fresh('vendors.vendor');
    }

    /** Terbitkan RFQ & kirim ke vendor (§12). */
    public function issue(Rfq $rfq): Rfq
    {
        if ($rfq->items()->count() === 0) {
            throw ProcurementException::because('RFQ tanpa item tidak dapat diterbitkan.', 'RFQ_EMPTY');
        }

        if ($rfq->vendors()->count() === 0) {
            throw ProcurementException::because('RFQ belum memiliki vendor yang diundang.', 'RFQ_NO_VENDORS');
        }

        $rfq->forceFill(['status' => RfqStatus::ISSUED->value, 'issued_at' => now()])->save();

        $this->audit->record(ProcurementAuditAction::ISSUE, $rfq, null, ['status' => 'ISSUED']);
        $this->events->dispatch('RFQIssued', $rfq, ['number' => $rfq->number, 'vendors' => $rfq->vendors()->pluck('vendor_id')->all()]);

        return $rfq->fresh();
    }

    public function close(Rfq $rfq): Rfq
    {
        $rfq->forceFill(['status' => RfqStatus::CLOSED->value])->save();
        $this->audit->record(ProcurementAuditAction::CLOSE, $rfq, null, ['status' => 'CLOSED']);

        return $rfq->fresh();
    }

    /** Buat RFQ dari sebuah peristiwa pengadaan (menarik item dari PR-PR-nya). */
    public function createFromEvent(ProcurementEvent $event, array $data = []): Rfq
    {
        $event->loadMissing('requests.items');

        $items = [];

        foreach ($event->requests as $request) {
            foreach ($request->items as $item) {
                $items[] = [
                    'purchase_request_item_id' => $item->id,
                    'item_id' => $item->item_id,
                    'description' => $item->description,
                    'specification' => $item->specification,
                    'quantity' => (float) $item->quantity,
                    'unit' => $item->unit,
                    'required_date' => $item->required_date?->toDateString(),
                ];
            }
        }

        return $this->create($data + [
            'event_id' => $event->id,
            'title' => $data['title'] ?? $event->title,
            'items' => $items,
            'created_by' => \Illuminate\Support\Facades\Auth::id(),
        ]);
    }
}
