<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizProgramRequest;
use App\Http\Resources\TahfizProgramResource;
use App\Models\TahfizProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Program Tahfiz — konfigurasi yang menentukan jenis peserta, durasi, target,
 * kehadiran minimum, dan skema penilaian.
 *
 * Aturan ditentukan per program (data), bukan ditulis keras di kode, sehingga
 * admin dapat menambah program baru tanpa merilis ulang aplikasi.
 */
class TahfizProgramController extends BaseApiController
{
    protected array $filterable = ['status', 'participant_type', 'target_type', 'self_registration', 'requires_placement_test'];

    protected array $with = [];

    protected array $counts = ['levels', 'enrollments', 'halaqahs'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizProgram::query()->withCount($this->counts);

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
                TahfizProgramResource::collection($query->get()),
                'Daftar program Tahfiz berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizProgramResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar program Tahfiz berhasil diambil'
        );
    }

    public function show(TahfizProgram $program): JsonResponse
    {
        return $this->successResponse(
            new TahfizProgramResource($program->loadCount($this->counts)->load('levels')),
            'Detail program Tahfiz berhasil diambil'
        );
    }

    public function store(TahfizProgramRequest $request): JsonResponse
    {
        $program = TahfizProgram::create($request->validated());

        return $this->createdResponse(
            new TahfizProgramResource($program->loadCount($this->counts)),
            'Program Tahfiz berhasil dibuat'
        );
    }

    public function update(TahfizProgramRequest $request, TahfizProgram $program): JsonResponse
    {
        $data = $request->validated();

        // Kode program unik; abaikan kalau nilainya sama.
        if (($data['code'] ?? null) === $program->code) {
            unset($data['code']);
        }

        $program->update($data);

        return $this->successResponse(
            new TahfizProgramResource($program->loadCount($this->counts)),
            'Program Tahfiz berhasil diperbarui'
        );
    }

    public function destroy(TahfizProgram $program): JsonResponse
    {
        $program->delete();

        return $this->successResponse(null, 'Program Tahfiz berhasil dihapus');
    }
}
