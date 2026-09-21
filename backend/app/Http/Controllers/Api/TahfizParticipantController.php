<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizParticipantRequest;
use App\Http\Resources\TahfizParticipantResource;
use App\Models\TahfizParticipant;
use App\Services\Tahfiz\ParticipantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Peserta Tahfiz — entitas tunggal untuk siswa, pegawai, wali, peserta umum,
 * alumni, dan lainnya.
 *
 * Peserta umum menyimpan identitasnya langsung; peserta terikat ke entitas
 * induk (student/employee/guardian) dan identitasnya diambil dari sana.
 */
class TahfizParticipantController extends BaseApiController
{
    protected array $filterable = ['participant_type', 'status', 'student_id', 'employee_id', 'guardian_id'];

    protected array $with = ['profile', 'student', 'employee', 'guardian'];

    protected array $counts = ['enrollments'];

    public function __construct(protected ParticipantService $participants)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = TahfizParticipant::query()
            ->with($this->with)
            ->withCount($this->counts);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        $query->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizParticipantResource::collection($query->get()),
                'Daftar peserta Tahfiz berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizParticipantResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar peserta Tahfiz berhasil diambil'
        );
    }

    public function show(TahfizParticipant $participant): JsonResponse
    {
        return $this->successResponse(
            new TahfizParticipantResource($participant->load($this->with)->loadCount($this->counts)),
            'Detail peserta Tahfiz berhasil diambil'
        );
    }

    public function store(TahfizParticipantRequest $request): JsonResponse
    {
        $data = $request->validated();
        $profile = $data['profile'] ?? null;

        $participant = $this->participants->createParticipant($data, $profile);

        return $this->createdResponse(
            new TahfizParticipantResource($participant->load($this->with)),
            'Peserta Tahfiz berhasil dibuat'
        );
    }

    public function update(TahfizParticipantRequest $request, TahfizParticipant $participant): JsonResponse
    {
        $data = $request->validated();
        $profile = $data['profile'] ?? null;
        unset($data['profile']);

        $participant->update($data);

        if ($profile !== null) {
            $participant->profile()->updateOrCreate(['participant_id' => $participant->id], $profile);
        }

        return $this->successResponse(
            new TahfizParticipantResource($participant->refresh()->load($this->with)),
            'Peserta Tahfiz berhasil diperbarui'
        );
    }

    public function destroy(TahfizParticipant $participant): JsonResponse
    {
        $participant->delete();

        return $this->successResponse(null, 'Peserta Tahfiz berhasil dihapus');
    }
}
