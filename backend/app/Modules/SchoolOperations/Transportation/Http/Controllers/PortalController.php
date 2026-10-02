<?php

namespace App\Modules\SchoolOperations\Transportation\Http\Controllers;

use App\Modules\SchoolOperations\Transportation\Application\Services\{BoardingService, TransportationAssignmentService, TransportationRequestService, TransportationScopeService};
use App\Modules\SchoolOperations\Transportation\Domain\Contracts\ParentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Portal wali & siswa (§73/§74): rute, halte, jadwal, trip hari ini, status
 * naik/turun, info pengemudi & kendaraan, serta pengajuan perubahan.
 * Isolasi data: wali hanya melihat anaknya (§87 Rule 14).
 */
class PortalController extends Controller
{
    public function __construct(
        private readonly TransportationScopeService $scope,
        private readonly TransportationAssignmentService $assignments,
        private readonly BoardingService $boarding,
        private readonly TransportationRequestService $requests,
        private readonly ParentGatewayInterface $parents,
    ) {
    }

    /** Daftar anak yang dapat dilihat pengguna portal. */
    public function children(Request $request): JsonResponse
    {
        $request->user()->can('transportation.portal.view') || abort(403);
        $user = $request->user();
        $childIds = $this->parents->childrenOfUser((int) $user->getKey());

        return response()->json(['data' => array_map(fn (int $studentId): array => [
            'student_id' => $studentId,
            'assignment' => $this->scope->assignmentsFor($user, $studentId),
        ], $childIds)]);
    }

    public function childOverview(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('transportation.portal.view') || abort(403);
        abort_unless($this->scope->canAccessStudent($request->user(), $studentId), 403, 'Anda hanya dapat melihat data anak Anda.');

        return response()->json(['data' => [
            'student_id' => $studentId,
            'assignments' => $this->scope->assignmentsFor($request->user(), $studentId),
            'profile' => $this->assignments->profile($studentId),
            'today' => $this->boarding->studentTodayStatus($studentId, $request->input('date')),
        ]]);
    }

    public function today(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('transportation.portal.view') || abort(403);
        abort_unless($this->scope->canAccessStudent($request->user(), $studentId), 403);

        return response()->json(['data' => $this->boarding->studentTodayStatus($studentId, $request->input('date'))]);
    }

    public function history(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('transportation.portal.view') || abort(403);
        abort_unless($this->scope->canAccessStudent($request->user(), $studentId), 403);

        return response()->json(['data' => $this->scope->studentTrips(
            $request->user(), $studentId, $request->input('from'), $request->input('to')
        )]);
    }

    /** Pengajuan permintaan dari portal wali (dibatasi ke anaknya sendiri). */
    public function requestChange(Request $request): JsonResponse
    {
        $request->user()->can('transportation.request.create') || abort(403);
        $validated = $request->validate([
            'student_id' => ['required', 'integer'],
            'request_type' => ['required', 'string'],
            'route_id' => ['nullable', 'integer'],
            'pickup_stop_id' => ['nullable', 'integer'],
            'dropoff_stop_id' => ['nullable', 'integer'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
            'reason' => ['nullable', 'string'],
        ]);
        abort_unless($this->scope->canAccessStudent($request->user(), (int) $validated['student_id']), 403,
            'Anda hanya dapat mengajukan permintaan untuk anak Anda.');

        $validated['requested_by_type'] = 'PARENT';

        return response()->json(['data' => $this->requests->submit($validated, $request->user())], 201);
    }

    public function myRequests(Request $request): JsonResponse
    {
        $request->user()->can('transportation.portal.view') || abort(403);
        $childIds = $this->parents->childrenOfUser((int) $request->user()->getKey());

        $rows = $this->requests->requests(['student_id' => $request->input('student_id')]);
        $mine = array_values(array_filter($rows, fn (array $row): bool => in_array((int) ($row['student_id'] ?? 0), $childIds, true)));

        return response()->json(['data' => $mine]);
    }

    /** Jadwal rute publik-internal untuk wali (§73). */
    public function schedules(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('transportation.portal.view') || abort(403);
        abort_unless($this->scope->canAccessStudent($request->user(), $studentId), 403);
        $assignments = $this->scope->assignmentsFor($request->user(), $studentId);

        $schedules = [];
        foreach ($assignments as $assignment) {
            $routeId = $assignment['route']['id'] ?? null;
            if (! $routeId) {
                continue;
            }
            $schedules = array_merge($schedules, \App\Modules\SchoolOperations\Transportation\Models\TransportationSchedule::query()
                ->where('route_id', $routeId)->where('active', true)
                ->get(['id', 'code', 'name', 'direction', 'departure_time', 'expected_arrival_time', 'days_of_week'])
                ->toArray());
        }

        return response()->json(['data' => ['student_id' => $studentId, 'assignments' => $assignments, 'schedules' => $schedules]]);
    }
}
