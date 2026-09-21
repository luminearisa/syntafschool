<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Curriculum\ProgramRequest;
use App\Http\Resources\ProgramResource;
use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Program / jurusan / peminatan (IPA, IPS, Bahasa).
 */
class ProgramController extends BaseApiController
{
    /** Kolom yang boleh difilter lewat query string. */
    protected array $filterable = ['education_level_id', 'is_active'];

    public function index(Request $request): JsonResponse
    {
        $query = Program::query()
            ->with('educationLevel:id,name,jenjang,level_number')
            ->withCount('students');

        $this->applyFilters($query, $request);

        if ($search = $request->query('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $query->orderBy('name');

        if ($request->boolean('all')) {
            return $this->successResponse(
                ProgramResource::collection($query->get()),
                'Program berhasil diambil',
            );
        }

        return $this->successResponse(
            ProgramResource::collection($query->paginate((int) $request->query('per_page', 50))),
            'Program berhasil diambil',
        );
    }

    public function store(ProgramRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['education_level_id'] = $data['education_level_id'] ?? null;
        $data['is_active'] = $request->boolean('is_active', true);

        $program = Program::create($data);

        return $this->createdResponse(
            new ProgramResource($program->load('educationLevel')),
            'Program berhasil ditambahkan',
        );
    }

    public function show(Program $program): JsonResponse
    {
        return $this->successResponse(
            new ProgramResource($program->load('educationLevel')->loadCount('students')),
            'Detail program berhasil diambil',
        );
    }

    public function update(ProgramRequest $request, Program $program): JsonResponse
    {
        $data = $request->validated();
        $data['education_level_id'] = $data['education_level_id'] ?? null;

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $program->update($data);

        return $this->successResponse(
            new ProgramResource($program->refresh()->load('educationLevel')->loadCount('students')),
            'Program berhasil diperbarui',
        );
    }

    public function destroy(Program $program): JsonResponse
    {
        $program->delete();

        return $this->successResponse(null, 'Program berhasil dihapus');
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        $filters = array_merge(
            (array) $request->query('filter', []),
            array_intersect_key($request->query(), array_flip($this->filterable)),
        );

        foreach ($filters as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }

            if ($column === 'is_active') {
                $query->where('is_active', filter_var($value, FILTER_VALIDATE_BOOLEAN));
                continue;
            }

            $query->where($column, $value);
        }
    }
}
