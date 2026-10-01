<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\AssetDisposalService;
use App\Modules\Asset\Application\Services\AssetReplacementService;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetDisposalRequest;
use App\Modules\Asset\Models\AssetReplacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetDisposalController extends AssetControllerBase
{
    public function __construct(
        private readonly AssetDisposalService $disposals,
        private readonly AssetReplacementService $replacements,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = AssetDisposalRequest::query()->with(['asset:id,asset_code,name', 'requester:id,name', 'approver:id,name']);

        foreach (['status', 'reason', 'method'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        return $this->paginated($query->orderByDesc('id')->paginate(min(100, max(1, (int) $request->input('per_page', 25)))), 'Daftar permintaan penghapusan.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'reason' => ['required', 'string', 'max:32'],
            'method' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'expected_proceeds' => ['nullable', 'numeric', 'min:0'],
            'buyer_name' => ['nullable', 'string', 'max:160'],
            'donation_recipient' => ['nullable', 'string', 'max:160'],
            'inspection_id' => ['nullable', 'integer'],
        ]);

        return $this->created($this->disposals->submit($this->actor($request), $data), 'Permintaan penghapusan diajukan.');
    }

    public function show(Request $request, AssetDisposalRequest $disposal): JsonResponse
    {
        Gate::authorize('view', $disposal);

        return $this->ok($disposal->load(['asset', 'requester:id,name', 'approver:id,name', 'disposal']), 'Rincian permintaan penghapusan.');
    }

    public function inspect(Request $request, AssetDisposalRequest $disposal): JsonResponse
    {
        Gate::authorize('update', $disposal);

        $data = $request->validate([
            'condition' => ['nullable', 'string', 'max:24'],
            'results' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->disposals->inspect($this->actor($request), $disposal, $data), 'Inspeksi pra-penghapusan selesai.');
    }

    public function value(Request $request, AssetDisposalRequest $disposal): JsonResponse
    {
        Gate::authorize('update', $disposal);

        $data = $request->validate([
            'estimated_value' => ['required', 'numeric', 'min:0'],
            'expected_proceeds' => ['nullable', 'numeric', 'min:0'],
            'document_reference' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->ok($this->disposals->value($this->actor($request), $disposal, $data), 'Penilaian pra-penghapusan dicatat.');
    }

    public function approve(Request $request, AssetDisposalRequest $disposal): JsonResponse
    {
        Gate::authorize('update', $disposal);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:200']]);

        return $this->ok($this->disposals->approve($this->actor($request), $disposal, $data['notes'] ?? null), 'Penghapusan disetujui.');
    }

    public function reject(Request $request, AssetDisposalRequest $disposal): JsonResponse
    {
        Gate::authorize('update', $disposal);

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->ok($this->disposals->reject($this->actor($request), $disposal, $data['reason']), 'Permintaan penghapusan ditolak.');
    }

    public function execute(Request $request, AssetDisposalRequest $disposal): JsonResponse
    {
        Gate::authorize('update', $disposal);

        $data = $request->validate([
            'method' => ['nullable', 'string', 'max:32'],
            'proceeds' => ['nullable', 'numeric', 'min:0'],
            'recipient' => ['nullable', 'string', 'max:160'],
            'document_reference' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->disposals->execute($this->actor($request), $disposal, $data), 'Penghapusan dilaksanakan; aset ditandai dihapusbukukan (barisnya tetap ada).');
    }

    public function retire(Request $request, AssetDisposalRequest $disposal): JsonResponse
    {
        Gate::authorize('update', $disposal);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        return $this->ok($this->disposals->retire($this->actor($request), $disposal, $data['reason'] ?? null), 'Aset diarsipkan.');
    }

    // ── Penggantian ─────────────────────────────────────────────────────────
    public function replacements(Request $request): JsonResponse
    {
        $query = AssetReplacement::query()->with('asset:id,asset_code,name,status,condition');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('recommendation')) {
            $query->where('recommendation', $request->input('recommendation'));
        }

        return $this->paginated($query->orderByDesc('id')->paginate(min(100, max(1, (int) $request->input('per_page', 25)))), 'Daftar usulan penggantian.');
    }

    public function evaluateReplacement(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $persist = $request->boolean('persist', false);
        $result = $this->replacements->recommendFor($asset, $persist);

        return $this->ok([
            'recommendation' => $result['recommendation']->value,
            'reasons' => $result['reasons'],
            'age_ratio' => $result['age_ratio'],
            'maintenance_cost_ratio' => $result['maintenance_cost_ratio'],
            'downtime_days' => $result['downtime_days'],
            'record' => $result['record'] ?? null,
        ], 'Usulan penggantian (rekomendasi, bukan pembelian).');
    }

    public function reviewReplacement(Request $request, AssetReplacement $replacement): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:REVIEWED,APPROVED,REJECTED'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->replacements->review($this->actor($request), $replacement, $data['decision'], $data['notes'] ?? null), 'Tinjauan usulan dicatat.');
    }

    public function forwardReplacement(Request $request, AssetReplacement $replacement): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->ok($this->replacements->forwardToProcurement($this->actor($request), $replacement, $data), 'Usulan diteruskan ke pengadaan (via kontrak/outbox).');
    }
}
