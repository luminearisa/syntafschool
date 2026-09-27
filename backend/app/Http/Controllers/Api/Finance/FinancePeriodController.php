<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\FinancePeriodResource;
use App\Models\FinancePeriod;
use App\Services\Finance\FinancePeriodService;
use App\Services\Finance\FinanceSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Periode fiskal & penutupan (§48, §49).
 *
 * Setelah ditutup, ledger engine menolak posting ke periode itu dan transaksi
 * lama tidak boleh diedit — koreksi lewat adjustment/reversal di periode terbuka.
 * Reopen dijaga permission `finance.closing.manage` dan dicatat audit.
 */
class FinancePeriodController extends BaseApiController
{
    public function __construct(
        private readonly FinancePeriodService $periods,
        private readonly FinanceSettingsService $settings,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = FinancePeriod::query()
            ->withCount('transactions')
            ->when($request->filled('fiscal_year'), fn (Builder $q) => $q->where('fiscal_year', (int) $request->input('fiscal_year')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->orderByDesc('start_date');

        if ($request->boolean('all')) {
            return $this->successResponse(FinancePeriodResource::collection($query->get()), 'Daftar periode berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 24)));

        return $this->successResponse(
            FinancePeriodResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['statuses' => FinancePeriod::STATUSES]]),
            'Daftar periode berhasil diambil',
        );
    }

    public function show(FinancePeriod $period): JsonResponse
    {
        $period->load('closedBy')->loadCount('transactions');

        return $this->successResponse(new FinancePeriodResource($period), 'Detail periode berhasil diambil');
    }

    /** Periode aktif + ringkasan status closing untuk dashboard. */
    public function summary(Request $request): JsonResponse
    {
        $fiscalYear = $request->filled('fiscal_year')
            ? (int) $request->input('fiscal_year')
            : (int) $this->settings->get('fiscal_year');

        return $this->successResponse(
            $this->periods->closingSummary($fiscalYear),
            'Ringkasan periode berhasil diambil',
        );
    }

    /** Buat periode bulanan untuk satu tahun fiskal (idempoten) — setup tahun ajaran baru. */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'start_month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $startMonth = (int) ($data['start_month'] ?? $this->settings->get('fiscal_start_month', 7));
        $periods = $this->periods->ensureMonthlyPeriods((int) $data['fiscal_year'], $startMonth);

        return $this->successResponse(
            FinancePeriodResource::collection(collect($periods)),
            'Periode fiskal berhasil disiapkan',
        );
    }

    public function close(Request $request, FinancePeriod $period): JsonResponse
    {
        $closed = $this->periods->close($period, $request->user());

        return $this->successResponse(new FinancePeriodResource($closed->load('closedBy')), 'Periode berhasil ditutup');
    }

    public function reopen(Request $request, FinancePeriod $period): JsonResponse
    {
        $opened = $this->periods->reopen($period, $request->user());

        return $this->successResponse(new FinancePeriodResource($opened), 'Periode berhasil dibuka kembali');
    }
}
