<?php

namespace App\Modules\SchoolOperations\Laundry\Http\Controllers;

use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryProcessingService;
use App\Modules\SchoolOperations\Laundry\Models\LaundryBatch;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Pemrosesan: kanban antrean (§32), batch (§33), langkah individual
 * (§35–§37), QC (§38), dan pengemasan (§42).
 */
class ProcessingController extends Controller
{
    public function __construct(private readonly LaundryProcessingService $processing)
    {
    }

    public function kanban(Request $request): JsonResponse
    {
        $request->user()->can('laundry.view') || abort(403);

        return response()->json(['data' => $this->processing->kanban($request->input('date'))]);
    }

    // ── Batch ──────────────────────────────────────────────────────────────

    public function batches(Request $request): JsonResponse
    {
        $request->user()->can('laundry.view') || abort(403);

        $batches = LaundryBatch::query()
            ->with('machine:id,code,name', 'orders:id,order_number,student_id,net_weight,status')
            ->when($request->string('status')->toString(), fn ($q, $v) => $q->where('status', $v))
            ->when($request->string('stage')->toString(), fn ($q, $v) => $q->where('stage', $v))
            ->latest('id')
            ->paginate(min(50, max(10, $request->integer('per_page', 20))));

        return response()->json(['data' => $batches->items(), 'meta' => [
            'total' => $batches->total(), 'page' => $batches->currentPage(),
        ]]);
    }

    public function storeBatch(Request $request): JsonResponse
    {
        $request->user()->can('laundry.batch.manage') || abort(403);

        $data = $request->validate([
            'stage' => ['required', 'string', 'in:'.implode(',', (array) config('laundry.processing_stages'))],
            'laundry_machine_id' => ['nullable', 'integer', 'exists:laundry_machines,id'],
            'operator_id' => ['nullable', 'integer', 'exists:users,id'],
            'program' => ['nullable', 'string', 'max:50'],
            'temperature_c' => ['nullable', 'numeric'],
            'detergent' => ['nullable', 'string'],
            'drying_method' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['integer'],
        ]);

        $batch = $this->processing->createBatch($data, $request->user());
        if (! empty($data['order_ids'])) {
            $batch = $this->processing->addOrders($batch, $data['order_ids'], $request->user());
        }

        return response()->json(['data' => $batch->load('orders:id,order_number,net_weight,status')], 201);
    }

    public function showBatch(Request $request, LaundryBatch $batch): JsonResponse
    {
        $request->user()->can('laundry.view') || abort(403);

        return response()->json(['data' => $batch->load('machine:id,code,name', 'orders')]);
    }

    public function addBatchOrders(Request $request, LaundryBatch $batch): JsonResponse
    {
        $request->user()->can('laundry.batch.manage') || abort(403);

        $data = $request->validate(['order_ids' => ['required', 'array', 'min:1'], 'order_ids.*' => ['integer']]);

        $batch = $this->processing->addOrders($batch, $data['order_ids'], $request->user());

        return response()->json(['data' => $batch->load('orders:id,order_number,net_weight,status')]);
    }

    public function startBatch(Request $request, LaundryBatch $batch): JsonResponse
    {
        $request->user()->can('laundry.process') || abort(403);

        return response()->json(['data' => $this->processing->startBatch($batch, $request->user())]);
    }

    public function completeBatch(Request $request, LaundryBatch $batch): JsonResponse
    {
        $request->user()->can('laundry.process') || abort(403);

        return response()->json(['data' => $this->processing->completeBatch($batch, $request->user())]);
    }

    // ── Langkah individual ─────────────────────────────────────────────────

    public function recordStep(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.process') || abort(403);

        $data = $request->validate([
            'step_type' => ['required', 'string', 'in:WASHING,DRYING,IRONING,FOLDING,PACKAGING'],
            'action' => ['required', 'string', 'in:start,complete'],
            'laundry_machine_id' => ['nullable', 'integer', 'exists:laundry_machines,id'],
            'program' => ['nullable', 'string', 'max:50'],
            'temperature_c' => ['nullable', 'numeric'],
            'detergent' => ['nullable', 'string'],
            'drying_method' => ['nullable', 'string', 'max:30'],
            'item_count' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $step = $this->processing->recordStep(
            $order,
            (string) $data['step_type'],
            (string) $data['action'],
            $data,
            $request->user(),
        );

        return response()->json(['data' => $step]);
    }

    // ── QC & pengemasan ────────────────────────────────────────────────────

    public function qualityCheck(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.qc') || abort(403);

        $data = $request->validate([
            'result' => ['required', 'string', 'in:'.implode(',', (array) config('laundry.qc_results'))],
            'checklist' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->processing->qualityCheck($order, $data, $request->user())]);
    }

    public function package(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.process') || abort(403);

        $data = $request->validate([
            'laundry_bag_id' => ['nullable', 'integer', 'exists:laundry_bags,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $order = $this->processing->package($order, $data, $request->user());

        return response()->json(['data' => $order->fresh()]);
    }
}
