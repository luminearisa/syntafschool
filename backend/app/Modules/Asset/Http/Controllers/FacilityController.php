<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Application\Services\FacilityBookingService;
use App\Modules\Asset\Application\Services\FacilityService;
use App\Modules\Asset\Http\Requests\BookFacilityRequest;
use App\Modules\Asset\Http\Resources\FacilityResource;
use App\Modules\Asset\Models\Facility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class FacilityController extends AssetControllerBase
{
    public function __construct(
        private readonly FacilityService $facilities,
        private readonly FacilityBookingService $bookings,
        private readonly AssetAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Facility::query()->with(['type:id,code,name,category', 'location:id,code,name,path']);

        if (! $this->access->isFacilityManager($this->actor($request))) {
            $query->where('status', '!=', 'RETIRED');
        }

        foreach (['campus_id', 'location_id', 'facility_type_id', 'status', 'availability', 'condition'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('q')) {
            $keyword = (string) $request->input('q');
            $query->where(fn ($inner) => $inner->where('name', 'like', "%$keyword%")->orWhere('facility_code', 'like', "%$keyword%"));
        }

        $page = $query->orderBy('facility_code')->paginate(min(100, max(1, (int) $request->input('per_page', 25))));

        return response()->json([
            'message' => 'Daftar fasilitas.',
            'data' => FacilityResource::collection($page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'facility_code' => ['nullable', 'string', 'max:60', 'unique:facilities,facility_code'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
            'facility_type_id' => ['nullable', 'integer', 'exists:facility_types,id'],
            'capacity_total' => ['nullable', 'integer', 'min:0'],
            'capacity_normal' => ['nullable', 'integer', 'min:0'],
            'capacity_exam' => ['nullable', 'integer', 'min:0'],
            'capacity_event' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'string', 'max:24'],
            'manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'responsible_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'min_booking_minutes' => ['nullable', 'integer', 'min:1'],
            'max_booking_minutes' => ['nullable', 'integer', 'min:1'],
            'advance_booking_days' => ['nullable', 'integer', 'min:0'],
            'requires_approval' => ['nullable', 'boolean'],
            'allowed_roles' => ['nullable', 'array'],
            'operating_hours' => ['nullable', 'array'],
            'description' => ['nullable', 'string'],
        ]);

        return $this->created(new FacilityResource($this->facilities->create($this->actor($request), $data)), 'Fasilitas dibuat.');
    }

    public function show(Request $request, Facility $facility): JsonResponse
    {
        Gate::authorize('view', $facility);

        return $this->ok(new FacilityResource($facility->load(['type', 'location', 'operatingHours', 'blackoutPeriods'])));
    }

    public function update(Request $request, Facility $facility): JsonResponse
    {
        Gate::authorize('update', $facility);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'capacity_total' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'capacity_normal' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'capacity_exam' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'capacity_event' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', 'max:24'],
            'availability' => ['sometimes', 'string', 'max:24'],
            'condition' => ['sometimes', 'string', 'max:24'],
            'manager_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'responsible_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:units,id'],
            'min_booking_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_booking_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'advance_booking_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'requires_approval' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        return $this->ok(new FacilityResource($this->facilities->update($this->actor($request), $facility, $data)), 'Fasilitas diperbarui.');
    }

    public function availability(Request $request, Facility $facility): JsonResponse
    {
        Gate::authorize('view', $facility);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'date' => ['nullable', 'date'],
        ]);

        if (isset($data['date'])) {
            return $this->ok($this->bookings->availability($facility, $data['date']), 'Ketersediaan fasilitas.');
        }

        $from = Carbon::parse($data['from'] ?? now());
        $to = Carbon::parse($data['to'] ?? now()->addDay());

        return $this->ok($this->facilities->availability($facility, $from, $to), 'Ketersediaan fasilitas.');
    }

    public function utilization(Request $request, Facility $facility): JsonResponse
    {
        Gate::authorize('view', $facility);

        $from = Carbon::parse($request->input('from', now()->startOfMonth()->toDateString()));
        $to = Carbon::parse($request->input('to', now()->toDateString()));

        return $this->ok($this->facilities->utilization($facility, $from, $to), 'Pemanfaatan fasilitas.');
    }

    public function book(BookFacilityRequest $request, Facility $facility): JsonResponse
    {
        $booking = $this->bookings->book($this->actor($request), $facility, $request->validated());

        return $this->created($booking, 'Booking dibuat.');
    }

    public function close(Request $request, Facility $facility): JsonResponse
    {
        Gate::authorize('update', $facility);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $result = $this->facilities->close(
            $this->actor($request),
            $facility,
            $data['reason'],
            isset($data['from']) ? Carbon::parse($data['from']) : null,
            isset($data['to']) ? Carbon::parse($data['to']) : null,
        );

        return $this->ok($result, 'Fasilitas ditutup; booking terdampak dilaporkan dan diberi tahu.');
    }

    public function reopen(Request $request, Facility $facility): JsonResponse
    {
        Gate::authorize('update', $facility);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        return $this->ok($this->facilities->reopen($this->actor($request), $facility, $data['reason'] ?? null), 'Fasilitas dibuka kembali.');
    }

    public function addBlackout(Request $request, Facility $facility): JsonResponse
    {
        Gate::authorize('update', $facility);

        $data = $request->validate([
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->created(
            $this->facilities->addBlackout($this->actor($request), $facility, Carbon::parse($data['start_at']), Carbon::parse($data['end_at']), $data['reason'] ?? null),
            'Periode hitam ditambahkan.',
        );
    }

    public function setOperatingHours(Request $request, Facility $facility): JsonResponse
    {
        Gate::authorize('update', $facility);

        $data = $request->validate([
            'hours' => ['required', 'array'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'hours.*.open_time' => ['nullable', 'string', 'max:5'],
            'hours.*.close_time' => ['nullable', 'string', 'max:5'],
            'hours.*.is_closed' => ['nullable', 'boolean'],
        ]);

        $facility->operatingHours()->delete();

        foreach ($data['hours'] as $hour) {
            $facility->operatingHours()->create($hour);
        }

        return $this->ok(new FacilityResource($facility->refresh()->load('operatingHours')), 'Jam operasional diperbarui.');
    }
}
