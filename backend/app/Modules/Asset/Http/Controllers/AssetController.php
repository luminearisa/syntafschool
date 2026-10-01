<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\AssetAuditService;
use App\Modules\Asset\Application\Services\AssetLoanService;
use App\Modules\Asset\Application\Services\AssetRegisterService;
use App\Modules\Asset\Application\Services\AssetTimelineService;
use App\Modules\Asset\Application\Services\AssetValuationService;
use App\Modules\Asset\Application\Services\DamageReportService;
use App\Modules\Asset\Application\Services\InspectionService;
use App\Modules\Asset\Application\Services\MaintenanceService;
use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Application\Services\AssetDisposalService;
use App\Modules\Asset\Application\Services\AssetTransferService;
use App\Modules\Asset\Domain\Enums\AssetCondition;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\ValuationType;
use App\Modules\Asset\Http\Requests\StoreAssetRequest;
use App\Modules\Asset\Http\Requests\UpdateAssetRequest;
use App\Modules\Asset\Http\Resources\AssetResource;
use App\Modules\Asset\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetController extends AssetControllerBase
{
    public function __construct(
        private readonly AssetRegisterService $register,
        private readonly AssetAccessService $access,
        private readonly AssetTimelineService $timeline,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Asset::query()->with(['category:id,code,name', 'type:id,code,name', 'location:id,code,name,path']);

        $this->access->scopeAssets($query, $this->actor($request));

        $filters = $this->filters($request, [
            'q', 'status', 'condition', 'category_id', 'location_id', 'unit_id', 'custodian_id',
            'criticality', 'tag_status', 'warranty_before', 'archived',
        ]);

        if (! empty($filters['q'])) {
            $keyword = (string) $filters['q'];
            $query->where(function ($inner) use ($keyword): void {
                $inner->where('asset_code', 'like', "%$keyword%")
                    ->orWhere('name', 'like', "%$keyword%")
                    ->orWhere('serial_number', 'like', "%$keyword%")
                    ->orWhere('barcode', 'like', "%$keyword%");
            });
        }

        foreach (['status', 'condition', 'category_id', 'location_id', 'unit_id', 'custodian_id', 'criticality', 'tag_status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['warranty_before'])) {
            $query->whereNotNull('warranty_end')->where('warranty_end', '<=', $filters['warranty_before']);
        }

        if (isset($filters['archived']) && $filters['archived'] !== '') {
            $query->where('is_archived', (bool) $filters['archived']);
        }

        $sort = in_array($filters['sort'], ['asset_code', 'name', 'registered_at', 'acquisition_cost', 'next_maintenance_at'], true)
            ? $filters['sort']
            : 'asset_code';

        $page = $query->orderBy($sort, $filters['direction'])->paginate($filters['per_page']);

        return response()->json([
            'message' => 'Daftar aset.',
            'data' => AssetResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            ],
        ]);
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $asset = $this->register->register($this->actor($request), $request->validated());

        app(\App\Modules\Asset\Application\Services\PreventiveMaintenanceService::class)
            ->ensureSchedulesForAsset($this->actor($request), $asset);

        return $this->created(new AssetResource($asset->load(['category', 'type', 'location'])), 'Aset terdaftar.');
    }

    public function show(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        return $this->ok(new AssetResource($asset->load(['category', 'type', 'location'])));
    }

    public function update(UpdateAssetRequest $request, Asset $asset): JsonResponse
    {
        Gate::authorize('update', $asset);

        $updated = $this->register->update($this->actor($request), $asset, $request->validated());

        return $this->ok(new AssetResource($updated), 'Data aset diperbarui.');
    }

    public function changeStatus(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('update', $asset);

        $data = $request->validate([
            'status' => ['required', 'string', 'max:24'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $updated = $this->register->changeStatus(
            $this->actor($request),
            $asset,
            AssetStatus::from($data['status']),
            $data['reason'] ?? null,
        );

        return $this->ok(new AssetResource($updated), 'Status aset diperbarui.');
    }

    public function changeCondition(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('update', $asset);

        $data = $request->validate([
            'condition' => ['required', 'string', 'max:24'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $updated = $this->register->changeCondition(
            $this->actor($request),
            $asset,
            AssetCondition::from($data['condition']),
            $data['reason'] ?? null,
        );

        return $this->ok(new AssetResource($updated), 'Kondisi aset diperbarui.');
    }

    public function place(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('update', $asset);

        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'custodian_id' => ['nullable', 'integer'],
            'custodian_type' => ['nullable', 'string', 'max:24'],
        ]);

        $updated = $this->register->place(
            $this->actor($request),
            $asset,
            (int) $data['location_id'],
            $data['custodian_id'] ?? null,
            $data['custodian_type'] ?? null,
        );

        return $this->ok(new AssetResource($updated), 'Aset ditempatkan.');
    }

    public function archive(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('update', $asset);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        $updated = $this->register->changeStatus($this->actor($request), $asset, AssetStatus::RETIRED, $data['reason'] ?? 'Diarsipkan.');

        return $this->ok(new AssetResource($updated), 'Aset diarsipkan (barisnya tetap tersimpan untuk pemeriksaan).');
    }

    public function timeline(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        return $this->ok($this->timeline->forAsset($asset, (int) $request->input('limit', 200)), 'Garis waktu aset.');
    }

    public function history(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        return $this->ok($this->timeline->summary($asset), 'Riwayat lengkap aset.');
    }

    public function financial(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        return $this->ok($this->register->financialSummary($this->actor($request), $asset), 'Ringkasan keuangan aset.');
    }

    public function documents(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        return $this->ok($asset->documents()->orderByDesc('id')->get(), 'Dokumen aset.');
    }

    public function revalue(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('update', $asset);

        $data = $request->validate([
            'valuation_type' => ['required', 'string', 'max:32'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:200'],
            'effective_date' => ['nullable', 'date'],
            'document_reference' => ['nullable', 'string', 'max:200'],
        ]);

        $valuation = app(AssetValuationService::class)->revalue(
            $this->actor($request),
            $asset,
            ValuationType::from($data['valuation_type']),
            (string) $data['amount'],
            $data['reason'],
            $data,
        );

        return $this->created($valuation, 'Penilaian ulang tercatat.');
    }

    // ── Alur dari halaman detail aset ───────────────────────────────────────
    public function assign(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('update', $asset);

        $data = $request->validate([
            'custodian_id' => ['required', 'integer'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);

        $updated = $this->register->place(
            $this->actor($request),
            $asset,
            (int) ($data['location_id'] ?? $asset->location_id),
            $data['custodian_id'],
        );

        return $this->ok(new AssetResource($updated), 'Aset ditugaskan.');
    }

    public function transfer(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $data = $request->validate([
            'to_location_id' => ['required', 'integer', 'exists:locations,id'],
            'type' => ['nullable', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:200'],
            'to_custodian_id' => ['nullable', 'integer'],
            'to_unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        $transfer = app(AssetTransferService::class)->request($this->actor($request), $data, [$asset->id]);

        return $this->created($transfer, 'Permintaan pemindahan dibuat.');
    }

    public function loan(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $data = $request->validate([
            'borrower_id' => ['nullable', 'integer', 'exists:users,id'],
            'borrower_type' => ['nullable', 'string', 'max:24'],
            'purpose' => ['required', 'string', 'max:200'],
            'start_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'activity_reference' => ['nullable', 'string', 'max:80'],
        ]);

        $loan = app(AssetLoanService::class)->request($this->actor($request), $data, [$asset->id]);

        return $this->created($loan, 'Permintaan peminjaman dibuat.');
    }

    public function returnLoan(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $data = $request->validate([
            'condition' => ['required', 'string', 'max:24'],
            'damage_found' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $loan = \App\Modules\Asset\Models\AssetLoan::query()
            ->where('active_loan_id', $asset->id)
            ->orWhereHas('items', fn ($query) => $query->where('asset_id', $asset->id)->where('returned', false))
            ->latest('id')
            ->first();

        abort_if($loan === null, 404, 'Tidak ada peminjaman berjalan untuk aset ini.');

        $returned = app(AssetLoanService::class)->return($this->actor($request), $loan, $data);

        return $this->ok($returned, 'Aset dikembalikan.');
    }

    public function inspect(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $data = $request->validate([
            'inspection_type' => ['nullable', 'string', 'max:32'],
            'condition' => ['nullable', 'string', 'max:24'],
            'results' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $inspection = app(InspectionService::class)->schedule($this->actor($request), [
            'asset_id' => $asset->id,
            'inspection_type' => $data['inspection_type'] ?? 'ROUTINE',
            'scheduled_at' => now(),
        ]);

        if (! empty($data['results']) || isset($data['condition'])) {
            $inspection = app(InspectionService::class)->perform($this->actor($request), $inspection, $data['results'] ?? [], $data);
        }

        return $this->created($inspection, 'Inspeksi dicatat.');
    }

    public function maintenanceRequest(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'max:24'],
        ]);

        $created = app(MaintenanceService::class)->createRequest($this->actor($request), $data + ['asset_id' => $asset->id]);

        return $this->created($created, 'Permintaan pemeliharaan dibuat.');
    }

    public function dispose(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:32'],
            'method' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $disposal = app(AssetDisposalService::class)->submit($this->actor($request), $data + ['asset_id' => $asset->id]);

        return $this->created($disposal, 'Permintaan penghapusan dibuat (belum menghapus apa pun).');
    }
}
