<?php

namespace App\Modules\SchoolOperations\Transportation\Http\Controllers;

use App\Modules\SchoolOperations\Transportation\Application\Services\{FleetService, FuelCostService, InspectionService, TransportationAnalyticsService};
use App\Modules\SchoolOperations\Transportation\Models\{VehicleDocument, VehicleProfile, VehicleRental};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Armada: kendaraan, perlengkapan, dokumen, BBM, inspeksi, sewa (§6-§10, §58, §62). */
class FleetController extends Controller
{
    public function __construct(
        private readonly FleetService $fleet,
        private readonly FuelCostService $costs,
        private readonly InspectionService $inspections,
        private readonly TransportationAnalyticsService $analytics,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('transportation.fleet.view') || abort(403);

        return response()->json(['data' => $this->fleet->vehicles($request->only(['status', 'campus_id', 'vehicle_type', 'ownership_type']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('transportation.fleet.manage') || abort(403);
        $validated = $request->validate([
            'vehicle_code' => ['nullable', 'string', 'max:40'],
            'asset_id' => ['nullable', 'integer'],
            'vehicle_type' => ['nullable', 'string'],
            'registration_number' => ['nullable', 'string', 'max:30'],
            'brand' => ['nullable', 'string'], 'model' => ['nullable', 'string'],
            'manufacture_year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'total_capacity' => ['nullable', 'integer', 'min:1'],
            'student_capacity' => ['nullable', 'integer', 'min:1'],
            'adult_capacity' => ['nullable', 'integer', 'min:1'],
            'standing_capacity' => ['nullable', 'integer', 'min:0'],
            'wheelchair_capacity' => ['nullable', 'integer', 'min:0'],
            'reserved_capacity' => ['nullable', 'integer', 'min:0'],
            'fuel_type' => ['nullable', 'string'], 'transmission' => ['nullable', 'string'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string'],
            'campus_id' => ['nullable', 'integer'],
            'ownership_type' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $vehicle = $this->fleet->createVehicle($validated);

        return response()->json(['data' => $this->fleet->vehicleSnapshot($vehicle)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.fleet.view') || abort(403);
        $vehicle = VehicleProfile::query()->findOrFail($id);

        return response()->json(['data' => $this->fleet->vehicleSnapshot($vehicle) + [
            'documents' => VehicleDocument::query()->where('vehicle_id', $vehicle->id)->get()->toArray(),
            'equipment_detail' => $vehicle->equipment()->get()->toArray(),
            'fuel_metrics' => $this->fleet->fuelMetrics($vehicle->id),
            'eligibility' => $this->fleet->eligibility($vehicle),
        ]]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.fleet.manage') || abort(403);
        $vehicle = VehicleProfile::query()->findOrFail($id);

        return response()->json(['data' => $this->fleet->vehicleSnapshot(
            $this->fleet->updateVehicle($vehicle, $request->all())
        )]);
    }

    public function setStatus(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.fleet.manage') || abort(403);
        $validated = $request->validate(['status' => ['required', 'string'], 'reason' => ['nullable', 'string']]);
        $vehicle = VehicleProfile::query()->findOrFail($id);

        return response()->json(['data' => $this->fleet->vehicleSnapshot(
            $this->fleet->setVehicleStatus($vehicle, $validated['status'], $validated['reason'] ?? null)
        )]);
    }

    public function available(Request $request): JsonResponse
    {
        $request->user()->can('transportation.fleet.view') || abort(403);

        return response()->json(['data' => $this->fleet->availableVehicles($request->only(['date', 'campus_id']))]);
    }

    public function syncEquipment(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.fleet.manage') || abort(403);
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.equipment_type' => ['required', 'string'],
            'items.*.status' => ['nullable', 'string'],
        ]);
        $vehicle = VehicleProfile::query()->findOrFail($id);

        return response()->json(['data' => $this->fleet->syncEquipment($vehicle, $validated['items'])]);
    }

    public function addDocument(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.fleet.documents') || abort(403);
        $validated = $request->validate([
            'document_type' => ['required', 'string'],
            'document_number' => ['nullable', 'string'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'file_path' => ['nullable', 'string'],
            'verification_status' => ['nullable', 'string'],
            'blocks_dispatch' => ['nullable', 'boolean'],
            'reminder_days' => ['nullable', 'integer', 'min:1'],
        ]);
        $vehicle = VehicleProfile::query()->findOrFail($id);

        return response()->json(['data' => $this->fleet->addDocument($vehicle, $validated)], 201);
    }

    public function verifyDocument(Request $request, int $documentId): JsonResponse
    {
        $request->user()->can('transportation.fleet.documents') || abort(403);
        $document = VehicleDocument::query()->findOrFail($documentId);

        return response()->json(['data' => $this->fleet->verifyDocument($document, (bool) $request->input('verified', true), $request->user()->id)]);
    }

    public function expiringDocuments(Request $request): JsonResponse
    {
        $request->user()->can('transportation.fleet.view') || abort(403);

        return response()->json(['data' => $this->fleet->expiringDocuments((int) $request->input('days', 30), $request->input('vehicle_id'))]);
    }

    public function logFuel(Request $request): JsonResponse
    {
        $request->user()->can('transportation.fleet.fuel') || abort(403);
        $validated = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'trip_id' => ['nullable', 'integer'],
            'driver_profile_id' => ['nullable', 'integer'],
            'fuel_quantity' => ['required', 'numeric', 'min:0.1'],
            'fuel_cost' => ['required', 'numeric', 'min:0'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'fuel_date' => ['nullable', 'date'],
            'station' => ['nullable', 'string'],
            'receipt_number' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string'],
        ]);

        $result = $this->fleet->logFuel($validated, $validated['idempotency_key'] ?? $request->header('Idempotency-Key'));

        if (($result['duplicate'] ?? false) === false) {
            $this->costs->costFromFuelLog(\App\Modules\SchoolOperations\Transportation\Models\VehicleFuelLog::query()
                ->findOrFail($result['result']['fuel_log_id']));
        }

        return response()->json(['data' => $result], 201);
    }

    public function fuelMetrics(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.fleet.view') || abort(403);

        return response()->json(['data' => $this->fleet->fuelMetrics($id, $request->input('from'), $request->input('to'))]);
    }

    public function inspections(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.inspection.view') || abort(403);
        $vehicle = VehicleProfile::query()->findOrFail($id);

        return response()->json(['data' => [
            'template' => $this->inspections->checklistTemplate(),
            'records' => $vehicle->inspections()->orderByDesc('id')->limit(20)->get()->toArray(),
        ]]);
    }

    public function recordInspection(Request $request): JsonResponse
    {
        $request->user()->can('transportation.inspection.perform') || abort(403);
        $validated = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'inspection_type' => ['required', 'string'],
            'inspection_date' => ['required', 'date'],
            'next_inspection_date' => ['nullable', 'date'],
            'result' => ['nullable', 'string'],
            'workshop' => ['nullable', 'string'],
            'cost' => ['nullable', 'numeric'],
            'findings' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->fleet->recordInspection($validated)], 201);
    }

    public function createRental(Request $request): JsonResponse
    {
        $request->user()->can('transportation.fleet.manage') || abort(403);
        $validated = $request->validate([
            'vehicle_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
            'vendor_name' => ['nullable', 'string'],
            'contract_number' => ['nullable', 'string'],
            'rental_start' => ['required', 'date'],
            'rental_end' => ['nullable', 'date'],
            'rental_cost' => ['nullable', 'numeric'],
            'cost_basis' => ['nullable', 'string'],
            'insurance_reference' => ['nullable', 'string'],
            'driver_arrangement' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->fleet->createRental($validated)], 201);
    }

    public function rentals(Request $request): JsonResponse
    {
        $request->user()->can('transportation.fleet.view') || abort(403);

        return response()->json(['data' => VehicleRental::query()->orderByDesc('id')->limit(100)->get()->toArray()]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $request->user()->can('transportation.analytics.view') || abort(403);

        return response()->json(['data' => $this->analytics->vehicleAnalytics($request->input('from'), $request->input('to'))]);
    }

    public function showFuelLogs(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.fleet.view') || abort(403);
        $vehicle = VehicleProfile::query()->findOrFail($id);

        return response()->json(['data' => $vehicle->fuelLogs()->orderByDesc('fuel_date')->limit(50)->get()->toArray()]);
    }
}
