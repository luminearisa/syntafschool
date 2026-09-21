<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizRegistrationRequest;
use App\Http\Resources\TahfizParticipantResource;
use App\Http\Resources\TahfizRegistrationRequestResource;
use App\Models\TahfizEnrollment;
use App\Models\TahfizProgram;
use App\Models\TahfizRegistrationRequest as RegistrationRequest;
use App\Services\Tahfiz\ParticipantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pendaftaran mandiri peserta umum.
 *
 * `store` dibuka untuk umum (tanpa login, lihat route): calon peserta mengisi
 * form pendaftaran. Admin me-review, lalu menyetujui atau menolak. Persetujuan
 * membuatkan peserta + enrollment status `registered`.
 */
class TahfizRegistrationController extends BaseApiController
{
    protected array $filterable = ['status', 'program_id'];

    protected array $with = ['program', 'participant'];

    public function __construct(protected ParticipantService $participants)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = RegistrationRequest::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        $query->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizRegistrationRequestResource::collection($query->get()),
                'Daftar pendaftaran peserta umum berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizRegistrationRequestResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar pendaftaran peserta umum berhasil diambil'
        );
    }

    /**
     * Pendaftaran mandiri — bisa diakses tanpa login.
     */
    public function store(TahfizRegistrationRequest $request): JsonResponse
    {
        $program = TahfizProgram::query()->whereKey($request->input('program_id'))->firstOrFail();

        if (! $program->self_registration) {
            throw ValidationException::withMessages([
                'program_id' => "Program {$program->name} tidak menerima pendaftaran mandiri.",
            ]);
        }

        $registration = RegistrationRequest::create($request->validated());

        return $this->createdResponse(
            new TahfizRegistrationRequestResource($registration->load($this->with)),
            'Pendaftaran berhasil dikirim. Admin akan mereview dan menghubungi Anda untuk placement test.'
        );
    }

    /**
     * Setujui pendaftaran: buat peserta + enrollment.
     */
    public function approve(Request $request, RegistrationRequest $registration): JsonResponse
    {
        $request->validate(['review_note' => ['nullable', 'string', 'max:500']]);

        $participant = $this->participants->approveRegistration(
            $registration,
            $request->user()?->id ?? 0
        );

        // Enrollment status registered — menunggu placement test & approval.
        $participant->enrollments()->create([
            'code' => TahfizEnrollment::nextCode(),
            'program_id' => $registration->program_id,
            'status' => 'registered',
            'enrolled_at' => now()->toDateString(),
            'created_by' => $request->user()?->id,
            'notes' => $request->input('review_note'),
        ]);

        return $this->successResponse(
            new TahfizParticipantResource($participant->load(['profile'])),
            'Pendaftaran disetujui. Peserta dan enrollment dibuat; lanjutkan dengan placement test.'
        );
    }

    /**
     * Tolak pendaftaran.
     */
    public function reject(Request $request, RegistrationRequest $registration): JsonResponse
    {
        $request->validate(['review_note' => ['required', 'string', 'max:500']]);

        $this->participants->rejectRegistration(
            $registration,
            $request->user()?->id ?? 0,
            $request->input('review_note')
        );

        return $this->successResponse(
            new TahfizRegistrationRequestResource($registration->refresh()->load($this->with)),
            'Pendaftaran ditolak.'
        );
    }
}
