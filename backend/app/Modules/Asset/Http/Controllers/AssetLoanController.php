<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\AssetLoanService;
use App\Modules\Asset\Models\AssetLoan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetLoanController extends AssetControllerBase
{
    public function __construct(private readonly AssetLoanService $loans) {}

    public function index(Request $request): JsonResponse
    {
        $query = AssetLoan::query()->with(['items.asset:id,asset_code,name', 'borrower:id,name', 'requester:id,name']);

        if (! $this->actor($request)->can('asset.loan.manage')) {
            $query->where(fn ($inner) => $inner->where('borrower_id', $this->actor($request)->id)->orWhere('requested_by', $this->actor($request)->id));
        }

        foreach (['status', 'borrower_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->boolean('overdue')) {
            $query->whereIn('status', ['ACTIVE', 'OVERDUE', 'HANDED_OVER'])->where('due_at', '<', now());
        }

        return $this->paginated($query->orderByDesc('id')->paginate(min(100, max(1, (int) $request->input('per_page', 25)))), 'Daftar peminjaman.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['integer', 'exists:assets,id'],
            'borrower_id' => ['nullable', 'integer', 'exists:users,id'],
            'borrower_type' => ['nullable', 'string', 'max:24'],
            'purpose' => ['required', 'string', 'max:200'],
            'start_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date', 'after:start_at'],
            'activity_reference' => ['nullable', 'string', 'max:80'],
            'accessories' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $loan = $this->loans->request($this->actor($request), $data, $data['asset_ids']);

        return $this->created($loan, 'Permintaan peminjaman dibuat.');
    }

    public function show(Request $request, AssetLoan $loan): JsonResponse
    {
        Gate::authorize('view', $loan);

        return $this->ok($loan->load(['items.asset', 'borrower:id,name', 'requester:id,name', 'approver:id,name', 'returnRecord']), 'Rincian peminjaman.');
    }

    public function approve(Request $request, AssetLoan $loan): JsonResponse
    {
        Gate::authorize('update', $loan);

        return $this->ok($this->loans->approve($this->actor($request), $loan), 'Peminjaman disetujui.');
    }

    public function reserve(Request $request, AssetLoan $loan): JsonResponse
    {
        Gate::authorize('update', $loan);

        return $this->ok($this->loans->reserve($this->actor($request), $loan), 'Aset direservasi untuk peminjaman ini.');
    }

    public function handover(Request $request, AssetLoan $loan): JsonResponse
    {
        Gate::authorize('update', $loan);

        $data = $request->validate(['conditions' => ['nullable', 'array'], 'condition_out' => ['nullable', 'string', 'max:24']]);

        return $this->ok($this->loans->handover($this->actor($request), $loan, $data), 'Aset diserahkan (satu pinjaman berjalan per aset dijaga basis data).');
    }

    public function activate(Request $request, AssetLoan $loan): JsonResponse
    {
        Gate::authorize('update', $loan);

        return $this->ok($this->loans->activate($this->actor($request), $loan), 'Peminjaman berjalan.');
    }

    public function returnLoan(Request $request, AssetLoan $loan): JsonResponse
    {
        Gate::authorize('update', $loan);

        $data = $request->validate([
            'condition' => ['required', 'string', 'max:24'],
            'conditions' => ['nullable', 'array'],
            'damage_found' => ['nullable', 'boolean'],
            'accessories' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->loans->return($this->actor($request), $loan, $data), 'Aset dikembalikan beserta hasil pemeriksaan kondisi.');
    }

    public function cancel(Request $request, AssetLoan $loan): JsonResponse
    {
        Gate::authorize('update', $loan);

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->ok($this->loans->cancel($this->actor($request), $loan, $data['reason']), 'Peminjaman dibatalkan.');
    }
}
