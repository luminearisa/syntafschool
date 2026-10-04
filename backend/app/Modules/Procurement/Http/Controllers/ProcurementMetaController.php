<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Procurement\Application\Services\IntegrationService;
use App\Modules\Procurement\Application\Services\ProcurementAnalyticsService;
use App\Modules\Procurement\Domain\Enums\ContractStatus;
use App\Modules\Procurement\Domain\Enums\ExceptionStatus;
use App\Modules\Procurement\Domain\Enums\ExceptionType;
use App\Modules\Procurement\Domain\Enums\InvoiceStatus;
use App\Modules\Procurement\Domain\Enums\MatchStatus;
use App\Modules\Procurement\Domain\Enums\ProcurementItemType;
use App\Modules\Procurement\Domain\Enums\ProcurementMethod;
use App\Modules\Procurement\Domain\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Domain\Enums\PurchaseRequestPriority;
use App\Modules\Procurement\Domain\Enums\PurchaseRequestStatus;
use App\Modules\Procurement\Domain\Enums\RfqStatus;
use App\Modules\Procurement\Domain\Enums\VendorClassification;
use App\Modules\Procurement\Domain\Enums\VendorDocumentType;
use App\Modules\Procurement\Domain\Enums\VendorRiskLevel;
use App\Modules\Procurement\Domain\Enums\VendorStatus;
use App\Modules\Procurement\Domain\Enums\VendorTier;
use App\Modules\Procurement\Domain\Enums\VendorType;
use App\Modules\Procurement\Models\Category;
use App\Modules\Procurement\Models\Item;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Konteks, metadata enum, dasbor, dan kesehatan modul Procurement. */
class ProcurementMetaController extends BaseApiController
{
    public function __construct(
        private readonly ProcurementAnalyticsService $analytics,
        private readonly IntegrationService $integrations,
    ) {
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'user' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'roles' => method_exists($user, 'getRoleNames') ? $user->getRoleNames() : [],
                'permissions' => method_exists($user, 'getAllPermissions')
                    ? $user->getAllPermissions()->pluck('name')
                    : [],
            ],
        ]);
    }

    /** Metadata enum untuk dropdown frontend. */
    public function metadata(): JsonResponse
    {
        return $this->successResponse([
            'vendor_type' => VendorType::options(),
            'vendor_status' => VendorStatus::options(),
            'vendor_tier' => VendorTier::options(),
            'vendor_classification' => VendorClassification::options(),
            'vendor_risk_level' => VendorRiskLevel::options(),
            'vendor_document_type' => VendorDocumentType::options(),
            'item_type' => ProcurementItemType::options(),
            'method' => ProcurementMethod::options(),
            'pr_status' => PurchaseRequestStatus::options(),
            'pr_priority' => PurchaseRequestPriority::options(),
            'rfq_status' => RfqStatus::options(),
            'po_status' => PurchaseOrderStatus::options(),
            'invoice_status' => InvoiceStatus::options(),
            'match_status' => MatchStatus::options(),
            'exception_type' => ExceptionType::options(),
            'exception_status' => ExceptionStatus::options(),
            'contract_status' => ContractStatus::options(),
        ]);
    }

    public function dashboard(): JsonResponse
    {
        return $this->successResponse($this->analytics->dashboard());
    }

    /** Status pemasangan modul integrasi (untuk UI peringatan). */
    public function health(): JsonResponse
    {
        return $this->successResponse([
            'providers' => [
                'accounting' => $this->integrations->isInstalled('ACCOUNTING'),
                'budget' => $this->integrations->isInstalled('BUDGET'),
                'inventory' => $this->integrations->isInstalled('INVENTORY'),
                'asset' => $this->integrations->isInstalled('ASSET'),
                'workflow' => $this->integrations->isInstalled('WORKFLOW'),
                'notification' => $this->integrations->isInstalled('NOTIFICATION'),
            ],
        ]);
    }

    /** Opsi untuk SearchSelect (vendor, item, kategori). */
    public function options(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', 'vendor');
        $term = $request->query('search');

        $data = match ($type) {
            'item' => Item::query()->active()->search($term)->limit(20)->get(['id', 'sku', 'name', 'unit', 'estimated_price']),
            'category' => Category::query()->active()->when($term, fn ($q) => $q->where('name', 'like', "%{$term}%"))->limit(20)->get(['id', 'code', 'name']),
            default => Vendor::query()->usable()->search($term)->limit(20)->get(['id', 'code', 'legal_name']),
        };

        return $this->successResponse($data);
    }
}
