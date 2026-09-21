<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizHalaqahRequest;
use App\Http\Resources\TahfizHalaqahResource;
use App\Models\TahfizHalaqah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Halaqah — kelompok belajar Tahfiz.
 *
 * Kapasitas ditegakkan saat peserta ditempatkan (lihat `ParticipantService`),
 * dan `active_members_count` selalu dihitung ulang dari anggota aktif.
 */
class TahfizHalaqahController extends BaseApiController
{
    protected array $filterable = ['program_id', 'level_id', 'status', 'instructor_id', 'musyrif_id'];

    protected array $with = ['program', 'level', 'room', 'instructor', 'assistant', 'musyrif'];

    protected array $counts = ['activeMembers', 'schedules'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizHalaqah::query()
            ->with($this->with)
            ->withCount($this->counts);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"));
        }

        $query->orderBy('name');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizHalaqahResource::collection($query->get()),
                'Daftar halaqah berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizHalaqahResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar halaqah berhasil diambil'
        );
    }

    public function show(TahfizHalaqah $halaqah): JsonResponse
    {
        return $this->successResponse(
            new TahfizHalaqahResource($halaqah->load($this->with)->loadCount($this->counts)->load('activeMembers.participant')),
            'Detail halaqah berhasil diambil'
        );
    }

    public function store(TahfizHalaqahRequest $request): JsonResponse
    {
        $halaqah = TahfizHalaqah::create($request->validated());

        return $this->createdResponse(
            new TahfizHalaqahResource($halaqah->load($this->with)->loadCount($this->counts)),
            'Halaqah berhasil dibuat'
        );
    }

    public function update(TahfizHalaqahRequest $request, TahfizHalaqah $halaqah): JsonResponse
    {
        $data = $request->validated();
        if (($data['code'] ?? null) === $halaqah->code) {
            unset($data['code']);
        }

        $halaqah->update($data);

        return $this->successResponse(
            new TahfizHalaqahResource($halaqah->load($this->with)->loadCount($this->counts)),
            'Halaqah berhasil diperbarui'
        );
    }

    public function destroy(TahfizHalaqah $halaqah): JsonResponse
    {
        $halaqah->delete();

        return $this->successResponse(null, 'Halaqah berhasil dihapus');
    }
}
