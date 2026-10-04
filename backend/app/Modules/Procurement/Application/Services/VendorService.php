<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Domain\Enums\VendorClassification;
use App\Modules\Procurement\Domain\Enums\VendorDocumentStatus;
use App\Modules\Procurement\Domain\Enums\VendorStatus;
use App\Modules\Procurement\Domain\Enums\VendorTier;
use App\Modules\Procurement\Domain\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Models\VendorDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Vendor & onboarding (§4–§6).
 *
 * Semua perubahan status vendor melewati layanan ini agar jejak audit dan
 * aturan kelayakan (hanya ACTIVE yang boleh dipakai pada PO) konsisten.
 */
class VendorService
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
        return Vendor::query()
            ->with(['contacts', 'bankAccounts', 'categories'])
            ->search($filters['search'] ?? null)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['vendor_type'] ?? null, fn ($q, $type) => $q->where('vendor_type', $type))
            ->when($filters['classification'] ?? null, fn ($q, $c) => $q->where('classification', $c))
            ->when($filters['tier'] ?? null, fn ($q, $tier) => $q->where('tier', $tier))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->whereHas('categories', fn ($c) => $c->where('proc_categories.id', (int) $id)))
            ->when(filter_var($filters['usable_only'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->usable())
            ->orderBy('legal_name')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Vendor
    {
        return DB::transaction(function () use ($data): Vendor {
            $data['code'] ??= $this->numbering->next(ProcurementNumberingService::CODE_VENDOR);
            $data['status'] ??= VendorStatus::DRAFT->value;

            $vendor = Vendor::query()->create($data);

            $this->syncRelations($vendor, $data);
            $this->audit->record(ProcurementAuditAction::CREATE, $vendor, null, $vendor->toArray());
            $this->events->dispatch('VendorCreated', $vendor, ['code' => $vendor->code]);

            return $vendor->fresh(['contacts', 'addresses', 'bankAccounts', 'documents', 'categories']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Vendor $vendor, array $data): Vendor
    {
        return DB::transaction(function () use ($vendor, $data): Vendor {
            $old = $vendor->toArray();
            $vendor->fill($data)->save();

            $this->syncRelations($vendor, $data);
            $this->audit->record(ProcurementAuditAction::UPDATE, $vendor, $old, $vendor->fresh()->toArray());
            $this->events->dispatch('VendorUpdated', $vendor, ['code' => $vendor->code]);

            return $vendor->fresh(['contacts', 'addresses', 'bankAccounts', 'documents', 'categories']);
        });
    }

    /** Ajukan vendor untuk verifikasi (§5). */
    public function submit(Vendor $vendor): Vendor
    {
        return $this->transition($vendor, VendorStatus::UNDER_VERIFICATION, 'VendorSubmitted');
    }

    /** Verifikasi dokumen vendor (§5). */
    public function verify(Vendor $vendor, ?int $userId = null): Vendor
    {
        $vendor->forceFill([
            'verified_by' => $userId,
            'verified_at' => now(),
            'rejection_reason' => null,
        ])->save();

        return $this->transition($vendor, VendorStatus::VERIFIED, 'VendorVerified');
    }

    /** Setujui vendor → ACTIVE, siap dipakai PO (§5). */
    public function approve(Vendor $vendor): Vendor
    {
        return $this->transition($vendor, VendorStatus::ACTIVE, 'VendorApproved');
    }

    public function reject(Vendor $vendor, string $reason): Vendor
    {
        $vendor->forceFill(['rejection_reason' => $reason])->save();

        return $this->transition($vendor, VendorStatus::REJECTED, 'VendorRejected', ['reason' => $reason]);
    }

    public function suspend(Vendor $vendor, string $reason): Vendor
    {
        return $this->transition($vendor, VendorStatus::SUSPENDED, 'VendorSuspended', ['reason' => $reason]);
    }

    public function deactivate(Vendor $vendor): Vendor
    {
        return $this->transition($vendor, VendorStatus::INACTIVE, 'VendorDeactivated');
    }

    /** Klasifikasi vendor (§6). */
    public function classify(Vendor $vendor, VendorClassification $classification, ?VendorTier $tier = null, ?string $riskLevel = null): Vendor
    {
        $old = ['classification' => $vendor->classification, 'tier' => $vendor->tier, 'risk_level' => $vendor->risk_level];

        $vendor->forceFill(array_filter([
            'classification' => $classification->value,
            'tier' => $tier?->value,
            'risk_level' => $riskLevel,
        ], fn ($v) => $v !== null))->save();

        if ($classification === VendorClassification::BLACKLISTED) {
            $vendor->forceFill(['status' => VendorStatus::BLACKLISTED->value])->save();
        }

        $this->audit->record(ProcurementAuditAction::UPDATE, $vendor, $old, [
            'classification' => $classification->value,
            'tier' => $tier?->value,
            'risk_level' => $riskLevel,
        ], 'Klasifikasi vendor diubah');

        return $vendor->fresh();
    }

    /** Tambah dokumen vendor (§5). @param array<string, mixed> $data */
    public function addDocument(Vendor $vendor, array $data): VendorDocument
    {
        $document = $vendor->documents()->create($data + ['status' => $data['status'] ?? VendorDocumentStatus::SUBMITTED->value]);

        $this->audit->record(ProcurementAuditAction::UPDATE, $document, null, $document->toArray(), 'Dokumen vendor ditambahkan');

        return $document;
    }

    public function verifyDocument(VendorDocument $document, ?int $userId = null): VendorDocument
    {
        $document->forceFill([
            'status' => VendorDocumentStatus::VERIFIED->value,
            'verified_by' => $userId,
            'verified_at' => now(),
            'rejection_reason' => null,
        ])->save();

        $this->audit->record(ProcurementAuditAction::APPROVE, $document, null, ['status' => 'VERIFIED'], 'Dokumen vendor diverifikasi');

        return $document;
    }

    public function rejectDocument(VendorDocument $document, string $reason): VendorDocument
    {
        $document->forceFill([
            'status' => VendorDocumentStatus::REJECTED->value,
            'rejection_reason' => $reason,
        ])->save();

        $this->audit->record(ProcurementAuditAction::REJECT, $document, null, ['status' => 'REJECTED', 'reason' => $reason], $reason);

        return $document;
    }

    /** Pastikan vendor layak dipakai (dipanggil alur PO). */
    public function assertUsable(Vendor $vendor, bool $overridden = false): void
    {
        $this->control->assertVendorUsable($vendor, $overridden);
    }

    /** Dokumen yang akan kedaluwarsa dalam N hari (peringatan). */
    public function expiringDocuments(int $days): array
    {
        return VendorDocument::query()
            ->with('vendor:id,code,legal_name')
            ->expiringWithin($days)
            ->orderBy('expiry_date')
            ->get()
            ->all();
    }

    protected function transition(Vendor $vendor, VendorStatus $status, string $event, array $payload = []): Vendor
    {
        $old = $vendor->status instanceof \BackedEnum ? $vendor->status->value : $vendor->status;

        $vendor->forceFill(['status' => $status->value])->save();

        $this->audit->record(ProcurementAuditAction::UPDATE, $vendor, ['status' => $old], ['status' => $status->value], $event);
        $this->events->dispatch($event, $vendor, $payload + ['status' => $status->value]);

        return $vendor->fresh();
    }

    /** @param array<string, mixed> $data */
    protected function syncRelations(Vendor $vendor, array $data): void
    {
        if (array_key_exists('categories', $data) && is_array($data['categories'])) {
            $sync = [];
            foreach ($data['categories'] as $category) {
                if (is_array($category)) {
                    $sync[(int) $category['id']] = ['is_primary' => (bool) ($category['is_primary'] ?? false)];
                } else {
                    $sync[(int) $category] = ['is_primary' => false];
                }
            }
            $vendor->categories()->sync($sync);
        }
    }
}
