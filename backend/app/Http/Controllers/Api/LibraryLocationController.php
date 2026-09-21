<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Library\LibraryLocationRequest;
use App\Http\Resources\LibraryLocationResource;
use App\Models\Library;
use App\Models\LibraryLocation;
use App\Services\Library\LibraryLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Lokasi perpustakaan (hierarkis).
 *
 * `index` mengembalikan daftar datar (untuk tabel) atau pohon lengkap
 * (`?tree=1`) untuk tampilan pohon di antarmuka.
 */
class LibraryLocationController extends BaseApiController
{
    public function __construct(protected LibraryLocationService $service)
    {
    }

    public function index(Request $request, Library $library): JsonResponse
    {
        $this->authorize('viewLocations', $library);

        $query = LibraryLocation::query()->where('library_id', $library->id);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, ['type', 'status'], true)) {
                $query->where($column, $value);
            }
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"));
        }

        $query->orderBy('name');

        if ($request->boolean('tree')) {
            return $this->successResponse(
                LibraryLocationResource::collection($this->buildTree($query->get())),
                'Pohon lokasi berhasil diambil'
            );
        }

        $query->withCount('children');

        if ($request->boolean('all')) {
            return $this->successResponse(
                LibraryLocationResource::collection($query->get()),
                'Daftar lokasi berhasil diambil'
            );
        }

        return $this->successResponse(
            LibraryLocationResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar lokasi berhasil diambil'
        );
    }

    public function store(LibraryLocationRequest $request, Library $library): JsonResponse
    {
        $this->authorize('createLocation', $library);

        $location = $this->service->create($library, $request->validated());

        return $this->createdResponse(
            new LibraryLocationResource($location->load('parent')->loadCount('children')),
            'Lokasi berhasil dibuat'
        );
    }

    public function update(LibraryLocationRequest $request, Library $library, LibraryLocation $location): JsonResponse
    {
        $this->authorize('updateLocation', $library);

        $location = $this->service->update($location, $request->validated());

        return $this->successResponse(
            new LibraryLocationResource($location->load('parent')->loadCount('children')),
            'Lokasi berhasil diperbarui'
        );
    }

    public function destroy(Library $library, LibraryLocation $location): JsonResponse
    {
        $this->authorize('deleteLocation', $library);

        $this->service->delete($location);

        return $this->successResponse(null, 'Lokasi berhasil dihapus');
    }

    /**
     * Pindahkan lokasi ke induk lain (atau ke akar bila `parent_id` null).
     */
    public function move(Request $request, Library $library, LibraryLocation $location): JsonResponse
    {
        $this->authorize('updateLocation', $library);

        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:library_locations,id'],
        ]);

        $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;

        $location = $this->service->move($location, $parentId);

        return $this->successResponse(
            new LibraryLocationResource($location->load('parent')->loadCount('children')),
            'Lokasi berhasil dipindahkan'
        );
    }

    /**
     * Susun pohon dari daftar datar tanpa query tambahan (hindari N+1).
     *
     * @param  Collection<int, LibraryLocation>  $locations
     * @return Collection<int, LibraryLocation>
     */
    protected function buildTree(Collection $locations): Collection
    {
        $byParent = $locations->groupBy(fn (LibraryLocation $l) => $l->parent_id ?? 0);

        foreach ($locations as $location) {
            $location->setRelation('children', $byParent->get($location->id, collect())->values());
        }

        return $locations->filter(fn (LibraryLocation $l) => $l->parent_id === null)->values();
    }
}