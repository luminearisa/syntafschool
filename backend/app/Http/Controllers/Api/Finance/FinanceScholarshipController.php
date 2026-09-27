<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceScholarshipRequest;
use App\Http\Resources\FinanceScholarshipResource;
use App\Models\FinanceScholarship;
use App\Services\Finance\FinanceScholarshipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Beasiswa (§18). Dibuat draft; approve/reject/cancel lewat `FinanceScholarshipService`
 * yang menegakkan transisi status. Beasiswa aktif mengurangi tagihan saat billing
 * digenerate — karena itu approval dijaga permission terpisah.
 */
class FinanceScholarshipController extends BaseApiController
{
    public function __construct(private readonly FinanceScholarshipService $scholarships)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = FinanceScholarship::query()
            ->with(['student', 'feeType', 'approvedBy'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', (int) $request->input('student_id')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceScholarshipResource::collection($query->get()), 'Daftar beasiswa berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceScholarshipResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['statuses' => FinanceScholarship::STATUSES]]),
            'Daftar beasiswa berhasil diambil',
        );
    }

    public function show(FinanceScholarship $scholarship): JsonResponse
    {
        $scholarship->load(['student', 'feeType', 'approvedBy']);

        return $this->successResponse(new FinanceScholarshipResource($scholarship), 'Detail beasiswa berhasil diambil');
    }

    public function store(FinanceScholarshipRequest $request): JsonResponse
    {
        $scholarship = FinanceScholarship::create([
            ...$request->validated(),
            'status' => FinanceScholarship::STATUS_DRAFT,
        ]);

        return $this->createdResponse(new FinanceScholarshipResource($scholarship->load(['student', 'feeType'])), 'Beasiswa berhasil dibuat (draft)');
    }

    public function update(FinanceScholarshipRequest $request, FinanceScholarship $scholarship): JsonResponse
    {
        if (! in_array($scholarship->status, [FinanceScholarship::STATUS_DRAFT, FinanceScholarship::STATUS_REJECTED], true)) {
            return $this->errorResponse("Beasiswa berstatus {$scholarship->status} tidak boleh diubah.", 422);
        }

        $scholarship->update($request->validated());

        return $this->successResponse(new FinanceScholarshipResource($scholarship->fresh(['student', 'feeType'])), 'Beasiswa berhasil diperbarui');
    }

    public function approve(Request $request, FinanceScholarship $scholarship): JsonResponse
    {
        $updated = $this->scholarships->approve($scholarship, $request->user());

        return $this->successResponse(new FinanceScholarshipResource($updated), 'Beasiswa disetujui');
    }

    public function reject(Request $request, FinanceScholarship $scholarship): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $updated = $this->scholarships->reject($scholarship, (string) $data['reason'], $request->user());

        return $this->successResponse(new FinanceScholarshipResource($updated), 'Beasiswa ditolak');
    }

    public function cancel(Request $request, FinanceScholarship $scholarship): JsonResponse
    {
        $updated = $this->scholarships->cancel($scholarship, $request->user());

        return $this->successResponse(new FinanceScholarshipResource($updated), 'Beasiswa dibatalkan');
    }

    public function destroy(FinanceScholarship $scholarship): JsonResponse
    {
        if ($scholarship->status === FinanceScholarship::STATUS_ACTIVE) {
            return $this->errorResponse('Beasiswa aktif tidak boleh dihapus; batalkan lebih dulu.', 422);
        }

        $scholarship->delete();

        return $this->successResponse(null, 'Beasiswa berhasil dihapus');
    }
}
