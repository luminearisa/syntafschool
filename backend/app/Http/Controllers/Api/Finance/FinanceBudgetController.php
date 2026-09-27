<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceBudgetRequest;
use App\Http\Resources\FinanceBudgetResource;
use App\Models\FinanceBudget;
use App\Services\Finance\FinanceBudgetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Anggaran (§8) + alur approval, laporan Budget vs Actual (§9) dan pemeriksaan
 * ketersediaan anggaran untuk pengeluaran (§10).
 *
 * CRUD hanya boleh saat draft/ditolak; setelah diajukan, perubahan nominal
 * diblokir agar yang disetujui = yang tercatat. Aksi submit/approve/reject/
 * close/cancel lewat `FinanceBudgetService` yang menegakkan pemisahan pengaju
 * vs penyetuju — controller ini tidak pernah menghitung realisasi sendiri.
 */
class FinanceBudgetController extends BaseApiController
{
    public function __construct(private readonly FinanceBudgetService $budgets) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinanceBudget::query()
            ->with(['account', 'costCenter', 'approvedBy'])
            ->when($request->filled('fiscal_year'), fn (Builder $q) => $q->where('fiscal_year', (int) $request->input('fiscal_year')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('account_id'), fn (Builder $q) => $q->where('account_id', (int) $request->input('account_id')))
            ->when($request->filled('cost_center_id'), fn (Builder $q) => $q->where('cost_center_id', (int) $request->input('cost_center_id')))
            ->orderByDesc('fiscal_year')->orderBy('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceBudgetResource::collection($query->get()), 'Daftar anggaran berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceBudgetResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['statuses' => FinanceBudget::STATUSES, 'status_labels' => FinanceBudget::STATUS_LABELS]]),
            'Daftar anggaran berhasil diambil',
        );
    }

    /**
     * Anggaran plus posisinya (§9/§10): actual dari buku besar, committed dari
     * pengajuan yang berjalan, available hasil pengurangannya.
     */
    public function show(Request $request, FinanceBudget $budget): JsonResponse
    {
        $budget->load(['account', 'costCenter', 'submittedBy', 'approvedBy', 'closedBy', 'cancelledBy']);

        $payload = (new FinanceBudgetResource($budget))->resolve($request);
        $payload['position'] = $this->budgets->position($budget);

        return $this->successResponse($payload, 'Detail anggaran berhasil diambil');
    }

    public function store(FinanceBudgetRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->assertUniqueScope($data, null);

        $budget = FinanceBudget::create([
            ...$data,
            'status' => FinanceBudget::STATUS_DRAFT,
            'used_amount' => 0,
        ]);

        return $this->createdResponse(new FinanceBudgetResource($budget->load(['account', 'costCenter'])), 'Anggaran berhasil dibuat');
    }

    public function update(FinanceBudgetRequest $request, FinanceBudget $budget): JsonResponse
    {
        $this->assertEditable($budget);

        $data = $request->validated();

        // Update parsial (PATCH) boleh hanya mengirim sebagian field; scope unik
        // harus dihitung dari nilai efektif (yang dikirim menimpa yang tersimpan).
        $scope = [
            'fiscal_year' => $data['fiscal_year'] ?? $budget->fiscal_year,
            'account_id' => $data['account_id'] ?? $budget->account_id,
            'cost_center_id' => array_key_exists('cost_center_id', $data) ? $data['cost_center_id'] : $budget->cost_center_id,
            'period' => array_key_exists('period', $data) ? $data['period'] : $budget->period,
        ];
        $this->assertUniqueScope($scope, $budget->id);

        $budget->update($data);

        return $this->successResponse(new FinanceBudgetResource($budget->fresh(['account', 'costCenter'])), 'Anggaran berhasil diperbarui');
    }

    public function destroy(FinanceBudget $budget): JsonResponse
    {
        $this->assertEditable($budget);

        $budget->delete();

        return $this->successResponse(null, 'Anggaran berhasil dihapus');
    }

    public function submit(Request $request, FinanceBudget $budget): JsonResponse
    {
        $updated = $this->budgets->submit($budget, $request->user());

        return $this->successResponse(new FinanceBudgetResource($updated->load(['account', 'costCenter'])), 'Anggaran berhasil diajukan');
    }

    public function approve(Request $request, FinanceBudget $budget): JsonResponse
    {
        $updated = $this->budgets->approve($budget, $request->user());

        return $this->successResponse(new FinanceBudgetResource($updated->load(['account', 'costCenter', 'approvedBy'])), 'Anggaran berhasil disetujui');
    }

    public function reject(Request $request, FinanceBudget $budget): JsonResponse
    {
        $data = $request->validate(['reject_reason' => ['required', 'string', 'max:500']]);

        $updated = $this->budgets->reject($budget, (string) $data['reject_reason'], $request->user());

        return $this->successResponse(new FinanceBudgetResource($updated->load(['account', 'costCenter'])), 'Anggaran ditolak');
    }

    /** Tutup anggaran yang sudah berlaku (§8 CLOSED) — realisasinya tetap terbaca. */
    public function close(Request $request, FinanceBudget $budget): JsonResponse
    {
        $updated = $this->budgets->close($budget, $request->user());

        return $this->successResponse(
            new FinanceBudgetResource($updated->load(['account', 'costCenter', 'closedBy'])),
            'Anggaran berhasil ditutup',
        );
    }

    /** Batalkan anggaran yang belum pernah berlaku (§8 CANCELLED), wajib disertai alasan. */
    public function cancel(Request $request, FinanceBudget $budget): JsonResponse
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);

        $updated = $this->budgets->cancel($budget, (string) $data['cancel_reason'], $request->user());

        return $this->successResponse(
            new FinanceBudgetResource($updated->load(['account', 'costCenter', 'cancelledBy'])),
            'Anggaran dibatalkan',
        );
    }

    /**
     * Laporan Budget vs Actual (§9): Budget, Actual dari buku besar, Variance,
     * Utilization, plus Committed/Available (§10) per anggaran.
     */
    public function positions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'fiscal_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'period' => ['nullable', 'string', 'max:20'],
            'account_id' => ['nullable', 'integer'],
            'cost_center_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(FinanceBudget::STATUSES)],
        ]);

        return $this->successResponse(
            $this->budgets->positions($filters),
            'Budget vs actual berhasil diambil',
        );
    }

    /**
     * "Check Budget → Available?" (§10) untuk satu nominal — dipakai form
     * pengajuan pengeluaran menampilkan sisa anggaran sebelum dikirim.
     */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:finance_accounts,id'],
            'cost_center_id' => ['required', 'integer', 'exists:finance_cost_centers,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['nullable', 'date'],
        ]);

        return $this->successResponse(
            $this->budgets->check(
                (int) $data['account_id'],
                (int) $data['cost_center_id'],
                (float) $data['amount'],
                $data['date'] ?? null,
            ),
            'Ketersediaan anggaran berhasil diperiksa',
        );
    }

    /** Hanya draft/ditolak yang boleh diubah atau dihapus. */
    protected function assertEditable(FinanceBudget $budget): void
    {
        if (! in_array($budget->status, [FinanceBudget::STATUS_DRAFT, FinanceBudget::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'status' => "Anggaran berstatus {$budget->status} tidak boleh diubah. Buat anggaran baru untuk koreksi.",
            ]);
        }
    }

    /**
     * Satu kombinasi (tahun, akun, cost center, periode) hanya boleh punya satu
     * anggaran — mencegah dua baris bersaing mengukur realisasi yang sama.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertUniqueScope(array $data, ?int $ignoreId): void
    {
        $exists = FinanceBudget::query()
            ->where('fiscal_year', $data['fiscal_year'])
            ->where('account_id', $data['account_id'])
            ->where('cost_center_id', $data['cost_center_id'] ?? null)
            ->where('period', $data['period'] ?? null)
            ->when($ignoreId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'account_id' => 'Anggaran untuk kombinasi tahun/akun/cost center/periode ini sudah ada.',
            ]);
        }
    }
}
