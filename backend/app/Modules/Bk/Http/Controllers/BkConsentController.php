<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Modules\Bk\Application\Services\BkAccessService;
use App\Modules\Bk\Application\Services\BkConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Persetujuan (consent) granular.
 *
 * Melihat daftar persetujuan bukan hak semua orang: wali hanya melihat anaknya,
 * siswa hanya dirinya, dan tim BK melihat sesuai cakupan kasusnya.
 */
class BkConsentController extends Controller
{
    use ApiResponse;


    public function __construct(private readonly BkConsentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('bk.consent.view') || abort(403);

        return $this->successResponse([
            'consents' => $this->service->paginate($request->user(), $request->only([
                'student_id', 'case_id', 'scope', 'status', 'expiring_days',
            ]), (int) $request->input('per_page', 25)),
            'scopes' => $this->service->scopes(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('bk.consent.request') || abort(403);

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'scope' => ['required', 'string'],
            'case_id' => ['nullable', 'integer', 'exists:bk_cases,id'],
            'purpose' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $consent = $this->service->request($request->user(), (int) $data['student_id'], $data);

        return $this->createdResponse($consent, 'Permintaan persetujuan dibuat.');
    }

    public function grant(Request $request, int $id): JsonResponse
    {
        $consent = \App\Modules\Bk\Models\BkConsent::query()->findOrFail($id);

        // Wali pemilik anak, atau staf BK yang mencatat keputusan wali secara tertulis.
        $user = $request->user();
        $isGuardian = app(BkAccessService::class)->isGuardianOf($user, (int) $consent->student_id);

        abort_unless($isGuardian || $user->can('bk.consent.grant'), 403);

        $data = $request->validate([
            'note' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date'],
            'document_id' => ['nullable', 'integer', 'exists:bk_case_documents,id'],
            'is_standing' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse($this->service->grant($user, $consent, $data), 'Persetujuan dicatat.');
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $consent = \App\Modules\Bk\Models\BkConsent::query()->findOrFail($id);
        $user = $request->user();

        $isGuardian = app(BkAccessService::class)->isGuardianOf($user, (int) $consent->student_id);
        abort_unless($isGuardian || $user->can('bk.consent.revoke'), 403);

        $data = $request->validate(['reason' => ['required', 'string']]);

        return $this->successResponse($this->service->revoke($user, $consent, $data['reason']), 'Persetujuan dicabut.');
    }
}
