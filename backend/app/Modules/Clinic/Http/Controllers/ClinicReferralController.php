<?php

namespace App\Modules\Clinic\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Clinic\Application\Services\ClinicAccessService;
use App\Modules\Clinic\Application\Services\ClinicalRecordService;
use App\Modules\Clinic\Application\Services\ReferralService;
use App\Modules\Clinic\Domain\Enums\FollowUpStatus;
use App\Modules\Clinic\Domain\Enums\ReferralStatus;
use App\Modules\Clinic\Domain\Enums\ReferralUrgency;
use App\Modules\Clinic\Models\ClinicFollowUp;
use App\Modules\Clinic\Models\ClinicReferral;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rujukan & tindak lanjut.
 *
 * Rujukan tanpa hasil yang tercatat adalah rujukan yang hilang. Karena itu
 * setiap rujukan yang selesai otomatis melahirkan tindak lanjut (baca hasil),
 * dan tindak lanjut yang lewat tenggat dapat dilihat di dashboard.
 */
class ClinicReferralController extends BaseApiController
{
    public function __construct(
        private readonly ReferralService $referrals,
        private readonly ClinicalRecordService $records,
        private readonly ClinicAccessService $access,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->access->scopePatients(
            ClinicReferral::query()->with(['patient.student:id,nis,full_name', 'provider:id,name']),
            $request->user(),
        );

        $query
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('urgency'), fn ($q, $urgency) => $q->where('urgency', $urgency))
            ->when($request->boolean('open_only'), fn ($q) => $q->whereNotIn('status', ['COMPLETED', 'CANCELLED']))
            ->orderByDesc('referral_date');

        return $this->successResponse($query->limit(200)->get());
    }

    /** Buat rujukan dari sebuah kunjungan. */
    public function store(Request $request, ClinicVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $validated = $request->validate([
            'facility' => ['required', 'string', 'max:191'],
            'facility_type' => ['nullable', 'string', 'max:30'],
            'reason' => ['required', 'string', 'max:3000'],
            'diagnosis_summary' => ['nullable', 'string', 'max:255'],
            'urgency' => ['nullable', Rule::in(ReferralUrgency::values())],
            'referral_date' => ['nullable', 'date'],
            'transport' => ['nullable', 'string', 'max:60'],
            'emergency_id' => ['nullable', 'integer', 'exists:clinic_emergencies,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $referral = $this->referrals->create($visit, $validated, $request->user());

        return $this->createdResponse($referral, 'Rujukan '.$referral->referral_number.' dibuat');
    }

    public function show(Request $request, ClinicReferral $referral): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $referral->patient_id);

        return $this->successResponse($referral->load(['patient.student', 'visit:id,visit_number', 'followUps']));
    }

    /** Perbarui status perjalanan rujukan. */
    public function update(Request $request, ClinicReferral $referral): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $referral->patient_id);

        $validated = $request->validate([
            'status' => ['required', Rule::in(ReferralStatus::values())],
            'transport' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'outcome' => ['nullable', 'string', 'max:3000'],
            'arrived_at' => ['nullable', 'date'],
            'follow_up_date' => ['nullable', 'date'],
        ]);

        return $this->successResponse($this->referrals->updateStatus($referral, $validated, $request->user()), 'Status rujukan diperbarui');
    }

    /** Daftar tindak lanjut (termasuk yang terlambat). */
    public function followUps(Request $request): JsonResponse
    {
        $query = $this->access->scopePatients(
            ClinicFollowUp::query()->with(['patient.student:id,nis,full_name', 'assignee:id,name']),
            $request->user(),
        );

        $query
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('open_only'), fn ($q) => $q->whereIn('status', FollowUpStatus::values()))
            ->when($request->boolean('overdue_only'), fn ($q) => $q->where('status', '!=', 'COMPLETED')->whereDate('due_date', '<', now()->toDateString()))
            ->orderBy('due_date');

        return $this->successResponse($query->limit(200)->get());
    }

    /** Selesaikan tindak lanjut. */
    public function completeFollowUp(Request $request, ClinicFollowUp $followUp): JsonResponse
    {
        $this->access->assertCanSeePatient($request->user(), (int) $followUp->patient_id);

        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        return $this->successResponse(
            $this->records->completeFollowUp($followUp, $request->user(), $validated['notes'] ?? null),
            'Tindak lanjut diselesaikan',
        );
    }
}
