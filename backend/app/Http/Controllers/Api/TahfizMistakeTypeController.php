<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizMistakeTypeRequest;
use App\Http\Resources\TahfizMistakeTypeResource;
use App\Models\TahfizMistakeType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Taksonomi kesalahan hafalan/bacaan.
 *
 * Jenis kesalahan adalah data konfigurabel: lupa ayat, salah sambung, salah
 * lafadz, tajwid, makhraj, harakat, waqaf, mad, ghunnah — semuanya bisa
 * ditambah admin. Inilah yang memungkinkan analitik pola kesalahan.
 */
class TahfizMistakeTypeController extends BaseApiController
{
    protected array $filterable = ['category', 'is_active'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizMistakeType::query();

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        $query->orderBy('category')->orderBy('label');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizMistakeTypeResource::collection($query->get()),
                'Daftar jenis kesalahan berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizMistakeTypeResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar jenis kesalahan berhasil diambil'
        );
    }

    public function show(TahfizMistakeType $type): JsonResponse
    {
        return $this->successResponse(
            new TahfizMistakeTypeResource($type),
            'Detail jenis kesalahan berhasil diambil'
        );
    }

    public function store(TahfizMistakeTypeRequest $request): JsonResponse
    {
        $type = TahfizMistakeType::create($request->validated());

        return $this->createdResponse(
            new TahfizMistakeTypeResource($type),
            'Jenis kesalahan berhasil dibuat'
        );
    }

    public function update(TahfizMistakeTypeRequest $request, TahfizMistakeType $type): JsonResponse
    {
        $data = $request->validated();
        if (($data['code'] ?? null) === $type->code) {
            unset($data['code']);
        }

        $type->update($data);

        return $this->successResponse(
            new TahfizMistakeTypeResource($type),
            'Jenis kesalahan berhasil diperbarui'
        );
    }

    public function destroy(TahfizMistakeType $type): JsonResponse
    {
        $type->delete();

        return $this->successResponse(null, 'Jenis kesalahan berhasil dihapus');
    }
}
