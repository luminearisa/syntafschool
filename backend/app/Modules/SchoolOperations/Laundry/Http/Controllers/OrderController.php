<?php

namespace App\Modules\SchoolOperations\Laundry\Http\Controllers;

use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryOrderService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryPaymentService;
use App\Modules\SchoolOperations\Laundry\Domain\Support\OrderStatus;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * API order laundry admin/operasional (§9–§12, §29–§31, §54–§57, §61).
 * Otorisasi baris untuk portal berada di PortalController; controller ini
 * untuk staf dengan izin `laundry.*`.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly LaundryOrderService $orders,
        private readonly LaundryPaymentService $payments,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('laundry.view') || abort(403);

        $orders = LaundryOrder::query()
            ->with('student:id,full_name,nis', 'service:id,name,code')
            ->when($request->string('status')->toString(), fn ($q, $v) => $q->where('status', $v))
            ->when($request->string('payment_status')->toString(), fn ($q, $v) => $q->where('payment_status', $v))
            ->when($request->integer('student_id') ?: null, fn ($q, $v) => $q->where('student_id', $v))
            ->when($request->integer('laundry_service_id') ?: null, fn ($q, $v) => $q->where('laundry_service_id', $v))
            ->when($request->string('from')->toString(), fn ($q, $v) => $q->whereDate('order_date', '>=', $v))
            ->when($request->string('to')->toString(), fn ($q, $v) => $q->whereDate('order_date', '<=', $v))
            ->when($request->string('search')->toString(), function ($q, $term): void {
                $like = '%'.$term.'%';
                $q->where(function ($sub) use ($like): void {
                    $sub->where('order_number', 'like', $like)
                        ->orWhereHas('student', fn ($s) => $s->where('full_name', 'like', $like)
                            ->orWhere('nis', 'like', $like));
                });
            })
            ->latest('id')
            ->paginate(min(100, max(10, $request->integer('per_page', 25))));

        return response()->json([
            'data' => collect($orders->items())->map(fn (LaundryOrder $o): array => $this->orders->presentSummary($o))->all(),
            'meta' => [
                'total' => $orders->total(),
                'page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('laundry.create') || abort(403);

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'laundry_service_id' => ['required', 'integer'],
            'laundry_bag_id' => ['nullable', 'integer'],
            'submitted_by' => ['nullable', 'string', 'max:100'],
            'pickup_method' => ['nullable', 'string', 'in:'.implode(',', (array) config('laundry.pickup_methods'))],
            'notes' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.item_type' => ['required_with:items', 'string'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.color' => ['nullable', 'string'],
            'items.*.condition' => ['nullable', 'string'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.tag_code' => ['nullable', 'string'],
            'items.*.rfid_tag' => ['nullable', 'string'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $order = $this->orders->create($data, $request->user());

        return response()->json(['data' => $this->orders->present($order)], 201);
    }

    public function show(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.view') || abort(403);

        return response()->json(['data' => $this->orders->present($order)]);
    }

    public function timeline(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.view') || abort(403);

        return response()->json(['data' => $this->orders->timeline($order)]);
    }

    public function receive(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.receive') || abort(403);

        $data = $request->validate([
            'laundry_bag_id' => ['nullable', 'integer'],
            'bag_code' => ['nullable', 'string'],
            'submitted_by' => ['nullable', 'string', 'max:100'],
            'items' => ['nullable', 'array'],
            'items.*.item_type' => ['required_with:items', 'string'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.color' => ['nullable', 'string'],
            'items.*.condition' => ['nullable', 'string'],
        ]);

        foreach ((array) ($data['items'] ?? []) as $item) {
            $this->orders->addItem($order, $item);
        }

        $order = $this->orders->receive($order->fresh(), $data, $request->user());

        return response()->json(['data' => $this->orders->present($order->fresh())]);
    }

    public function weigh(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.weigh') || abort(403);

        $data = $request->validate([
            'gross_weight' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'bag_weight' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'item_count' => ['nullable', 'integer', 'min:0'],
        ]);

        $order = $this->orders->weigh($order, $data, $request->user());

        return response()->json(['data' => $this->orders->present($order)]);
    }

    /** §54: pratinjau harga + jatah + saldo sebelum konfirmasi. */
    public function preview(Request $request): JsonResponse
    {
        $request->user()->can('laundry.view') || abort(403);

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'laundry_service_id' => ['required', 'integer'],
            'net_weight' => ['nullable', 'numeric', 'min:0'],
            'items' => ['nullable', 'array'],
            'items.*.item_type' => ['required_with:items', 'string'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'extras' => ['nullable', 'array'],
            'extras.*' => ['string'],
            'date' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->orders->previewQuote((int) $data['student_id'], $data)]);
    }

    public function calculate(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.weigh') || abort(403);

        $data = $request->validate([
            'extras' => ['nullable', 'array'],
            'extras.*' => ['string', 'in:'.implode(',', array_keys((array) config('laundry.fee_types', [])))],
        ]);

        $order = $this->orders->calculate($order, $request->user(), (array) ($data['extras'] ?? []));

        return response()->json(['data' => $this->orders->present($order)]);
    }

    /** §20/§22/§70: charge wallet idempoten (kunci wajib dari pemanggil). */
    public function pay(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.payment.view') || abort(403);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100'],
            'method' => ['nullable', 'string', 'in:'.implode(',', (array) config('laundry.payment_methods'))],
            'rfid_uid' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->payments->pay(
            $order,
            (string) $data['idempotency_key'],
            $request->user(),
            (string) ($data['method'] ?? 'WALLET'),
            $data['rfid_uid'] ?? null,
        );

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'data' => $result,
                'message' => $result['reason'] === 'INSUFFICIENT_BALANCE'
                    ? 'Saldo wallet tidak mencukupi.'
                    : 'Pembayaran gagal.',
            ], 422);
        }

        return response()->json(['data' => [
            'payment' => $result['payment'],
            'order' => $this->orders->present($order->fresh()),
            'replayed' => (bool) ($result['replayed'] ?? false),
        ]]);
    }

    public function paymentPreview(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.payment.view') || abort(403);

        return response()->json(['data' => $this->payments->preview($order)]);
    }

    public function refund(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.refund') || abort(403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $result = $this->payments->refund($order, (string) $data['reason'], $request->user(), $data['amount'] ?? null);

        return response()->json(['data' => $result['refund']]);
    }

    /** Antrikan order yang sudah beres pembayarannya (Rule 27). */
    public function queue(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.process') || abort(403);

        $order = $this->orders->queue($order, $request->user());

        return response()->json(['data' => $this->orders->presentSummary($order)]);
    }

    /** Transisi status manual yang sah (mis. QUALITY_CHECK → PACKAGED lewat QC API). */
    public function transition(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.process') || abort(403);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', OrderStatus::ALL)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $stateMachine = app(\App\Modules\SchoolOperations\Laundry\Application\Services\LaundryOrderStateMachine::class);

        if ($data['status'] === OrderStatus::QUEUED) {
            $order = $this->orders->queue($order, $request->user());
        } else {
            $order = $stateMachine->transitionTo($order, (string) $data['status'], $request->user(), $data['reason'] ?? null);
        }

        return response()->json(['data' => $this->orders->presentSummary($order)]);
    }

    public function complete(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.deliver') || abort(403);

        $stateMachine = app(\App\Modules\SchoolOperations\Laundry\Application\Services\LaundryOrderStateMachine::class);
        $order = $stateMachine->transitionTo($order, OrderStatus::COMPLETED, $request->user(), 'Finalisasi manual');

        return response()->json(['data' => $this->orders->presentSummary($order)]);
    }

    public function cancel(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.cancel') || abort(403);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $result = $this->orders->cancel($order, (string) $data['reason'], $request->user());

        return response()->json(['data' => array_merge($result, [
            'order' => $this->orders->presentSummary($order->fresh()),
        ])]);
    }

    /** §57: override beralasan (WAIVE_FEE / FORCE_PROCESS) — diaudit. */
    public function override(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.payment.override') || abort(403);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:WAIVE_FEE,FORCE_PROCESS'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $order = $this->orders->override($order, (string) $data['type'], (string) $data['reason'], $request->user());

        return response()->json(['data' => $this->orders->present($order)]);
    }

    public function label(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.receive') || abort(403);

        $label = $this->orders->ensureLabel($order, $request->user());

        return response()->json(['data' => [
            'label_number' => (string) $label->label_number,
            'qr_payload' => (string) $label->qr_payload,
            'order_number' => (string) $order->order_number,
        ]]);
    }

    /** §17: tap RFID/QR di meja penerimaan. */
    public function identify(Request $request): JsonResponse
    {
        $request->user()->can('laundry.identify') || abort(403);

        $data = $request->validate(['uid' => ['required', 'string', 'max:120']]);

        return response()->json(['data' => $this->orders->identify((string) $data['uid'])]);
    }
}
