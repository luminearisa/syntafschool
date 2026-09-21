<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Library\LibraryMasterRequest;
use App\Http\Resources\LibraryMasterResource;
use App\Services\Library\MasterData\LibraryMasterRegistry;
use App\Services\Library\MasterData\LibraryMasterService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Master data Library (Step 2) — satu controller generik untuk 18 master.
 *
 * Setiap master tetap punya rute sendiri dengan middleware permission
 * granular (`library.collection_type.create`, dst.), sedangkan bentuk data,
 * scope, hierarki, dan proteksi hapus ditangani
 * `LibraryMasterRegistry` + `LibraryMasterService` agar tidak ada 18 salinan
 * logika yang sama.
 */
class LibraryMasterController extends BaseApiController
{
    public function __construct(protected LibraryMasterService $service)
    {
    }

    /**
     * Metadata seluruh master (grup, scope, hierarki, definisi field) —
     * menyetir antarmuka generik.
     */
    public function meta(): JsonResponse
    {
        $masters = collect(LibraryMasterRegistry::all())->map(fn (array $master, string $key) => [
            'key' => $key,
            'label' => $master['label'],
            'group' => $master['group'],
            'scoped' => $master['scoped'],
            'hierarchical' => $master['hierarchical'],
            'permission' => $master['permission'],
            'fields' => LibraryMasterRegistry::fields($key),
        ])->values();

        return $this->successResponse(['masters' => $masters], 'Metadata master data berhasil diambil');
    }

    public function index(Request $request): JsonResponse
    {
        $master = $this->service->descriptor($this->masterKey($request));

        $query = $this->service->query($master, $request);

        if ($request->boolean('all')) {
            $rows = $query->get();
            $this->attachUsage($master, $rows);

            return $this->successResponse(
                LibraryMasterResource::collection($rows),
                "Daftar {$master['label']} berhasil diambil"
            );
        }

        $page = $query->paginate((int) $request->query('per_page', 15));
        $this->attachUsage($master, $page->getCollection());

        return $this->successResponse(
            LibraryMasterResource::collection($page),
            "Daftar {$master['label']} berhasil diambil"
        );
    }

    public function store(LibraryMasterRequest $request): JsonResponse
    {
        $master = $this->service->descriptor($this->masterKey($request));

        $model = $this->service->create($master, $request->validated());

        return $this->createdResponse(
            $this->present($master, $model),
            "{$master['label']} berhasil dibuat"
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $master = $this->service->descriptor($this->masterKey($request));

        $model = $this->resolve($master, $id);

        return $this->successResponse(
            $this->present($master, $model),
            "Detail {$master['label']} berhasil diambil"
        );
    }

    public function update(LibraryMasterRequest $request, int $id): JsonResponse
    {
        $master = $this->service->descriptor($this->masterKey($request));

        $model = $this->resolve($master, $id);

        $model = $this->service->update($master, $model, $request->validated());

        return $this->successResponse(
            $this->present($master, $model),
            "{$master['label']} berhasil diperbarui"
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $master = $this->service->descriptor($this->masterKey($request));

        $model = $this->resolve($master, $id);

        $this->service->delete($master, $model);

        return $this->successResponse(null, "{$master['label']} berhasil dihapus");
    }

    /**
     * Aktifkan / nonaktifkan banyak data sekaligus (§27).
     */
    public function bulkStatus(Request $request): JsonResponse
    {
        $master = $this->service->descriptor($this->masterKey($request));

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $request->user()?->can("library.{$master['permission']}.update")) {
            abort(403, 'Anda tidak memiliki izin mengubah master data ini.');
        }

        $count = $this->service->bulkStatus(
            $master,
            $validated['ids'],
            $validated['status'],
            $validated['reason'] ?? null
        );

        return $this->successResponse(
            ['updated' => $count],
            "{$count} {$master['label']} berhasil diperbarui"
        );
    }

    protected function masterKey(Request $request): string
    {
        return (string) $request->route('master');
    }

    /**
     * @param  array<string, mixed>  $master
     */
    protected function resolve(array $master, int $id): Model
    {
        /** @var Model $model */
        $model = $master['model']::query()->findOrFail($id);

        return $model;
    }

    /**
     * @param  array<string, mixed>  $master
     * @return list<string>
     */
    protected function eagerLoads(array $master): array
    {
        return array_merge(
            ['parent'],
            array_keys($master['relations'] ?? []),
            $master['scoped'] ? ['library'] : []
        );
    }

    /**
     * Sajikan satu baris master lengkap: relasi, jumlah anak, dan jumlah
     * pemakaian.
     *
     * @param  array<string, mixed>  $master
     */
    protected function present(array $master, Model $model): LibraryMasterResource
    {
        if ($master['hierarchical']) {
            $model->loadCount('children');
        }

        $model->load($this->eagerLoads($master));
        $model->usage_count = $this->service->usageCount($master, $model);

        return new LibraryMasterResource($model);
    }

    /**
     * Pasang jumlah pemakaian pada tiap baris (satu query per definisi
     * pemakaian, bukan per baris).
     *
     * @param  array<string, mixed>  $master
     * @param  Collection<int, Model>  $rows
     */
    protected function attachUsage(array $master, Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $counts = $this->service->usageCounts($master, $rows->pluck('id')->map(fn ($id) => (int) $id)->all());

        foreach ($rows as $row) {
            $row->usage_count = $counts[(int) $row->id] ?? 0;
        }
    }
}