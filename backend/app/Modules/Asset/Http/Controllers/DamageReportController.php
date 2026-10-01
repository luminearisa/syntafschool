<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\DamageReportService;
use App\Modules\Asset\Models\DamageReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DamageReportController extends AssetControllerBase
{
    public function __construct(private readonly DamageReportService $damage) {}

    public function index(Request $request): JsonResponse
    {
        $query = DamageReport::query()->with(['asset:id,asset_code,name', 'facility:id,facility_code,name', 'reporter:id,name']);

        foreach (['status', 'severity', 'asset_id', 'facility_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        return $this->paginated($query->orderByDesc('reported_at')->paginate(min(100, max(1, (int) $request->input('per_page', 25)))), 'Daftar laporan kerusakan.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['nullable', 'integer', 'exists:assets,id', 'required_without:facility_id'],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id', 'required_without:asset_id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'severity' => ['required', 'string', 'in:MINOR,MODERATE,MAJOR,TOTAL'],
            'damage_type' => ['nullable', 'string', 'max:60'],
            'description' => ['required', 'string'],
            'photo_path' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->created($this->damage->report($this->actor($request), $data), 'Laporan kerusakan dicatat.');
    }

    public function show(Request $request, DamageReport $report): JsonResponse
    {
        return $this->ok($report->load(['asset:id,asset_code,name', 'facility:id,facility_code,name', 'reporter:id,name', 'maintenanceRequest']), 'Rincian laporan kerusakan.');
    }

    public function verify(Request $request, DamageReport $report): JsonResponse
    {
        $data = $request->validate([
            'accepted' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->ok($this->damage->verify($this->actor($request), $report, (bool) $data['accepted'], $data['notes'] ?? null), 'Laporan diverifikasi.');
    }

    public function createMaintenance(Request $request, DamageReport $report): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'max:24'],
        ]);

        return $this->created($this->damage->createMaintenance($this->actor($request), $report, $data), 'Permintaan pemeliharaan dibuat dari laporan kerusakan.');
    }
}
