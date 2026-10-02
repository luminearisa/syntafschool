<?php

namespace App\Modules\SchoolOperations\Transportation\Http\Controllers;

use App\Modules\SchoolOperations\Transportation\Application\Services\{BoardingService, TransportationAssignmentService, TransportationRequestService};
use App\Modules\SchoolOperations\Transportation\Domain\Contracts\StudentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Siswa & wali: profil, permintaan, penugasan, daftar tunggu, penjemput (§22-§28). */
class StudentTransportationController extends Controller
{
    public function __construct(
        private readonly TransportationRequestService $requests,
        private readonly TransportationAssignmentService $assignments,
        private readonly BoardingService $boarding,
        private readonly StudentGatewayInterface $students,
    ) {
    }

    public function requests(Request $request): JsonResponse
    {
        $request->user()->can('transportation.request.view') || abort(403);

        return response()->json(['data' => $this->requests->requests($request->only(['status', 'student_id', 'route_id', 'limit']))]);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $request->user()->can('transportation.request.create') || abort(403);
        $validated = $request->validate([
            'request_type' => ['required', 'string'],
            'student_id' => ['required', 'integer'],
            'route_id' => ['nullable', 'integer'],
            'morning_route_id' => ['nullable', 'integer'],
            'afternoon_route_id' => ['nullable', 'integer'],
            'pickup_stop_id' => ['nullable', 'integer'],
            'dropoff_stop_id' => ['nullable', 'integer'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
            'priority_level' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
            'special_requirement' => ['nullable', 'string'],
            'requested_by_type' => ['nullable', 'string'],
        ]);

        $result = $this->requests->submit($validated, $request->user());

        return response()->json(['data' => $result], 201);
    }

    public function approveRequest(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.request.approve') || abort(403);
        $result = $this->requests->approve($id, $request->user()->id, $request->only(['allow_override']));

        return response()->json(['data' => $result], ($result['approved'] ?? false) ? 200 : 422);
    }

    public function rejectRequest(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.request.approve') || abort(403);
        $validated = $request->validate(['reason' => ['required', 'string']]);

        return response()->json(['data' => $this->requests->reject($id, $validated['reason'], $request->user()->id)]);
    }

    public function assignments(Request $request): JsonResponse
    {
        $request->user()->can('transportation.student.view') || abort(403);

        return response()->json(['data' => $this->assignments->assignments($request->only(['student_id', 'route_id', 'status', 'campus_id', 'limit']))]);
    }

    public function storeAssignment(Request $request): JsonResponse
    {
        $request->user()->can('transportation.student.manage') || abort(403);
        $validated = $request->validate([
            'student_id' => ['required', 'integer'],
            'route_id' => ['nullable', 'integer'],
            'morning_route_id' => ['nullable', 'integer'],
            'afternoon_route_id' => ['nullable', 'integer'],
            'pickup_stop_id' => ['nullable', 'integer'],
            'dropoff_stop_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
            'transport_mode' => ['nullable', 'string'],
            'billable' => ['nullable', 'boolean'],
            'fee_amount' => ['nullable', 'numeric'],
            'allow_override' => ['nullable', 'boolean'],
            'allow_multi_service' => ['nullable', 'boolean'],
        ]);

        $result = $this->assignments->create($validated + ['assigned_by' => $request->user()->id]);

        return response()->json(['data' => $result], ($result['created'] ?? false) ? 201 : 422);
    }

    public function suspendAssignment(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.student.manage') || abort(403);
        $validated = $request->validate(['reason' => ['required', 'string'], 'until' => ['nullable', 'date']]);
        $assignment = \App\Modules\SchoolOperations\Transportation\Models\TransportationAssignment::query()->findOrFail($id);

        return response()->json(['data' => $this->assignments->suspend($assignment, $validated['reason'], $validated['until'] ?? null)]);
    }

    public function resumeAssignment(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.student.manage') || abort(403);
        $assignment = \App\Modules\SchoolOperations\Transportation\Models\TransportationAssignment::query()->findOrFail($id);

        return response()->json(['data' => $this->assignments->resume($assignment, $request->input('from'))]);
    }

    public function changeAssignmentRoute(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.student.manage') || abort(403);
        $validated = $request->validate([
            'route_id' => ['required', 'integer'],
            'pickup_stop_id' => ['nullable', 'integer'],
            'dropoff_stop_id' => ['nullable', 'integer'],
            'allow_override' => ['nullable', 'boolean'],
        ]);
        $assignment = \App\Modules\SchoolOperations\Transportation\Models\TransportationAssignment::query()->findOrFail($id);

        return response()->json(['data' => $this->assignments->changeRoute(
            $assignment, (int) $validated['route_id'], $validated['pickup_stop_id'] ?? null, $validated['dropoff_stop_id'] ?? null,
            $validated
        )]);
    }

    public function profile(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('transportation.student.view') || abort(403);

        return response()->json(['data' => [
            'profile' => $this->assignments->profile($studentId),
            'student' => $this->students->snapshot($studentId),
        ]]);
    }

    public function upsertProfile(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('transportation.student.manage') || abort(403);

        return response()->json(['data' => $this->assignments->upsertProfile($studentId, $request->all())]);
    }

    /** Penjemput berwenang (§24). */
    public function addAuthorizedPickup(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('transportation.student.manage') || abort(403);
        $validated = $request->validate([
            'name' => ['required', 'string'],
            'relationship' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'identity_type' => ['nullable', 'string'],
            'identity_number' => ['nullable', 'string'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->assignments->addAuthorizedPickupPerson($studentId, $validated)], 201);
    }

    public function revokeAuthorizedPickup(Request $request, int $personId): JsonResponse
    {
        $request->user()->can('transportation.student.manage') || abort(403);
        $person = \App\Modules\SchoolOperations\Transportation\Models\AuthorizedPickupPerson::query()->findOrFail($personId);

        return response()->json(['data' => $this->assignments->revokeAuthorizedPickupPerson($person, (string) $request->input('reason', 'Dicabut oleh admin.'))]);
    }

    public function createParentAuthorization(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('transportation.student.manage') || abort(403);

        return response()->json(['data' => $this->assignments->createParentAuthorization($studentId, $request->all())], 201);
    }

    /** Daftar tunggu (§28). */
    public function waitlist(Request $request): JsonResponse
    {
        $request->user()->can('transportation.waitlist.manage') || abort(403);

        return response()->json(['data' => $this->requests->waitlist($request->only(['route_id', 'status']))]);
    }

    public function evaluateWaitlist(Request $request): JsonResponse
    {
        $request->user()->can('transportation.waitlist.manage') || abort(403);

        return response()->json(['data' => $this->requests->evaluateWaitlist($request->input('route_id'), $request->input('seats'))]);
    }

    public function acceptOffer(Request $request, int $waitlistId): JsonResponse
    {
        $request->user()->can('transportation.waitlist.manage') || abort(403);
        $result = $this->requests->acceptOffer($waitlistId, $request->user()->id);

        return response()->json(['data' => $result], ($result['accepted'] ?? false) ? 200 : 422);
    }

    public function declineOffer(Request $request, int $waitlistId): JsonResponse
    {
        $request->user()->can('transportation.waitlist.manage') || abort(403);

        return response()->json(['data' => $this->requests->declineOffer($waitlistId, $request->input('reason'))]);
    }

    /** Token QR boarding untuk siswa (§38). */
    public function issueBoardingToken(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('transportation.student.view') || abort(403);
        $validated = $request->validate(['trip_id' => ['required', 'integer'], 'ttl_minutes' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->boarding->issueBoardingToken($studentId, (int) $validated['trip_id'], $validated)]);
    }
}
