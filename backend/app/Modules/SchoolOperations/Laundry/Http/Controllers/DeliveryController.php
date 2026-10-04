<?php

namespace App\Modules\SchoolOperations\Laundry\Http\Controllers;

use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryDeliveryService;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Serah terima (§43/§44): siap diambil, pickup counter, delivery asrama. */
class DeliveryController extends Controller
{
    public function __construct(private readonly LaundryDeliveryService $delivery)
    {
    }

    public function ready(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.deliver') || abort(403);

        $order = $this->delivery->markReady($order, $request->user());

        return response()->json(['data' => $order->fresh()]);
    }

    public function pickup(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.deliver') || abort(403);

        $data = $request->validate([
            'verification_method' => ['required', 'string', 'in:'.implode(',', (array) config('laundry.verification_methods'))],
            'verification_value' => ['nullable', 'string', 'max:100'],
            'receiver_name' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $result = $this->delivery->pickup($order, $data, $request->user());

        return response()->json(['data' => [
            'order' => $result['order'],
            'pickup' => $result['pickup'],
        ]]);
    }

    public function deliver(Request $request, LaundryOrder $order): JsonResponse
    {
        $request->user()->can('laundry.deliver') || abort(403);

        $data = $request->validate([
            'verification_method' => ['required', 'string', 'in:'.implode(',', (array) config('laundry.verification_methods'))],
            'verification_value' => ['nullable', 'string', 'max:100'],
            'receiver_name' => ['required', 'string', 'max:100'],
            'dormitory_id' => ['nullable', 'integer'],
            'courier_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $result = $this->delivery->deliver($order, $data, $request->user());

        return response()->json(['data' => [
            'order' => $result['order'],
            'delivery' => $result['delivery'],
        ]]);
    }
}
