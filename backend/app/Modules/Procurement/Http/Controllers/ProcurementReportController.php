<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Procurement\Application\Services\BudgetService;
use App\Modules\Procurement\Application\Services\ProcurementAnalyticsService;
use App\Modules\Procurement\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Laporan, analitik belanja, dan pengaturan modul (§40–§42, §58). */
class ProcurementReportController extends BaseApiController
{
    public function __construct(
        private readonly ProcurementAnalyticsService $analytics,
        private readonly BudgetService $budget,
    ) {
    }

    /** Laporan per jenis: pr | rfq | po | invoice | vendor (§58). */
    public function report(Request $request, string $type): JsonResponse
    {
        return $this->successResponse(
            $this->analytics->report($type, $request->only(['from', 'to'])),
        );
    }

    /** Analitik belanja (§42). */
    public function spend(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->analytics->spendAnalytics($request->only(['from', 'to'])),
        );
    }

    /** Waktu siklus antar tahap (§41). */
    public function cycleTimes(): JsonResponse
    {
        return $this->successResponse($this->analytics->cycleTimes());
    }

    /** Ketersediaan anggaran (dibaca dari BudgetProvider) (§35). */
    public function budgetAvailability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cost_center_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->successResponse($this->budget->availability($data));
    }

    /** Daftar pengaturan modul. */
    public function settings(): JsonResponse
    {
        return $this->successResponse(Setting::query()->orderBy('group')->orderBy('key')->get());
    }

    /** Simpan pengaturan modul (aturan bisnis configurable). */
    public function updateSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:80'],
            'value' => ['required'],
            'group' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $setting = Setting::query()->updateOrCreate(
            ['key' => $data['key']],
            ['value' => $data['value'], 'group' => $data['group'] ?? 'general', 'description' => $data['description'] ?? null],
        );

        return $this->successResponse($setting, 'Pengaturan disimpan');
    }
}
