<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\FinancePeriod;
use App\Models\FinancePeriodReopenRequest;
use App\Services\Finance\Accounting\PeriodReopenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Permohonan membuka kembali periode yang sudah ditutup.
 *
 * Tutup buku mengikat, dan membukanya kembali adalah keputusan dua orang:
 * pengaju beserta alasannya, lalu pemutus yang berbeda.
 */
class FinancePeriodReopenController extends BaseApiController
{
    public function __construct(private readonly PeriodReopenService $reopen) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->reopen->paginate((int) $request->input('per_page', 25)));
    }

    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_id' => ['required', 'integer', 'exists:finance_periods,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $period = FinancePeriod::query()->findOrFail($data['period_id']);

        return $this->createdResponse(
            $this->reopen->request($period, (string) $data['reason'], $request->user()),
            'Permohonan pembukaan periode diajukan dan menunggu keputusan.',
        );
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $permohonan = FinancePeriodReopenRequest::query()->findOrFail($id);

        return $this->successResponse(
            $this->reopen->decide($permohonan, (bool) $data['approved'], $request->user(), $data['note'] ?? null),
            $data['approved'] ? 'Periode dibuka kembali.' : 'Permohonan ditolak.',
        );
    }

    public function history(int $periodId): JsonResponse
    {
        $period = FinancePeriod::query()->findOrFail($periodId);

        return $this->successResponse($this->reopen->history($period));
    }
}
