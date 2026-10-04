<?php

namespace App\Modules\SchoolOperations\Laundry\Http\Controllers;

use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryCatalogService;
use App\Modules\SchoolOperations\Laundry\Models\LaundryMachine;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPricingRule;
use App\Modules\SchoolOperations\Laundry\Models\LaundryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Master katalog: layanan (§3/§61), aturan harga (§4), mesin (§34). */
class CatalogController extends Controller
{
    public function __construct(private readonly LaundryCatalogService $catalog)
    {
    }

    // ── Layanan ────────────────────────────────────────────────────────────

    public function services(Request $request): JsonResponse
    {
        $request->user()->can('laundry.view') || abort(403);

        $services = LaundryService::query()
            ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $services]);
    }

    public function storeService(Request $request): JsonResponse
    {
        $request->user()->can('laundry.service.manage') || abort(403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:laundry_services,code'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'service_type' => ['nullable', 'string', 'max:30'],
            'pricing_model' => ['required', 'string', 'in:'.implode(',', (array) config('laundry.pricing_models'))],
            'unit' => ['nullable', 'string', 'max:20'],
            'processing_hours' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->catalog->createService($data, $request->user())], 201);
    }

    public function updateService(Request $request, LaundryService $service): JsonResponse
    {
        $request->user()->can('laundry.service.manage') || abort(403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'pricing_model' => ['sometimes', 'string', 'in:'.implode(',', (array) config('laundry.pricing_models'))],
            'unit' => ['sometimes', 'string', 'max:20'],
            'processing_hours' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->catalog->updateService($service, $data, $request->user())]);
    }

    // ── Aturan harga ───────────────────────────────────────────────────────

    public function pricingRules(Request $request): JsonResponse
    {
        $request->user()->can('laundry.view') || abort(403);

        $rules = LaundryPricingRule::query()
            ->with('service:id,code,name')
            ->when($request->integer('laundry_service_id') ?: null, fn ($q, $v) => $q->where('laundry_service_id', $v))
            ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $rules]);
    }

    public function storePricingRule(Request $request): JsonResponse
    {
        $request->user()->can('laundry.pricing.manage') || abort(403);

        $data = $request->validate([
            'laundry_service_id' => ['nullable', 'integer', 'exists:laundry_services,id'],
            'pricing_model' => ['required', 'string', 'in:'.implode(',', (array) config('laundry.pricing_models'))],
            'item_type' => ['nullable', 'string', 'max:30'],
            'unit_price' => ['required_without:fee_amount', 'nullable', 'numeric', 'min:0'],
            'minimum_quantity' => ['nullable', 'numeric', 'min:0'],
            'minimum_charge' => ['nullable', 'numeric', 'min:0'],
            'fee_type' => ['nullable', 'string', 'in:'.implode(',', array_keys((array) config('laundry.fee_types', [])))],
            'fee_amount' => ['nullable', 'numeric', 'min:0'],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'priority' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->catalog->createPricingRule($data, $request->user())], 201);
    }

    /** Rule 23: harga lama dinonaktifkan, tidak dihapus. */
    public function deactivatePricingRule(Request $request, LaundryPricingRule $pricingRule): JsonResponse
    {
        $request->user()->can('laundry.pricing.manage') || abort(403);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => $this->catalog->deactivatePricingRule($pricingRule, $request->user(), $data['reason'] ?? null)]);
    }

    // ── Mesin ──────────────────────────────────────────────────────────────

    public function machines(Request $request): JsonResponse
    {
        $request->user()->can('laundry.view') || abort(403);

        return response()->json(['data' => LaundryMachine::query()->orderBy('id')->get()]);
    }

    public function storeMachine(Request $request): JsonResponse
    {
        $request->user()->can('laundry.machine.manage') || abort(403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:laundry_machines,code'],
            'name' => ['required', 'string', 'max:100'],
            'machine_type' => ['required', 'string', 'in:WASHING,DRYING,IRONING'],
            'capacity_kg' => ['nullable', 'numeric', 'min:0'],
            'location' => ['nullable', 'string', 'max:100'],
            'asset_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->catalog->createMachine($data, $request->user())], 201);
    }

    public function updateMachine(Request $request, LaundryMachine $machine): JsonResponse
    {
        $request->user()->can('laundry.machine.manage') || abort(403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'capacity_kg' => ['nullable', 'numeric', 'min:0'],
            'location' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:OPERATIONAL,MAINTENANCE,OUT_OF_SERVICE'],
            'maintenance_state' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->catalog->updateMachine($machine, $data, $request->user())]);
    }
}
