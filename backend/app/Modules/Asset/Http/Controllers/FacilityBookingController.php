<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Application\Services\FacilityBookingService;
use App\Modules\Asset\Models\FacilityBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FacilityBookingController extends AssetControllerBase
{
    public function __construct(
        private readonly FacilityBookingService $bookings,
        private readonly AssetAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = FacilityBooking::query()->with(['facility:id,facility_code,name', 'location:id,name', 'requester:id,name']);

        if (! $this->access->isFacilityManager($this->actor($request))) {
            $query->where('requested_by', $this->actor($request)->id);
        }

        foreach (['facility_id', 'status', 'source'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('from')) {
            $query->where('start_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('start_at', '<=', $request->input('to'));
        }

        return $this->paginated($query->orderByDesc('start_at')->paginate(min(100, max(1, (int) $request->input('per_page', 25)))), 'Daftar booking fasilitas.');
    }

    public function show(Request $request, FacilityBooking $booking): JsonResponse
    {
        Gate::authorize('view', $booking);

        return $this->ok($booking->load(['facility', 'location', 'requester:id,name', 'approver:id,name']), 'Rincian booking.');
    }

    public function approve(Request $request, FacilityBooking $booking): JsonResponse
    {
        Gate::authorize('update', $booking);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:200']]);

        return $this->ok($this->bookings->approve($this->actor($request), $booking, $data['notes'] ?? null), 'Booking disetujui.');
    }

    public function reject(Request $request, FacilityBooking $booking): JsonResponse
    {
        Gate::authorize('update', $booking);

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->ok($this->bookings->reject($this->actor($request), $booking, $data['reason']), 'Booking ditolak.');
    }

    public function checkIn(Request $request, FacilityBooking $booking): JsonResponse
    {
        Gate::authorize('view', $booking);

        return $this->ok($this->bookings->checkIn($this->actor($request), $booking), 'Pemakaian fasilitas dimulai.');
    }

    public function complete(Request $request, FacilityBooking $booking): JsonResponse
    {
        Gate::authorize('update', $booking);

        return $this->ok($this->bookings->complete($this->actor($request), $booking), 'Pemakaian fasilitas selesai.');
    }

    public function cancel(Request $request, FacilityBooking $booking): JsonResponse
    {
        Gate::authorize('update', $booking);

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->ok($this->bookings->cancel($this->actor($request), $booking, $data['reason']), 'Booking dibatalkan.');
    }
}
