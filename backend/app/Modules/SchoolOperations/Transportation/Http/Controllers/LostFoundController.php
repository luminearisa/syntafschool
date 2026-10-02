<?php

namespace App\Modules\SchoolOperations\Transportation\Http\Controllers;

use App\Modules\SchoolOperations\Transportation\Application\Services\LostFoundService;
use App\Modules\SchoolOperations\Transportation\Models\LostFoundItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Barang hilang & temuan (§71). */
class LostFoundController extends Controller
{
    public function __construct(private readonly LostFoundService $lostFound)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('transportation.lostfound.view') || abort(403);

        return response()->json(['data' => $this->lostFound->items($request->only(['status', 'vehicle_id', 'trip_id', 'from', 'limit']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('transportation.lostfound.manage') || abort(403);
        $validated = $request->validate([
            'trip_id' => ['nullable', 'integer'],
            'vehicle_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'found_location' => ['nullable', 'string'],
            'found_date' => ['nullable', 'date'],
            'item_name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'photo_path' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->lostFound->record($validated)], 201);
    }

    public function identify(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.lostfound.manage') || abort(403);
        $item = LostFoundItem::query()->findOrFail($id);

        return response()->json(['data' => $this->lostFound->identify($item, $request->input('student_id'))]);
    }

    public function notifyOwner(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.lostfound.manage') || abort(403);
        $item = LostFoundItem::query()->findOrFail($id);

        return response()->json(['data' => $this->lostFound->notifyOwner($item)]);
    }

    public function returnItem(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.lostfound.manage') || abort(403);
        $item = LostFoundItem::query()->findOrFail($id);

        return response()->json(['data' => $this->lostFound->returnItem($item, $request->all())]);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $request->user()->can('transportation.lostfound.manage') || abort(403);
        $item = LostFoundItem::query()->findOrFail($id);

        return response()->json(['data' => $this->lostFound->close($item, (string) $request->input('status', 'UNCLAIMED'))]);
    }
}
