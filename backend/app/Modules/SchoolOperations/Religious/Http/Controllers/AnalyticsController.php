<?php

namespace App\Modules\SchoolOperations\Religious\Http\Controllers;

use App\Modules\SchoolOperations\Religious\Application\Services\ReligiousAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Analitik & laporan keagamaan (§71-§74). Seluruh angka bersifat deskriptif. */
class AnalyticsController extends Controller
{
    public function __construct(private readonly ReligiousAnalyticsService $analytics)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('religious.analytics.view') || abort(403);

        return response()->json(['data' => $this->analytics->metrics(
            (string) $request->input('from', now()->startOfMonth()->toDateString()),
            (string) $request->input('to', now()->toDateString()),
        )]);
    }

    public function prayerReport(Request $request): JsonResponse
    {
        $request->user()->can('religious.report.view') || abort(403);

        return response()->json(['data' => $this->analytics->prayerReport(
            (string) $request->input('from', now()->startOfMonth()->toDateString()),
            (string) $request->input('to', now()->toDateString()),
            (string) $request->input('group_by', 'DATE'),
            $request->only(['campus_id', 'mosque_id']),
        )]);
    }

    public function fridayReport(Request $request): JsonResponse
    {
        $request->user()->can('religious.report.view') || abort(403);

        return response()->json(['data' => $this->analytics->fridayReport(
            (string) $request->input('from', now()->startOfMonth()->toDateString()),
            (string) $request->input('to', now()->toDateString()),
            $request->integer('mosque_id') ?: null,
        )]);
    }

    public function activityReport(Request $request): JsonResponse
    {
        $request->user()->can('religious.report.view') || abort(403);

        return response()->json(['data' => $this->analytics->activityReport(
            (string) $request->input('from', now()->startOfMonth()->toDateString()),
            (string) $request->input('to', now()->toDateString()),
            $request->only(['activity_type']),
        )]);
    }

    public function ramadanReport(Request $request, int $programId): JsonResponse
    {
        $request->user()->can('religious.report.view') || abort(403);

        return response()->json(['data' => $this->analytics->ramadanReport($programId)]);
    }
}
