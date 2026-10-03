<?php

namespace App\Modules\SchoolOperations\Laundry\Http\Controllers;

use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryBagService;
use App\Modules\SchoolOperations\Laundry\Models\LaundryBag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Laundry bag (§16): master tas, penautan ke santri, pelepasan. */
class BagController extends Controller
{
    public function __construct(private readonly LaundryBagService $bags)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('laundry.view') || abort(403);

        $bags = LaundryBag::query()
            ->with(['assignments' => fn ($q) => $q->where('status', 'ACTIVE')->limit(1)])
            ->when($request->string('status')->toString(), fn ($q, $v) => $q->where('status', $v))
            ->when($request->integer('student_id') ?: null, fn ($q, $v) => $q->whereHas(
                'assignments', fn ($a) => $a->where('student_id', $v)->where('status', 'ACTIVE')
            ))
            ->orderBy('id')
            ->paginate(min(100, max(10, $request->integer('per_page', 25))));

        return response()->json(['data' => $bags->items(), 'meta' => [
            'total' => $bags->total(), 'page' => $bags->currentPage(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('laundry.bag.manage') || abort(403);

        $data = $request->validate([
            'bag_code' => ['required', 'string', 'max:50', 'unique:laundry_bags,bag_code'],
            'rfid_uid' => ['nullable', 'string', 'max:64', 'unique:laundry_bags,rfid_uid'],
            'qr_code' => ['nullable', 'string', 'max:100', 'unique:laundry_bags,qr_code'],
            'bag_type' => ['nullable', 'string', 'max:20'],
            'capacity_kg' => ['nullable', 'numeric', 'min:0'],
            'tare_weight' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->bags->create($data, $request->user())], 201);
    }

    public function update(Request $request, LaundryBag $bag): JsonResponse
    {
        $request->user()->can('laundry.bag.manage') || abort(403);

        $data = $request->validate([
            'bag_code' => ['sometimes', 'string', 'max:50'],
            'rfid_uid' => ['nullable', 'string', 'max:64'],
            'qr_code' => ['nullable', 'string', 'max:100'],
            'bag_type' => ['sometimes', 'string', 'max:20'],
            'capacity_kg' => ['nullable', 'numeric', 'min:0'],
            'tare_weight' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'in:AVAILABLE,ASSIGNED,IN_USE,REPAIR,RETIRED'],
            'notes' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->bags->update($bag, $data, $request->user())]);
    }

    public function assign(Request $request, LaundryBag $bag): JsonResponse
    {
        $request->user()->can('laundry.bag.manage') || abort(403);

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string'],
        ]);

        $assignment = $this->bags->assign($bag, (int) $data['student_id'], $request->user(), $data['notes'] ?? null);

        return response()->json(['data' => $assignment], 201);
    }

    public function release(Request $request, LaundryBag $bag): JsonResponse
    {
        $request->user()->can('laundry.bag.manage') || abort(403);

        $data = $request->validate(['status' => ['nullable', 'string', 'in:RETURNED,LOST']]);

        return response()->json(['data' => $this->bags->release($bag, $request->user(), (string) ($data['status'] ?? 'RETURNED'))]);
    }
}
