<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Application\Services\DocumentService;
use App\Modules\Hrd\Domain\Contracts\EmployeeDocumentProviderInterface;
use App\Modules\Hrd\Models\HrDocumentType;
use App\Modules\Hrd\Models\HrEmployeeDocument;

/**
 * Dokumen pegawai untuk modul lain (§31, §52, §65).
 *
 * Hanya metadata — tanpa jalur berkas dan tanpa tautan unduh. Modul lain boleh
 * tahu *apakah* dokumen wajib sudah ada; isi berkasnya tetap hanya lewat endpoint
 * ber-otorisasi milik HRD.
 */
class DocumentModuleProvider implements EmployeeDocumentProviderInterface
{
    public function __construct(private readonly DocumentService $documents) {}

    public function documentsFor(int $employeeId, ?string $category = null): array
    {
        return HrEmployeeDocument::query()
            ->with('documentType:id,code,name,category')
            ->where('employee_id', $employeeId)
            ->when($category !== null, fn ($q) => $q->whereHas('documentType', fn ($type) => $type->where('category', $category)))
            ->get()
            ->map(fn (HrEmployeeDocument $document) => [
                'id' => (int) $document->getKey(),
                'type_code' => $document->documentType?->code,
                'type_name' => $document->documentType?->name,
                'category' => $document->documentType?->category,
                'document_number' => $document->document_number,
                'issue_date' => $document->issue_date?->toDateString(),
                'expiry_date' => $document->expiry_date?->toDateString(),
                'status' => $document->status,
                'verified' => (bool) $document->verified,
            ])
            ->all();
    }

    public function hasValidDocument(int $employeeId, string $documentTypeCode): bool
    {
        return HrEmployeeDocument::query()
            ->where('employee_id', $employeeId)
            ->whereHas('documentType', fn ($q) => $q->where('code', $documentTypeCode))
            ->where('verified', true)
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString()))
            ->exists();
    }

    public function expiring(int $days, ?int $organizationId = null): array
    {
        return $this->documents->expiring($days, $organizationId);
    }

    /** @return list<string> kode jenis dokumen wajib yang belum dimiliki */
    public function missingMandatoryTypes(int $employeeId): array
    {
        $mandatory = HrDocumentType::query()->where('is_mandatory', true)->where('is_active', true)->pluck('id', 'code');

        if ($mandatory->isEmpty()) {
            return [];
        }

        $owned = HrEmployeeDocument::query()
            ->where('employee_id', $employeeId)
            ->whereIn('document_type_id', $mandatory->values()->all())
            ->pluck('document_type_id')
            ->all();

        return $mandatory->filter(fn ($id) => ! in_array($id, $owned, true))->keys()->values()->all();
    }
}
