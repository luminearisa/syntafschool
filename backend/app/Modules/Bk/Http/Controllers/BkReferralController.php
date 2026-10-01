<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Bk\Application\Services\BkReferralService;
use App\Modules\Bk\Http\Requests\BkReferralRequest;
use App\Modules\Bk\Http\Requests\BkScreeningRequest;
use App\Modules\Bk\Models\BkReferral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Referral BK: pengajuan, penerimaan, screening, dan konversi menjadi kasus.
 *
 * Guru/siswa/wali boleh mengajukan lewat endpoint yang sama; yang membedakan
 * adalah peran dan cakupan baris yang ditegakkan `BkAccessService`.
 */
class BkReferralController extends BaseApiController
{
    public function __construct(private readonly BkReferralService $referrals)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $this->referrals->paginate($request->user(), $request->only([
            'status', 'priority', 'category', 'source', 'student_id', 'queue', 'search',
        ]), (int) $request->integer('per_page', 20));

        return $this->successResponse($data, 'Daftar referral.');
    }

    public function store(BkReferralRequest $request): JsonResponse
    {
        $referral = $this->referrals->create($request->user(), $request->validated());

        return $this->createdResponse($referral, 'Referral diterima dan akan diperiksa tim BK.');
    }

    public function show(Request $request, BkReferral $referral): JsonResponse
    {
        $this->authorize('view', $referral);

        $data = $referral->load(['student:id,full_name,nis', 'referredBy:id,name', 'case:id,case_number,status', 'screenings']);

        return $this->successResponse($data, 'Detail referral.');
    }

    public function receive(Request $request, BkReferral $referral): JsonResponse
    {
        $this->authorize('receive', $referral);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->successResponse($this->referrals->receive($request->user(), $referral, $validated['note'] ?? null), 'Referral diterima BK.');
    }

    public function screen(BkScreeningRequest $request, BkReferral $referral): JsonResponse
    {
        $this->authorize('screen', $referral);

        $screening = app(\App\Modules\Bk\Application\Services\BkScreeningService::class)
            ->record($request->user(), $referral, $request->validated());

        return $this->createdResponse($screening, 'Screening tersimpan.');
    }

    public function openCase(Request $request, BkReferral $referral): JsonResponse
    {
        $this->authorize('update', $referral);

        $validated = $request->validate([
            'case_type' => ['required', 'string'],
            'category' => ['nullable', 'string'],
            'title' => ['nullable', 'string', 'max:160'],
            'priority' => ['nullable', 'string'],
            'severity' => ['nullable', 'string'],
            'confidentiality_level' => ['nullable', 'string'],
            'target_review_date' => ['nullable', 'date'],
        ]);

        $case = $this->referrals->openCase($request->user(), $referral, $validated);

        return $this->createdResponse($case, 'Kasus dibuka dari referral.');
    }

    public function reject(Request $request, BkReferral $referral): JsonResponse
    {
        $this->authorize('update', $referral);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->successResponse($this->referrals->reject($request->user(), $referral, $validated['reason']), 'Referral ditolak.');
    }

    public function cancel(Request $request, BkReferral $referral): JsonResponse
    {
        $this->authorize('view', $referral);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->successResponse($this->referrals->cancel($request->user(), $referral, $validated['reason']), 'Referral dibatalkan.');
    }
}
