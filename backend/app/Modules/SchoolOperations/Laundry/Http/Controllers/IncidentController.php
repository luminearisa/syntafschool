<?php

namespace App\Modules\SchoolOperations\Laundry\Http\Controllers;

use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryIncidentService;
use App\Modules\SchoolOperations\Laundry\Models\LaundryIncident;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Insiden (§39/§40) & kompensasi ber-approval (§41, Rule 18). */
class IncidentController extends Controller
{
    public function __construct(private readonly LaundryIncidentService $incidents)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('laundry.incident.view') || abort(403);

        $rows = $this->incidents->query($request->only(['status', 'incident_type', 'from', 'to']))
            ->paginate(min(100, max(10, $request->integer('per_page', 25))));

        return response()->json(['data' => $rows->items(), 'meta' => [
            'total' => $rows->total(), 'page' => $rows->currentPage(),
        ]]);
    }

    public function store(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.incident.manage') || abort(403);

        $data = $request->validate([
            'incident_type' => ['required', 'string', 'in:'.implode(',', (array) config('laundry.incident_types'))],
            'description' => ['required', 'string'],
            'laundry_order_item_id' => ['nullable', 'integer'],
            'evidence_path' => ['nullable', 'string'],
            'responsible_operator_id' => ['nullable', 'integer', 'exists:users,id'],
            'mark_order' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->incidents->report($order, $data, $request->user())], 201);
    }

    public function updateStatus(Request $request, LaundryIncident $incident): JsonResponse
    {
        $request->user()->can('laundry.incident.manage') || abort(403);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', (array) config('laundry.incident_statuses'))],
            'resolution' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->incidents->updateStatus(
            $incident, (string) $data['status'], $data['resolution'] ?? null, $request->user()
        )]);
    }

    public function proposeCompensation(Request $request, LaundryIncident $incident): JsonResponse
    {
        $request->user()->can('laundry.incident.manage') || abort(403);

        $data = $request->validate([
            'compensation_type' => ['required', 'string', 'in:'.implode(',', (array) config('laundry.compensation_types'))],
            'compensation_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json(['data' => $this->incidents->proposeCompensation(
            $incident, (string) $data['compensation_type'], $data['compensation_amount'] ?? null, $request->user()
        )]);
    }

    /** §41: kompensasi dieksekusi hanya setelah approval. */
    public function approveCompensation(Request $request, LaundryIncident $incident): JsonResponse
    {
        $request->user()->can('laundry.payment.override') || abort(403);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => $this->incidents->approveCompensation(
            $incident, $request->user(), $data['note'] ?? null
        )]);
    }
}
