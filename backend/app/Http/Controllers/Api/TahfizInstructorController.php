<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizInstructorRequest;
use App\Http\Resources\TahfizInstructorResource;
use App\Models\TahfizInstructor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profil instruktur Tahfiz: guru, asisten, musyrif, musyrifah.
 *
 * Pengajar adalah pegawai (`employees`); tabel ini hanya menyimpan kekhususan
 * mengajar Al-Quran mereka. Pemisahan peran instructor vs musyrif memungkinkan
 * pembagian kewenangan: instructor mengajar, musyrif memantau.
 */
class TahfizInstructorController extends BaseApiController
{
    protected array $filterable = ['role', 'is_active'];

    protected array $counts = ['halaqahs'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizInstructor::query()->with('employee')->withCount($this->counts);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        $query->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizInstructorResource::collection($query->get()),
                'Daftar instruktur Tahfiz berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizInstructorResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar instruktur Tahfiz berhasil diambil'
        );
    }

    public function show(TahfizInstructor $instructor): JsonResponse
    {
        return $this->successResponse(
            new TahfizInstructorResource($instructor->load('employee')->loadCount($this->counts)),
            'Detail instruktur Tahfiz berhasil diambil'
        );
    }

    public function store(TahfizInstructorRequest $request): JsonResponse
    {
        $instructor = TahfizInstructor::create($request->validated());

        return $this->createdResponse(
            new TahfizInstructorResource($instructor->load('employee')),
            'Instruktur Tahfiz berhasil ditambahkan'
        );
    }

    public function update(TahfizInstructorRequest $request, TahfizInstructor $instructor): JsonResponse
    {
        $instructor->update($request->validated());

        return $this->successResponse(
            new TahfizInstructorResource($instructor->load('employee')),
            'Instruktur Tahfiz berhasil diperbarui'
        );
    }

    public function destroy(TahfizInstructor $instructor): JsonResponse
    {
        $instructor->delete();

        return $this->successResponse(null, 'Instruktur Tahfiz berhasil dihapus');
    }
}
