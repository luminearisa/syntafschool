<?php

namespace App\Modules\SchoolOperations\Laundry\Http\Controllers;

use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryAnalyticsService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Dashboard & laporan admin (§48–§51, §73) + audit trail (§75). */
class DashboardController extends Controller
{
    public function __construct(
        private readonly LaundryAnalyticsService $analytics,
        private readonly LaundryAuditService $audit,
    ) {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $request->user()->can('laundry.dashboard.view') || abort(403);

        return response()->json(['data' => $this->analytics->dashboard($request->input('date'))]);
    }

    public function dailyOperations(Request $request): JsonResponse
    {
        $request->user()->can('laundry.report') || abort(403);

        return response()->json(['data' => $this->analytics->dailyOperations($request->input('date'))]);
    }

    public function freeVsPaid(Request $request): JsonResponse
    {
        $request->user()->can('laundry.report') || abort(403);

        return response()->json(['data' => $this->analytics->freeVsPaid(
            $request->input('from'),
            $request->input('to'),
        )]);
    }

    public function quotaReport(Request $request): JsonResponse
    {
        $request->user()->can('laundry.report') || abort(403);

        return response()->json(['data' => $this->analytics->quotaReport(
            $request->input('period'),
            $request->only(['dormitory_id']),
        )]);
    }

    public function walletReport(Request $request): JsonResponse
    {
        $request->user()->can('laundry.payment.view') || abort(403);

        return response()->json(['data' => $this->analytics->walletReport(
            $request->input('from'),
            $request->input('to'),
        )]);
    }

    public function operationalReport(Request $request): JsonResponse
    {
        $request->user()->can('laundry.report') || abort(403);

        return response()->json(['data' => $this->analytics->operationalReport(
            $request->input('from'),
            $request->input('to'),
        )]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $request->user()->can('laundry.audit') || abort(403);

        $rows = $this->audit->query(
            $request->input('subject_type'),
            $request->input('subject_id') ? (int) $request->input('subject_id') : null,
            $request->input('action'),
        )->paginate(min(100, max(10, $request->integer('per_page', 25))));

        return response()->json(['data' => $rows->items(), 'meta' => [
            'total' => $rows->total(), 'page' => $rows->currentPage(),
        ]]);
    }
}
