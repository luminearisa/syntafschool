<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Library\LibraryClosureRequest;
use App\Http\Resources\LibraryClosureResource;
use App\Models\Library;
use App\Models\LibraryClosure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penutupan khusus perpustakaan (libur, maintenance, acara sekolah).
 */
class LibraryClosureController extends BaseApiController
{
    public function index(Request $request, Library $library): JsonResponse
    {
        $this->authorize('viewClosures', $library);

        $query = LibraryClosure::query()->where('library_id', $library->id);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, ['type', 'status'], true)) {
                $query->where($column, $value);
            }
        }

        if ($from = $request->query('from')) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('date', '<=', $to);
        }

        $query->orderByDesc('date');

        if ($request->boolean('all')) {
            return $this->successResponse(
                LibraryClosureResource::collection($query->get()),
                'Daftar penutupan berhasil diambil'
            );
        }

        return $this->successResponse(
            LibraryClosureResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar penutupan berhasil diambil'
        );
    }

    public function store(LibraryClosureRequest $request, Library $library): JsonResponse
    {
        $this->authorize('createClosure', $library);

        $closure = LibraryClosure::create($request->validated() + ['library_id' => $library->id]);

        return $this->createdResponse(
            new LibraryClosureResource($closure),
            'Penutupan berhasil dibuat'
        );
    }

    public function update(LibraryClosureRequest $request, Library $library, LibraryClosure $closure): JsonResponse
    {
        $this->authorize('updateClosure', $library);

        $closure->update($request->validated());

        return $this->successResponse(
            new LibraryClosureResource($closure),
            'Penutupan berhasil diperbarui'
        );
    }

    public function destroy(Library $library, LibraryClosure $closure): JsonResponse
    {
        $this->authorize('deleteClosure', $library);

        $closure->delete();

        return $this->successResponse(null, 'Penutupan berhasil dihapus');
    }
}