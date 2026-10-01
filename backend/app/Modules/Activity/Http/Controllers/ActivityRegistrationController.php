<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Activity\Application\Services\ActivityRegistrationService;
use App\Modules\Activity\Models\ActivityConsent;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pendaftaran, seleksi, dan daftar tunggu (\§21\–24).
 */
class ActivityRegistrationController extends BaseApiController
{
    public function __construct(private readonly ActivityRegistrationService $registrations) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->registrations->paginate($request->all(), $request->user()));
    }

    /** Pendaftaran saya (portal siswa/orang tua). */
    public function mine(Request $request): JsonResponse
    {
        return $this->successResponse($this->registrations->paginate(
            array_merge($request->all(), ['mine' => true]),
            $request->user(),
        ));
    }

    public function store(Request $request, ActivityProgram $program): JsonResponse
    {
        $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'motivation' => ['nullable', 'string', 'max:2000'],
            'recommendation' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
            'priority' => ['nullable', 'integer'],
        ]);

        $registration = $this->registrations->start(
            $request->user(),
            $program,
            (int) $request->input('student_id'),
            $request->all(),
        );

        return $this->createdResponse($registration, 'Pendaftaran dicatat ('.$registration->status->label().').');
    }

    public function show(ActivityRegistration $registration): JsonResponse
    {
        return $this->successResponse([
            'registration' => $registration->load(['program:id,name,code', 'student:id,full_name,nis', 'decider:id,name']),
            'history' => $this->registrations->history($registration),
            'selection' => $registration->selectionResults()->get(),
            'consents' => $registration->consents()->get(),
        ]);
    }

    public function submit(ActivityRegistration $registration, Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->registrations->submit($registration, $request->user()),
            'Pendaftaran diajukan.',
        );
    }

    public function decide(ActivityRegistration $registration, Request $request): JsonResponse
    {
        $request->validate([
            'decision' => ['required', 'string', 'in:APPROVE,REJECT,WAITLIST,REVISE,SELECT'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'ignore_pending_consent' => ['nullable', 'boolean'],
            'membership' => ['nullable', 'array'],
        ]);

        return $this->successResponse(
            $this->registrations->decide($registration, $request->user(), $request->input('decision'), $request->all()),
            'Keputusan pendaftaran dicatat.',
        );
    }

    public function cancel(ActivityRegistration $registration, Request $request): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return $this->successResponse(
            $this->registrations->cancel($registration, $request->user(), $request->input('reason')),
            'Pendaftaran dibatalkan.',
        );
    }

    /** Catat hasil tahap seleksi (tes coba, audisi, wawancara). */
    public function select(ActivityRegistration $registration, Request $request): JsonResponse
    {
        $request->validate([
            'stage' => ['required', 'string', 'in:SCREENING,TRIAL,AUDITION,INTERVIEW,ASSESSMENT'],
            'score' => ['nullable', 'numeric'],
            'rank' => ['nullable', 'integer'],
            'decision' => ['nullable', 'string', 'in:PENDING,SELECTED,WAITLISTED,REJECTED'],
            'notes' => ['nullable', 'string'],
            'criteria' => ['nullable', 'array'],
            'apply_decision' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse(
            $this->registrations->recordSelection($registration, $request->user(), $request->all()),
            'Hasil seleksi dicatat.',
        );
    }

    /** Daftar tunggu satu program (\§23). */
    public function waitlist(ActivityProgram $program): JsonResponse
    {
        return $this->successResponse($this->registrations->waitlistOverview($program));
    }

    /** Persetujuan orang tua yang menunggu \— memakai layanan consent via registrasi. */
    public function consentPending(Request $request): JsonResponse
    {
        return $this->successResponse(
            ActivityConsent::query()
                ->with(['program:id,name', 'student:id,full_name'])
                ->whereIn('student_id', $request->user() ? [$request->integer('student_id')] : [])
                ->where('decision', 'PENDING')
                ->get(),
        );
    }
}
