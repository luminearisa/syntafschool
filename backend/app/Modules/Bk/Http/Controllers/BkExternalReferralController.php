<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Modules\Bk\Application\Services\BkExternalReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rujukan eksternal: penyedia, tahapan rujukan, hasil, dan tindak lanjut.
 *
 * Pengiriman ke pihak luar hanya lolos bila persetujuan wali sudah berlaku; itu
 * ditegakkan layanan, bukan ditebak dari status di layar.
 */
class BkExternalReferralController extends Controller
{
    use ApiResponse;


    public function __construct(private readonly BkExternalReferralService $service)
    {
    }

    // ------------------------------------------------------------- penyedia

    public function providers(Request $request): JsonResponse
    {
        $request->user()->can('bk.referral-provider.view') || abort(403);

        return $this->successResponse($this->service->providers($request->user(), $request->only(['type', 'active', 'search']), (int) $request->input('per_page', 25)));
    }

    public function storeProvider(Request $request): JsonResponse
    {
        $request->user()->can('bk.referral-provider.manage') || abort(403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'provider_type' => ['required', 'string'],
            'contact_person' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string'],
            'is_external' => ['nullable', 'boolean'],
            'requires_consent' => ['nullable', 'boolean'],
            'service_scope' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->createdResponse($this->service->createProvider($request->user(), $data), 'Penyedia rujukan dibuat.');
    }

    public function updateProvider(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.referral-provider.manage') || abort(403);

        $provider = \App\Modules\Bk\Models\BkReferralProvider::query()->findOrFail($id);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'provider_type' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'requires_consent' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->successResponse($this->service->updateProvider($request->user(), $provider, $data), 'Penyedia rujukan diperbarui.');
    }

    // ------------------------------------------------------------- rujukan

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('bk.external-referral.view') || abort(403);

        return $this->successResponse($this->service->paginate($request->user(), $request->only([
            'student_id', 'case_id', 'status', 'provider_id', 'from', 'to',
        ]), (int) $request->input('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('bk.external-referral.create') || abort(403);

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'case_id' => ['nullable', 'integer', 'exists:bk_cases,id'],
            'referral_provider_id' => ['nullable', 'integer', 'exists:bk_referral_providers,id'],
            'urgency' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
            'assessment' => ['nullable', 'string'],
            'referral_date' => ['nullable', 'date'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return $this->createdResponse($this->service->create($request->user(), $data), 'Rujukan eksternal dibuat sebagai draf.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $referral = \App\Modules\Bk\Models\BkExternalReferral::query()
            ->with(['student:id,full_name,nis', 'provider', 'consent', 'case:id,case_number', 'followUp:id,title,status,due_date'])
            ->findOrFail($id);

        $this->service->assertCanView($request->user(), $referral);

        return $this->successResponse([
            'referral' => $referral,
            'consent_valid' => $this->service->hasValidConsent($referral),
            'clinic_source_of_truth' => 'Rekam medis tetap milik Modul Clinic/Health; modul BK hanya menyimpan ringkasan yang boleh dibagikan.',
        ]);
    }

    public function assessment(Request $request, int $id): JsonResponse
    {
        $referral = \App\Modules\Bk\Models\BkExternalReferral::query()->findOrFail($id);
        $request->user()->can('bk.external-referral.manage') || abort(403);

        $data = $request->validate(['assessment' => ['required', 'string']]);

        return $this->successResponse($this->service->recordAssessment($request->user(), $referral, $data['assessment']), 'Asesmen rujukan dicatat.');
    }

    public function requestConsent(Request $request, int $id): JsonResponse
    {
        $referral = \App\Modules\Bk\Models\BkExternalReferral::query()->findOrFail($id);
        $request->user()->can('bk.external-referral.manage') || abort(403);

        $data = $request->validate(['purpose' => ['nullable', 'string']]);

        return $this->successResponse($this->service->requestConsent($request->user(), $referral, $data['purpose'] ?? null), 'Permintaan persetujuan wali dikirim.');
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $referral = \App\Modules\Bk\Models\BkExternalReferral::query()->findOrFail($id);
        $request->user()->can('bk.external-referral.manage') || abort(403);

        $data = $request->validate([
            'referral_date' => ['nullable', 'date'],
            'referral_document_id' => ['nullable', 'integer', 'exists:bk_case_documents,id'],
        ]);

        return $this->successResponse($this->service->submit($request->user(), $referral, $data), 'Rujukan dikirim ke pihak luar.');
    }

    public function appointment(Request $request, int $id): JsonResponse
    {
        $referral = \App\Modules\Bk\Models\BkExternalReferral::query()->findOrFail($id);
        $request->user()->can('bk.external-referral.manage') || abort(403);

        $data = $request->validate([
            'appointment_at' => ['required', 'date'],
            'appointment_location' => ['nullable', 'string', 'max:160'],
        ]);

        return $this->successResponse($this->service->scheduleAppointment($request->user(), $referral, $data), 'Janji temu pihak luar dijadwalkan.');
    }

    public function result(Request $request, int $id): JsonResponse
    {
        $referral = \App\Modules\Bk\Models\BkExternalReferral::query()->findOrFail($id);
        $request->user()->can('bk.external-referral.manage') || abort(403);

        $data = $request->validate([
            'outcome' => ['nullable', 'string'],
            'external_summary' => ['nullable', 'string'],
            'clinic_reference' => ['nullable', 'string', 'max:64'],
            'result_document_id' => ['nullable', 'integer', 'exists:bk_case_documents,id'],
            'outcome_shared_with_parent' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse($this->service->recordResult($request->user(), $referral, $data), 'Hasil rujukan dicatat sebagai ringkasan.');
    }

    public function followUp(Request $request, int $id): JsonResponse
    {
        $referral = \App\Modules\Bk\Models\BkExternalReferral::query()->findOrFail($id);
        $request->user()->can('bk.external-referral.manage') || abort(403);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
        ]);

        return $this->successResponse($this->service->createFollowUp($request->user(), $referral, $data), 'Tindak lanjut rujukan dibuat.');
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $referral = \App\Modules\Bk\Models\BkExternalReferral::query()->findOrFail($id);
        $request->user()->can('bk.external-referral.manage') || abort(403);

        $data = $request->validate(['follow_up_plan' => ['nullable', 'string']]);

        return $this->successResponse($this->service->complete($request->user(), $referral, $data), 'Rujukan diselesaikan.');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $referral = \App\Modules\Bk\Models\BkExternalReferral::query()->findOrFail($id);
        $request->user()->can('bk.external-referral.manage') || abort(403);

        $data = $request->validate(['reason' => ['required', 'string']]);

        return $this->successResponse($this->service->cancel($request->user(), $referral, $data['reason']), 'Rujukan dibatalkan.');
    }
}
