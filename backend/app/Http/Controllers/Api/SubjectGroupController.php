<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Curriculum\SubjectGroupRequest;
use App\Http\Resources\SubjectGroupResource;
use App\Models\SubjectGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kelompok mata pelajaran (Wajib, MIPA, IPS, Bahasa, Muatan Lokal, dst.).
 */
class SubjectGroupController extends BaseApiController
{
    /** Kolom yang boleh difilter lewat query string. */
    protected array $filterable = ['is_active'];

    public function index(Request $request): JsonResponse
    {
        $query = SubjectGroup::query()->withCount('items');

        $this->applyFilters($query, $request);

        if ($search = $request->query('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $query->orderBy('sort_order')->orderBy('name');

        if ($request->boolean('all')) {
            return $this->successResponse(
                SubjectGroupResource::collection($query->get()),
                'Kelompok mapel berhasil diambil',
            );
        }

        return $this->successResponse(
            SubjectGroupResource::collection($query->paginate((int) $request->query('per_page', 50))),
            'Kelompok mapel berhasil diambil',
        );
    }

    public function store(SubjectGroupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', true);

        $group = SubjectGroup::create($data);

        return $this->createdResponse(
            new SubjectGroupResource($group),
            'Kelompok mapel berhasil ditambahkan',
        );
    }

    public function show(SubjectGroup $subjectGroup): JsonResponse
    {
        return $this->successResponse(
            new SubjectGroupResource($subjectGroup->loadCount('items')),
            'Detail kelompok mapel berhasil diambil',
        );
    }

    public function update(SubjectGroupRequest $request, SubjectGroup $subjectGroup): JsonResponse
    {
        $data = $request->validated();

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $subjectGroup->update($data);

        return $this->successResponse(
            new SubjectGroupResource($subjectGroup->refresh()->loadCount('items')),
            'Kelompok mapel berhasil diperbarui',
        );
    }

    public function destroy(SubjectGroup $subjectGroup): JsonResponse
    {
        $subjectGroup->delete();

        return $this->successResponse(null, 'Kelompok mapel berhasil dihapus');
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
