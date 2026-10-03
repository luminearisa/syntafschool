<?php

namespace App\Modules\SchoolOperations\Laundry\Http\Controllers;

use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryAnalyticsService;
use App\Modules\SchoolOperations\Laundry\Application\Services\LaundryEntitlementService;
use App\Modules\SchoolOperations\Laundry\Models\LaundryEntitlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Jatah gratis santri (§6, §13, §14, §51, §52, §61).
 * Adjustment wajib izin `laundry.quota.adjust` + reason (Rule 15/24).
 */
class EntitlementController extends Controller
{
    public function __construct(
        private readonly LaundryEntitlementService $entitlements,
        private readonly LaundryAnalyticsService $analytics,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('laundry.quota.view') || abort(403);

        $rows = LaundryEntitlement::query()
            ->with('student:id,full_name,nis')
            ->when($request->string('period_key')->toString(), fn ($q, $v) => $q->where('period_key', $v))
            ->when($request->string('status')->toString(), fn ($q, $v) => $q->where('status', $v))
            ->when($request->integer('student_id') ?: null, fn ($q, $v) => $q->where('student_id', $v))
            ->orderByDesc('period_start')
            ->orderBy('student_id')
            ->paginate(min(100, max(10, $request->integer('per_page', 25))));

        return response()->json([
            'data' => collect($rows->items())->map(fn (LaundryEntitlement $e): array => array_merge(
                $this->entitlements->present($e),
                [
                    'student_name' => $e->student?->full_name,
                    'nis' => $e->student?->nis,
                ]
            ))->all(),
            'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage()],
        ]);
    }

    /** Jatah berjalan satu santri (§61: /entitlements/{studentId}). */
    public function show(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('laundry.quota.view') || abort(403);

        return response()->json(['data' => [
            'current' => $this->entitlements->currentState($studentId, $request->input('date')),
            'history' => $this->entitlements->historyFor($studentId),
        ]]);
    }

    public function transactions(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('laundry.quota.view') || abort(403);

        return response()->json(['data' => $this->entitlements->transactionsFor(
            $studentId,
            $request->input('period_key'),
        )]);
    }

    /** §14: adjustment beralasan — before/after diaudit. */
    public function adjust(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('laundry.quota.adjust') || abort(403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
            'date' => ['nullable', 'date'],
        ]);

        $state = $this->entitlements->adjust(
            $studentId,
            (float) $data['amount'],
            (string) $data['reason'],
            $request->user(),
            $data['date'] ?? null,
        );

        return response()->json(['data' => $state]);
    }

    /** §52: statement laundry per periode. */
    public function statement(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('laundry.quota.view') || abort(403);

        return response()->json(['data' => $this->analytics->studentStatement(
            $studentId,
            $request->input('period'),
        )]);
    }
}
