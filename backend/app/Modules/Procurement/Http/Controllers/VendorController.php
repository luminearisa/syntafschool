<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Procurement\Application\Services\VendorPerformanceService;
use App\Modules\Procurement\Application\Services\VendorService;
use App\Modules\Procurement\Domain\Enums\VendorClassification;
use App\Modules\Procurement\Domain\Enums\VendorDocumentStatus;
use App\Modules\Procurement\Domain\Enums\VendorDocumentType;
use App\Modules\Procurement\Domain\Enums\VendorRiskLevel;
use App\Modules\Procurement\Domain\Enums\VendorTier;
use App\Modules\Procurement\Domain\Enums\VendorType;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Models\VendorDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Vendor & onboarding (§4–§7). */
class VendorController extends BaseApiController
{
    public function __construct(
        private readonly VendorService $vendors,
        private readonly VendorPerformanceService $performance,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->vendors->paginate($request->only([
            'search', 'status', 'vendor_type', 'classification', 'tier', 'category_id', 'usable_only',
        ]), (int) $request->query('per_page', 20));

        return $this->successResponse($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $vendor = $this->vendors->create($data);

        return $this->createdResponse($vendor, 'Vendor berhasil dibuat');
    }

    public function show(Vendor $vendor): JsonResponse
    {
        return $this->successResponse($vendor->load([
            'contacts', 'addresses', 'bankAccounts', 'documents', 'categories', 'performanceRecords',
        ]));
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $this->validated($request, $vendor);

        return $this->successResponse($this->vendors->update($vendor, $data), 'Vendor diperbarui');
    }

    public function submit(Vendor $vendor): JsonResponse
    {
        return $this->successResponse($this->vendors->submit($vendor), 'Vendor diajukan untuk verifikasi');
    }

    public function verify(Request $request, Vendor $vendor): JsonResponse
    {
        return $this->successResponse($this->vendors->verify($vendor, $request->user()?->id), 'Vendor diverifikasi');
    }

    public function approve(Vendor $vendor): JsonResponse
    {
        return $this->successResponse($this->vendors->approve($vendor), 'Vendor diaktifkan');
    }

    public function reject(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->successResponse($this->vendors->reject($vendor, $data['reason']), 'Vendor ditolak');
    }

    public function suspend(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->successResponse($this->vendors->suspend($vendor, $data['reason']), 'Vendor ditangguhkan');
    }

    public function deactivate(Vendor $vendor): JsonResponse
    {
        return $this->successResponse($this->vendors->deactivate($vendor), 'Vendor dinonaktifkan');
    }

    public function classify(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'classification' => ['required', Rule::in(VendorClassification::values())],
            'tier' => ['nullable', Rule::in(VendorTier::values())],
            'risk_level' => ['nullable', Rule::in(VendorRiskLevel::values())],
        ]);

        $vendor = $this->vendors->classify(
            $vendor,
            VendorClassification::from($data['classification']),
            isset($data['tier']) ? VendorTier::from($data['tier']) : null,
            $data['risk_level'] ?? null,
        );

        return $this->successResponse($vendor, 'Klasifikasi vendor diperbarui');
    }

    public function storeDocument(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(VendorDocumentType::values())],
            'name' => ['required', 'string', 'max:150'],
            'number' => ['nullable', 'string', 'max:80'],
            'issued_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'file_path' => ['nullable', 'string', 'max:255'],
            'is_mandatory' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->createdResponse($this->vendors->addDocument($vendor, $data), 'Dokumen vendor ditambahkan');
    }

    public function verifyDocument(Request $request, VendorDocument $document): JsonResponse
    {
        return $this->successResponse(
            $this->vendors->verifyDocument($document, $request->user()?->id),
            'Dokumen diverifikasi',
        );
    }

    public function rejectDocument(Request $request, VendorDocument $document): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->successResponse($this->vendors->rejectDocument($document, $data['reason']), 'Dokumen ditolak');
    }

    /** Hitung scorecard periode tertentu (§7). */
    public function performance(Request $request, Vendor $vendor): JsonResponse
    {
        $period = (string) $request->query('period', now()->subMonth()->format('Y-m'));

        return $this->successResponse($this->performance->compute($vendor, $period), 'Scorecard dihitung');
    }

    /** Dokumen yang akan kedaluwarsa (peringatan). */
    public function expiringDocuments(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);

        return $this->successResponse($this->vendors->expiringDocuments($days));
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Vendor $vendor = null): array
    {
        return $request->validate([
            'legal_name' => [$vendor === null ? 'required' : 'sometimes', 'string', 'max:180'],
            'trading_name' => ['nullable', 'string', 'max:180'],
            'vendor_type' => ['nullable', Rule::in(VendorType::values())],
            'entity_type' => ['nullable', Rule::in(['COMPANY', 'INDIVIDUAL'])],
            'npwp' => ['nullable', 'string', 'max:40'],
            'nib' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'max:150'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_terms' => ['nullable', 'string', 'max:60'],
            'tax_name' => ['nullable', 'string', 'max:60'],
            'tax_number' => ['nullable', 'string', 'max:40'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tier' => ['nullable', Rule::in(VendorTier::values())],
            'risk_level' => ['nullable', Rule::in(VendorRiskLevel::values())],
            'notes' => ['nullable', 'string'],
            'categories' => ['nullable', 'array'],
            'categories.*.id' => ['required_with:categories', 'integer', 'exists:proc_categories,id'],
            'categories.*.is_primary' => ['nullable', 'boolean'],
        ]);
    }
}
