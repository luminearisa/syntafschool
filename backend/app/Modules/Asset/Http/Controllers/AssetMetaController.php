<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Application\Services\AssetCategoryService;
use App\Modules\Asset\Application\Services\AssetSearchService;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetCustodian;
use App\Modules\Asset\Models\AssetType;
use App\Modules\Asset\Domain\Contracts\VendorProviderInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetMetaController extends AssetControllerBase
{
    public function __construct(
        private readonly AssetAccessService $access,
        private readonly AssetSearchService $search,
        private readonly AssetCategoryService $categories,
        private readonly VendorProviderInterface $vendors,
    ) {}

    /** Pilihan enum untuk layar — dibangkitkan dari enum, bukan disalin manual. */
    public function enums(Request $request): JsonResponse
    {
        $values = [
            'asset_status' => \App\Modules\Asset\Domain\Enums\AssetStatus::cases(),
            'asset_condition' => \App\Modules\Asset\Domain\Enums\AssetCondition::cases(),
            'asset_criticality' => \App\Modules\Asset\Domain\Enums\AssetCriticality::cases(),
            'acquisition_source' => \App\Modules\Asset\Domain\Enums\AssetAcquisitionSource::cases(),
            'tag_status' => \App\Modules\Asset\Domain\Enums\AssetTagStatus::cases(),
            'location_type' => \App\Modules\Asset\Domain\Enums\LocationType::cases(),
            'facility_status' => \App\Modules\Asset\Domain\Enums\FacilityStatus::cases(),
            'booking_status' => \App\Modules\Asset\Domain\Enums\BookingStatus::cases(),
            'booking_source' => \App\Modules\Asset\Domain\Enums\BookingSource::cases(),
            'loan_status' => \App\Modules\Asset\Domain\Enums\LoanStatus::cases(),
            'transfer_type' => \App\Modules\Asset\Domain\Enums\TransferType::cases(),
            'assignment_type' => \App\Modules\Asset\Domain\Enums\AssignmentType::cases(),
            'custodian_type' => \App\Modules\Asset\Domain\Enums\CustodianType::cases(),
            'maintenance_priority' => \App\Modules\Asset\Domain\Enums\MaintenancePriority::cases(),
            'maintenance_status' => \App\Modules\Asset\Domain\Enums\MaintenanceRequestStatus::cases(),
            'work_order_status' => \App\Modules\Asset\Domain\Enums\WorkOrderStatus::cases(),
            'work_order_type' => \App\Modules\Asset\Domain\Enums\WorkOrderType::cases(),
            'inspection_type' => \App\Modules\Asset\Domain\Enums\InspectionType::cases(),
            'inspection_status' => \App\Modules\Asset\Domain\Enums\InspectionStatus::cases(),
            'damage_severity' => \App\Modules\Asset\Domain\Enums\DamageSeverity::cases(),
            'disposal_reason' => \App\Modules\Asset\Domain\Enums\DisposalReason::cases(),
            'disposal_method' => \App\Modules\Asset\Domain\Enums\DisposalMethod::cases(),
            'disposal_status' => \App\Modules\Asset\Domain\Enums\DisposalStatus::cases(),
            'stock_opname_status' => \App\Modules\Asset\Domain\Enums\StockOpnameStatus::cases(),
            'scan_result' => \App\Modules\Asset\Domain\Enums\StockOpnameScanResult::cases(),
            'incident_type' => \App\Modules\Asset\Domain\Enums\IncidentType::cases(),
            'maintenance_frequency' => \App\Modules\Asset\Domain\Enums\MaintenanceFrequency::cases(),
            'replacement_recommendation' => \App\Modules\Asset\Domain\Enums\ReplacementRecommendation::cases(),
            'valuation_type' => \App\Modules\Asset\Domain\Enums\ValuationType::cases(),
        ];

        $payload = [];

        foreach ($values as $key => $cases) {
            $payload[$key] = array_map(fn ($case): array => [
                'value' => $case->value,
                'label' => method_exists($case, 'label') ? $case->label() : $case->value,
            ], $cases);
        }

        return $this->ok($payload, 'Pilihan enum modul Asset & Sarpras.');
    }

    public function categories(Request $request): JsonResponse
    {
        return $this->ok(
            AssetCategory::query()
                ->with(['children:id,parent_id,code,name', 'types:id,category_id,code,name,default_criticality,default_useful_life_months'])
                ->whereNull('parent_id')
                ->orderBy('code')
                ->get(),
            'Kategori & jenis aset.',
        );
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', 'exists:asset_categories,id'],
            'kind' => ['nullable', 'string', 'max:24'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'depreciation_method' => ['nullable', 'string', 'max:32'],
            'capitalization_threshold' => ['nullable', 'string', 'max:32'],
            'inspection_frequency_days' => ['nullable', 'integer', 'min:1'],
            'default_criticality' => ['nullable', 'string', 'max:24'],
            'is_consumable' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],
        ]);

        return $this->created($this->categories->createCategory($this->actor($request), $data), 'Kategori dibuat.');
    }

    public function storeType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:asset_categories,id'],
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'default_useful_life_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'default_criticality' => ['nullable', 'string', 'max:24'],
            'default_depreciation_method' => ['nullable', 'string', 'max:32'],
            'attributes' => ['nullable', 'array'],
            'attributes.*.code' => ['required_with:attributes', 'string', 'max:60'],
            'attributes.*.label' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
        ]);

        return $this->created($this->categories->createType($this->actor($request), $data), 'Jenis aset dibuat.');
    }

    public function custodians(Request $request): JsonResponse
    {
        $query = AssetCustodian::query()->with(['user:id,name', 'unit:id,name', 'location:id,name'])->where('is_active', true);

        if ($request->filled('q')) {
            $keyword = (string) $request->input('q');
            $query->where(fn ($inner) => $inner->where('name', 'like', "%$keyword%")->orWhere('employee_id', 'like', "%$keyword%"));
        }

        return $this->ok($query->orderBy('name')->limit(100)->get(), 'Daftar pemegang aset.');
    }

    public function storeCustodian(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'custodian_type' => ['required', 'string', 'max:24'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'employee_id' => ['nullable', 'string', 'max:60'],
            'position' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        return $this->created(AssetCustodian::query()->create($data), 'Pemegang aset dibuat.');
    }

    public function vendors(Request $request): JsonResponse
    {
        $keyword = (string) $request->input('q', '');

        return $this->ok($keyword === '' ? [] : $this->vendors->search($keyword, 20), 'Daftar vendor (dari modul vendor/keuangan).');
    }

    /** Pencarian global: kode, nama, seri, barcode, QR, lokasi, work order. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);

        return $this->ok($this->search->search($this->actor($request), $data['q']), 'Hasil pencarian.');
    }
}
