<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\AssetTransferService;
use App\Modules\Asset\Models\AssetTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetTransferController extends AssetControllerBase
{
    public function __construct(private readonly AssetTransferService $transfers) {}

    public function index(Request $request): JsonResponse
    {
        $query = AssetTransfer::query()->with(['items.asset:id,asset_code,name', 'fromLocation:id,name', 'toLocation:id,name', 'requester:id,name']);

        foreach (['status', 'type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if (! $this->actor($request)->can('asset.transfer.approve')) {
            $query->where(fn ($inner) => $inner->where('requested_by', $this->actor($request)->id)->orWhere('received_by', $this->actor($request)->id));
        }

        return $this->paginated($query->orderByDesc('id')->paginate(min(100, max(1, (int) $request->input('per_page', 25)))), 'Daftar pemindahan.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['integer', 'exists:assets,id'],
            'to_location_id' => ['required', 'integer', 'exists:locations,id'],
            'from_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'type' => ['nullable', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:200'],
            'to_custodian_id' => ['nullable', 'integer', 'exists:asset_custodians,id'],
            'to_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'document_reference' => ['nullable', 'string', 'max:200'],
        ]);

        $transfer = $this->transfers->request($this->actor($request), $data, $data['asset_ids']);

        return $this->created($transfer, 'Permintaan pemindahan dibuat.');
    }

    public function show(Request $request, AssetTransfer $transfer): JsonResponse
    {
        Gate::authorize('view', $transfer);

        return $this->ok($transfer->load(['items.asset', 'fromLocation', 'toLocation', 'requester:id,name', 'receiver:id,name']), 'Rincian pemindahan.');
    }

    public function approve(Request $request, AssetTransfer $transfer): JsonResponse
    {
        Gate::authorize('update', $transfer);

        return $this->ok($this->transfers->approve($this->actor($request), $transfer), 'Pemindahan disetujui.');
    }

    public function handover(Request $request, AssetTransfer $transfer): JsonResponse
    {
        Gate::authorize('update', $transfer);

        $data = $request->validate([
            'conditions' => ['nullable', 'array'],
            'notes' => ['nullable', 'array'],
            'document_reference' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->ok($this->transfers->handover($this->actor($request), $transfer, $data), 'Serah terima dicatat; menunggu konfirmasi penerima.');
    }

    public function receive(Request $request, AssetTransfer $transfer): JsonResponse
    {
        Gate::authorize('view', $transfer);

        $data = $request->validate([
            'conditions' => ['nullable', 'array'],
            'recipient_condition' => ['nullable', 'string', 'max:24'],
            'recipient_notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->transfers->receive($this->actor($request), $transfer, $data), 'Penerimaan dikonfirmasi; lokasi dan pemegang diperbarui.');
    }

    public function cancel(Request $request, AssetTransfer $transfer): JsonResponse
    {
        Gate::authorize('update', $transfer);

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->ok($this->transfers->cancel($this->actor($request), $transfer, $data['reason']), 'Pemindahan dibatalkan.');
    }
}
