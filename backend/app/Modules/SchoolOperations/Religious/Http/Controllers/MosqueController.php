<?php

namespace App\Modules\SchoolOperations\Religious\Http\Controllers;

use App\Modules\SchoolOperations\Religious\Application\Services\{MosqueBookingService, MosqueOperationsService, MosqueService};
use App\Modules\SchoolOperations\Religious\Models\{Mosque, MosqueArea, MosqueAssetAssignment, MosqueBooking, MosqueCleaningTask, MosqueMaintenanceRequest};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Masjid, area, akses, perlengkapan, aset, pemesanan, dan operasional (§5-§9, §59-§63). */
class MosqueController extends Controller
{
    public function __construct(
        private readonly MosqueService $mosques,
        private readonly MosqueBookingService $bookings,
        private readonly MosqueOperationsService $operations,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('religious.mosque.view') || abort(403);

        return response()->json(['data' => $this->mosques->list($request->only(['campus_id', 'status', 'term', 'limit']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'campus_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'gender_access' => ['nullable', 'string', 'in:MIXED,MEN,WOMEN'],
            'operational_status' => ['nullable', 'string'],
            'latitude' => ['nullable'], 'longitude' => ['nullable'],
            'contact_person' => ['nullable', 'string'], 'contact_phone' => ['nullable', 'string'],
            'open_24h' => ['nullable', 'boolean'],
            'areas' => ['nullable', 'array'],
            'areas.*.name' => ['required_with:areas', 'string'],
            'areas.*.area_type' => ['nullable', 'string'],
            'areas.*.capacity' => ['nullable', 'integer'],
        ]);

        $mosque = $this->mosques->create($validated);

        foreach ((array) $request->input('areas', []) as $area) {
            $this->mosques->addArea($mosque, $area);
        }

        return response()->json(['data' => $this->mosques->overview($mosque->getKey())], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.mosque.view') || abort(403);

        return response()->json(['data' => $this->mosques->overview($id)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);
        $mosque = Mosque::query()->findOrFail($id);
        $mosque = $this->mosques->update($mosque, $request->validate([
            'name' => ['sometimes', 'string'], 'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string'], 'capacity' => ['nullable', 'integer'],
            'operational_status' => ['nullable', 'string'], 'gender_access' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]));

        return response()->json(['data' => $this->mosques->summary($mosque)]);
    }

    public function areas(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.mosque.view') || abort(403);

        return response()->json(['data' => $this->mosques->areas($id, $request->only(['bookable', 'area_type']))]);
    }

    public function storeArea(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);
        $mosque = Mosque::query()->findOrFail($id);
        $area = $this->mosques->addArea($mosque, $request->validate([
            'name' => ['required', 'string'], 'code' => ['nullable', 'string'],
            'area_type' => ['nullable', 'string'], 'capacity' => ['nullable', 'integer'],
            'gender' => ['nullable', 'string'], 'floor' => ['nullable', 'string'],
            'bookable' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string'],
        ]));

        return response()->json(['data' => $area->only(['id', 'code', 'name', 'area_type', 'capacity', 'bookable'])], 201);
    }

    public function storeAccessRule(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);
        $mosque = Mosque::query()->findOrFail($id);
        $rule = $this->mosques->addAccessRule($mosque, $request->validate([
            'area_id' => ['nullable', 'integer'], 'access_policy' => ['required', 'string'],
            'audience' => ['nullable', 'string'], 'gender' => ['nullable', 'string'],
            'day_of_week' => ['nullable', 'string'], 'time_from' => ['nullable'],
            'time_to' => ['nullable'], 'valid_from' => ['nullable'], 'valid_until' => ['nullable'],
            'requires_escort' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string'],
        ]));

        return response()->json(['data' => $rule->only(['id', 'access_policy', 'audience', 'status'])], 201);
    }

    /** Evaluasi izin akses masjid/area (§9). */
    public function accessCheck(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.mosque.view') || abort(403);

        return response()->json(['data' => $this->mosques->accessAllowed(
            $id,
            $request->integer('area_id') ?: null,
            (string) $request->input('audience', 'STUDENT'),
            $request->input('at'),
        )]);
    }

    public function storeEquipment(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);
        $mosque = Mosque::query()->findOrFail($id);
        $equipment = $this->mosques->addEquipment($mosque, $request->validate([
            'name' => ['required', 'string'], 'area_id' => ['nullable', 'integer'],
            'asset_id' => ['nullable', 'integer'], 'code' => ['nullable', 'string'],
            'equipment_type' => ['nullable', 'string'], 'quantity' => ['nullable', 'integer'],
            'condition' => ['nullable', 'string'], 'notes' => ['nullable', 'string'],
        ]));

        return response()->json(['data' => $equipment->only(['id', 'code', 'name', 'equipment_type', 'quantity', 'condition'])], 201);
    }

    public function assignAsset(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);
        $mosque = Mosque::query()->findOrFail($id);
        $assignment = $this->mosques->assignAsset($mosque, $request->integer('asset_id'), $request->validate([
            'area_id' => ['nullable', 'integer'], 'quantity' => ['nullable', 'integer'],
            'condition' => ['nullable', 'string'], 'assigned_at' => ['nullable'],
            'notes' => ['nullable', 'string'],
        ]));

        return response()->json(['data' => $assignment->only(['id', 'asset_id', 'area_id', 'status'])], 201);
    }

    public function releaseAsset(Request $request, int $assignmentId): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);
        $assignment = MosqueAssetAssignment::query()->findOrFail($assignmentId);

        return response()->json(['data' => $this->mosques->releaseAsset($assignment, $request->input('reason'))]);
    }

    public function saveDisplay(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);
        $mosque = Mosque::query()->findOrFail($id);

        return response()->json(['data' => $this->mosques->saveDisplaySetting($mosque, $request->all())]);
    }

    // ── Pemesanan (§59, §60) ────────────────────────────────────────

    public function bookings(Request $request): JsonResponse
    {
        $request->user()->can('religious.booking.view') || abort(403);

        return response()->json(['data' => $this->bookings->list($request->only(['mosque_id', 'status', 'from', 'to', 'limit']))]);
    }

    public function storeBooking(Request $request): JsonResponse
    {
        $request->user()->can('religious.booking.request') || abort(403);
        $booking = $this->bookings->request($request->validate([
            'mosque_id' => ['required', 'integer'], 'area_id' => ['nullable', 'integer'],
            'title' => ['required', 'string'], 'purpose' => ['nullable', 'string'],
            'booking_type' => ['nullable', 'string'], 'requester_name' => ['nullable', 'string'],
            'contact_phone' => ['nullable', 'string'], 'starts_at' => ['required'],
            'ends_at' => ['required'], 'expected_attendees' => ['nullable', 'integer'],
            'require_approval' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string'],
        ]));

        return response()->json(['data' => $booking], 201);
    }

    public function approveBooking(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.booking.approve') || abort(403);

        return response()->json(['data' => $this->bookings->approve($id, $request->only(['facility_id', 'note']))]);
    }

    public function rejectBooking(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.booking.approve') || abort(403);
        $validated = $request->validate(['reason' => ['required', 'string']]);

        return response()->json(['data' => $this->bookings->reject($id, $validated['reason'])]);
    }

    public function bookingAvailability(Request $request): JsonResponse
    {
        $request->user()->can('religious.booking.view') || abort(403);

        return response()->json(['data' => $this->bookings->availability(
            $request->integer('mosque_id'),
            $request->integer('area_id') ?: null,
            (string) $request->input('date', now()->toDateString()),
        )]);
    }

    // ── Operasional (§61-§63) ──────────────────────────────────────

    public function storeCleaningTask(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);

        return response()->json(['data' => $this->operations->createCleaningTask($id, $request->all())], 201);
    }

    public function completeCleaningTask(Request $request, int $taskId): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);

        return response()->json(['data' => $this->operations->completeCleaning($taskId, $request->all())]);
    }

    public function storeMaintenance(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);

        return response()->json(['data' => $this->operations->reportMaintenance($id, $request->validate([
            'title' => ['required', 'string'], 'area_id' => ['nullable', 'integer'],
            'asset_id' => ['nullable', 'integer'], 'equipment_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string'], 'severity' => ['nullable', 'string'],
            'description' => ['nullable', 'string'], 'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    public function openMaintenance(Request $request): JsonResponse
    {
        $request->user()->can('religious.mosque.view') || abort(403);

        return response()->json(['data' => $this->operations->openMaintenance($request->integer('mosque_id') ?: null)]);
    }

    public function resolveMaintenance(Request $request, int $requestId): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);

        return response()->json(['data' => $this->operations->resolveMaintenance($requestId, $request->all())]);
    }

    public function storeDonation(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);

        return response()->json(['data' => $this->operations->openDonationBox($id, $request->validate([
            'box_code' => ['required', 'string'], 'collection_date' => ['nullable'],
            'currency' => ['nullable', 'string'], 'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    public function closeDonation(Request $request, int $collectionId): JsonResponse
    {
        $request->user()->can('religious.mosque.manage') || abort(403);

        return response()->json(['data' => $this->operations->closeDonationBox($collectionId, $request->validate([
            'amount' => ['required', 'numeric', 'min:0'], 'verified_by' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ]))]);
    }

    public function operationalSummary(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.report.view') || abort(403);

        return response()->json(['data' => $this->operations->operationalSummary(
            $id,
            (string) $request->input('from', now()->startOfMonth()->toDateString()),
            (string) $request->input('to', now()->toDateString()),
        )]);
    }
}
