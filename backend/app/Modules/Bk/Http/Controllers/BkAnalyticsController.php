<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Modules\Bk\Application\Services\BkAccessService;
use App\Modules\Bk\Application\Services\BkAnalyticsService;
use App\Modules\Bk\Application\Services\BkWorkloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Analitik, dasbor konselor/koordinator, dan umur kasus.
 *
 * Semua angka melewati cakupan privasi yang sama dengan layar operasional, dan
 * kelompok berjumlah sangat kecil disamarkan agar tidak menjadi petunjuk identitas.
 */
class BkAnalyticsController extends Controller
{
    use ApiResponse;


    public function __construct(
        private readonly BkAnalyticsService $analytics,
        private readonly BkWorkloadService $workload,
    ) {
    }

    public function cases(Request $request): JsonResponse
    {
        $request->user()->can('bk.analytics.view') || abort(403);

        return $this->successResponse($this->analytics->cases($request->user(), $request->only(['from', 'to'])));
    }

    public function referrals(Request $request): JsonResponse
    {
        $request->user()->can('bk.analytics.view') || abort(403);

        return $this->successResponse($this->analytics->referrals($request->user(), $request->only(['from', 'to'])));
    }

    public function outcomes(Request $request): JsonResponse
    {
        $request->user()->can('bk.analytics.view') || abort(403);

        return $this->successResponse($this->analytics->outcomes($request->user(), $request->only(['from', 'to'])));
    }

    public function service(Request $request): JsonResponse
    {
        $request->user()->can('bk.analytics.view') || abort(403);

        return $this->successResponse($this->analytics->service($request->user(), $request->only(['from', 'to'])));
    }

    public function trends(Request $request): JsonResponse
    {
        $request->user()->can('bk.analytics.view') || abort(403);

        return $this->successResponse($this->analytics->trends($request->user(), $request->only(['from', 'to'])));
    }

    public function aging(Request $request): JsonResponse
    {
        $request->user()->can('bk.analytics.view') || abort(403);

        return $this->successResponse($this->analytics->aging($request->user()));
    }

    public function workload(Request $request): JsonResponse
    {
        $request->user()->can('bk.workload.view') || abort(403);

        return $this->successResponse([
            'me' => $this->workload->counselorDashboard($request->user()),
            'coordinator' => app(BkAccessService::class)->canMonitorWorkload($request->user())
                ? $this->workload->coordinatorDashboard($request->user())
                : null,
            'candidates' => app(BkAccessService::class)->canMonitorWorkload($request->user())
                ? $this->workload->assignmentCandidates()
                : [],
        ]);
    }
}
