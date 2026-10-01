<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\InspectionService;
use App\Modules\Asset\Models\AssetInspection;
use App\Modules\Asset\Models\AssetInspectionTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetInspectionController extends AssetControllerBase
{
    public function __construct(private readonly InspectionService $inspections) {}

    public function index(Request $request): JsonResponse
    {
        $query = AssetInspection::query()->with(['asset:id,asset_code,name', 'facility:id,facility_code,name', 'performer:id,name', 'template:id,name']);

        foreach (['status', 'inspection_type', 'asset_id', 'facility_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('from')) {
            $query->where('scheduled_at', '>=', $request->input('from'));
        }

        return $this->paginated($query->orderByDesc('id')->paginate(min(100, max(1, (int) $request->input('per_page', 25)))), 'Daftar inspeksi.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['nullable', 'integer', 'exists:assets,id', 'required_without:facility_id'],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id', 'required_without:asset_id'],
            'template_id' => ['nullable', 'integer', 'exists:asset_inspection_templates,id'],
            'inspection_type' => ['nullable', 'string', 'max:32'],
            'scheduled_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->created($this->inspections->schedule($this->actor($request), $data), 'Inspeksi dijadwalkan.');
    }

    public function show(Request $request, AssetInspection $inspection): JsonResponse
    {
        Gate::authorize('view', $inspection);

        return $this->ok($inspection->load(['asset:id,asset_code,name', 'facility:id,facility_code,name', 'results', 'template']), 'Rincian inspeksi.');
    }

    public function perform(Request $request, AssetInspection $inspection): JsonResponse
    {
        Gate::authorize('update', $inspection);

        $data = $request->validate([
            'results' => ['required', 'array', 'min:1'],
            'results.*.code' => ['required', 'string', 'max:60'],
            'results.*.label' => ['nullable', 'string', 'max:160'],
            'results.*.result' => ['required', 'string', 'in:PASS,FAIL,NOT_APPLICABLE'],
            'results.*.value' => ['nullable', 'string', 'max:120'],
            'results.*.notes' => ['nullable', 'string'],
            'condition' => ['nullable', 'string', 'max:24'],
            'findings' => ['nullable', 'string'],
            'follow_up_required' => ['nullable', 'boolean'],
            'next_inspection_at' => ['nullable', 'date'],
            'document_reference' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->inspections->perform($this->actor($request), $inspection, $data['results'], $data), 'Hasil inspeksi dicatat.');
    }

    public function templates(Request $request): JsonResponse
    {
        return $this->ok(AssetInspectionTemplate::query()->where('is_active', true)->orderBy('name')->get(), 'Templat inspeksi.');
    }
}
