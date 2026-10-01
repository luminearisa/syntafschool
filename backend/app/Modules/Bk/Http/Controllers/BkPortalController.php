<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Bk\Application\Services\BkAccessService;
use App\Modules\Bk\Application\Services\BkAppointmentService;
use App\Modules\Bk\Application\Services\BkConsentService;
use App\Modules\Bk\Application\Services\BkSupportRequestService;
use App\Modules\Bk\Models\BkConsent;
use App\Modules\Bk\Models\BkSupportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal siswa & orang tua.
 *
 * Isinya sengaja sempit: mengajukan permintaan dukungan, melihat janji temu
 * sendiri (tanpa isi konseling), dan memutuskan persetujuan. Tidak ada endpoint
 * portal yang mengembalikan catatan konseling — wali yang menebak ID kasus pun
 * tetap ditolak `BkAccessService`.
 */
class BkPortalController extends BaseApiController
{
    public function __construct(
        private readonly BkSupportRequestService $requests,
        private readonly BkConsentService $consents,
        private readonly BkAppointmentService $appointments,
        private readonly BkAccessService $access,
    ) {
    }

    /** Permintaan dukungan saya/anak saya. */
    public function requests(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->requests->paginate($request->user(), $request->only(['status', 'request_type', 'queued']), (int) $request->integer('per_page', 20)),
            'Permintaan dukungan saya.',
        );
    }

    /** Siswa mengajukan permintaan konseling. */
    public function requestByStudent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:160'],
            'preferred_time_slot' => ['nullable', 'string', 'max:60'],
            'message' => ['nullable', 'string', 'max:1000'],
            'urgent' => ['nullable', 'boolean'],
        ]);

        return $this->createdResponse($this->requests->requestByStudent($request->user(), $validated), 'Permintaanmu sudah masuk ke antrean BK.');
    }

    /** Orang tua mengajukan dukungan untuk anaknya. */
    public function requestByGuardian(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'topic' => ['required', 'string', 'max:160'],
            'preferred_time_slot' => ['nullable', 'string', 'max:60'],
            'message' => ['nullable', 'string', 'max:1000'],
            'urgent' => ['nullable', 'boolean'],
        ]);

        return $this->createdResponse($this->requests->requestByGuardian($request->user(), $validated), 'Permintaan dukungan diterima sekolah.');
    }

    public function cancelRequest(Request $request, BkSupportRequest $supportRequest): JsonResponse
    {
        abort_unless((int) $supportRequest->requested_by === (int) $request->user()->id, 403, 'Hanya pengaju yang dapat membatalkan permintaan ini.');

        $supportRequest->forceFill(['status' => 'CANCELLED'])->save();

        return $this->successResponse($supportRequest->refresh(), 'Permintaan dibatalkan.');
    }

    /** Janji temu saya/anak saya — tanpa tujuan sesi bagi siswa & wali. */
    public function appointments(Request $request): JsonResponse
    {
        $paginator = $this->appointments->paginate($request->user(), $request->only(['upcoming', 'date', 'status']), (int) $request->integer('per_page', 20));

        $paginator->getCollection()->transform(fn ($appointment): array => [
            'id' => $appointment->id,
            'appointment_number' => $appointment->appointment_number,
            'student' => $appointment->student?->full_name,
            'scheduled_start' => $appointment->scheduled_start?->toDateTimeString(),
            'scheduled_end' => $appointment->scheduled_end?->toDateTimeString(),
            'mode' => $appointment->mode->value,
            'location' => $appointment->location,
            'status' => $appointment->status->value,
            'counselor' => $appointment->counselor?->name,
        ]);

        return $this->successResponse($paginator, 'Janji temu saya.');
    }

    /** Persetujuan yang menunggu keputusan wali. */
    public function consents(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->consents->paginate($request->user(), $request->only(['status', 'student_id', 'pending']), (int) $request->integer('per_page', 20)),
            'Persetujuan yang perlu keputusan Anda.',
        );
    }

    public function decideConsent(Request $request, BkConsent $consent): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:APPROVED,DECLINED,approved,declined'],
            'note' => ['nullable', 'string', 'max:191'],
        ]);

        return $this->successResponse($this->consents->decide($request->user(), $consent, $validated['decision'], $validated['note'] ?? null), 'Keputusan Anda tersimpan.');
    }

    /** Ringkasan portal: anak-anak saya, janji terdekat, persetujuan menunggu. */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $studentIds = $this->access->visibleStudentIds($user);

        return $this->successResponse([
            'student_ids' => $studentIds,
            'is_guardian' => $studentIds->isNotEmpty(),
            'pending_consents' => BkConsent::query()->whereIn('student_id', $studentIds->all())->pending()->count(),
            'queued_requests' => BkSupportRequest::query()->whereIn('student_id', $studentIds->all())->queued()->count(),
        ], 'Ringkasan portal BK.');
    }
}
