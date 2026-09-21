<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizEnrollmentRequest;
use App\Http\Requests\Tahfiz\TahfizEnrollmentTransitionRequest;
use App\Http\Resources\TahfizEnrollmentResource;
use App\Models\TahfizEnrollment;
use App\Services\Tahfiz\ParticipantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Enrollment: pendaftaran satu peserta pada satu program.
 *
 * Alur status: draft → registered → assessment → approved → active
 * → (suspended | completed | withdrawn). Penolakan bisa terjadi kapan saja
 * sebelum aktif. Tiap transisi divalidasi di `ParticipantService` agar urutan
 * yang tidak sah ditolak, dan transisi keluar wajib disertai alasan.
 */
class TahfizEnrollmentController extends BaseApiController
{
    protected array $filterable = [
        'status', 'participant_id', 'program_id', 'halaqah_id', 'level_id',
        'school_class_id', 'academic_year_id', 'semester_id',
    ];

    protected array $with = ['participant', 'program', 'level', 'halaqah', 'schoolClass'];

    public function __construct(protected ParticipantService $participants)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = TahfizEnrollment::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        if ($search = $request->query('search')) {
            $query->whereHas('participant', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $query->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizEnrollmentResource::collection($query->get()),
                'Daftar enrollment Tahfiz berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizEnrollmentResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar enrollment Tahfiz berhasil diambil'
        );
    }

    public function show(TahfizEnrollment $enrollment): JsonResponse
    {
        return $this->successResponse(
            new TahfizEnrollmentResource($enrollment->load($this->with)),
            'Detail enrollment Tahfiz berhasil diambil'
        );
    }

    public function store(TahfizEnrollmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['code'] ??= $this->nextCode();
        $data['created_by'] = $request->user()?->id;
        $data['status'] ??= TahfizEnrollment::STATUS_REGISTERED;
        $data['enrolled_at'] ??= now()->toDateString();

        $enrollment = TahfizEnrollment::create($data);

        return $this->createdResponse(
            new TahfizEnrollmentResource($enrollment->load($this->with)),
            'Enrollment Tahfiz berhasil dibuat'
        );
    }

    public function update(TahfizEnrollmentRequest $request, TahfizEnrollment $enrollment): JsonResponse
    {
        $data = $request->validated();
        unset($data['placement_test_id']);

        $enrollment->update($data);

        return $this->successResponse(
            new TahfizEnrollmentResource($enrollment->refresh()->load($this->with)),
            'Enrollment Tahfiz berhasil diperbarui'
        );
    }

    public function destroy(TahfizEnrollment $enrollment): JsonResponse
    {
        $enrollment->delete();

        return $this->successResponse(null, 'Enrollment Tahfiz berhasil dihapus');
    }

    /**
     * Ubah status enrollment (menyetujui, mengaktifkan, menunda, dll).
     */
    public function transition(TahfizEnrollmentTransitionRequest $request, TahfizEnrollment $enrollment): JsonResponse
    {
        $enrollment = $this->participants->transition(
            $enrollment,
            $request->validated(),
            $request->user()?->id ?? 0
        );

        return $this->successResponse(
            new TahfizEnrollmentResource($enrollment->load($this->with)),
            'Status enrollment berhasil diubah ke '.$enrollment->status
        );
    }

    /**
     * Pintasan: setujui enrollment (assessment/registered → approved).
     */
    public function approve(Request $request, TahfizEnrollment $enrollment): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $enrollment = $this->participants->transition($enrollment, [
            'status' => TahfizEnrollment::STATUS_APPROVED,
            'level_id' => $enrollment->level_id,
            'halaqah_id' => $enrollment->halaqah_id,
            'reason' => $request->input('reason'),
        ], $request->user()?->id ?? 0);

        return $this->successResponse(
            new TahfizEnrollmentResource($enrollment->load($this->with)),
            'Enrollment disetujui.'
        );
    }

    protected function nextCode(): string
    {
        return TahfizEnrollment::nextCode();
    }
}
