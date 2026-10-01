<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\StockOpnameService;
use App\Modules\Asset\Domain\Enums\VarianceDecision;
use App\Modules\Asset\Models\AssetStockOpname;
use App\Modules\Asset\Models\AssetStockOpnameVariance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetStockOpnameController extends AssetControllerBase
{
    public function __construct(private readonly StockOpnameService $opnames) {}

    public function index(Request $request): JsonResponse
    {
        $query = AssetStockOpname::query();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->paginated($query->orderByDesc('id')->paginate(min(100, max(1, (int) $request->input('per_page', 25)))), 'Daftar sesi stok opname.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'array'],
            'scope.campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
            'scope.building_id' => ['nullable', 'integer', 'exists:buildings,id'],
            'scope.location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'scope.category_id' => ['nullable', 'integer', 'exists:asset_categories,id'],
            'scope.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'title' => ['nullable', 'string', 'max:200'],
            'method' => ['nullable', 'string', 'max:24'],
            'scheduled_for' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->created($this->opnames->create($this->actor($request), $data['scope'], $data), 'Sesi opname dibuat beserta daftar aset yang diharapkan.');
    }

    public function show(Request $request, AssetStockOpname $opname): JsonResponse
    {
        Gate::authorize('view', $opname);

        return $this->ok($opname->load(['items.asset:id,asset_code,name', 'variances']), 'Rincian sesi opname.');
    }

    public function start(Request $request, AssetStockOpname $opname): JsonResponse
    {
        Gate::authorize('update', $opname);

        return $this->ok($this->opnames->start($this->actor($request), $opname), 'Penghitungan dimulai.');
    }

    public function scan(Request $request, AssetStockOpname $opname): JsonResponse
    {
        Gate::authorize('update', $opname);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:160'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'custodian_id' => ['nullable', 'integer'],
            'condition' => ['nullable', 'string', 'max:24'],
            'notes' => ['nullable', 'string'],
        ]);

        $item = $this->opnames->scan($this->actor($request), $opname, $data['code'], $data);

        return $this->ok([
            'item' => $item,
            'result' => $item->scan_result?->value ?? (string) $item->scan_result,
            'is_variance' => (bool) $item->is_variance,
        ], 'Hasil pindai dicatat.');
    }

    public function verify(Request $request, AssetStockOpname $opname): JsonResponse
    {
        Gate::authorize('update', $opname);

        return $this->ok($this->opnames->moveToVerification($this->actor($request), $opname), 'Sesi masuk tahap verifikasi.');
    }

    public function decideVariance(Request $request, AssetStockOpname $opname, AssetStockOpnameVariance $variance): JsonResponse
    {
        Gate::authorize('update', $opname);

        $data = $request->validate([
            'decision' => ['required', 'string', 'in:PENDING,ACCEPTED,INVESTIGATED,ADJUSTED,REJECTED'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok(
            $this->opnames->decideVariance($this->actor($request), $opname, $variance, VarianceDecision::from($data['decision']), $data),
            'Keputusan selisih dicatat.',
        );
    }

    public function complete(Request $request, AssetStockOpname $opname): JsonResponse
    {
        Gate::authorize('update', $opname);

        $data = $request->validate(['notes' => ['nullable', 'string']]);

        return $this->ok($this->opnames->complete($this->actor($request), $opname, $data), 'Opname selesai; hanya selisih yang disetujui diterapkan.');
    }

    public function summary(Request $request, AssetStockOpname $opname): JsonResponse
    {
        Gate::authorize('view', $opname);

        return $this->ok($this->opnames->summary($opname), 'Ringkasan hasil opname.');
    }
}
