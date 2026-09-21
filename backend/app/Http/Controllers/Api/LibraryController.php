<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Library\LibraryRequest;
use App\Http\Requests\Library\LibraryTransitionRequest;
use App\Http\Resources\LibraryResource;
use App\Models\Library;
use App\Services\Library\LibraryDashboardService;
use App\Services\Library\LibraryService;
use App\Services\Library\LibrarySetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perpustakaan: CRUD, siklus status, dashboard, dan status kelengkapan.
 *
 * Satu sistem dapat memiliki beberapa perpustakaan — tidak ada asumsi
 * perpustakaan tunggal.
 */
class LibraryController extends BaseApiController
{
    protected array $filterable = ['status', 'school_id'];

    protected array $with = ['school'];

    protected array $counts = ['locations', 'operatingHours', 'closures', 'numberingConfigs'];

    public function __construct(
        protected LibraryService $service,
        protected LibrarySetupService $setup,
        protected LibraryDashboardService $dashboard,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Library::class);

        $query = Library::query()->with($this->with)->withCount($this->counts);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"));
        }

        $query->orderBy('name');

        if ($request->boolean('all')) {
            return $this->successResponse(
                LibraryResource::collection($query->get()),
                'Daftar perpustakaan berhasil diambil'
            );
        }

        return $this->successResponse(
            LibraryResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar perpustakaan berhasil diambil'
        );
    }

    public function store(LibraryRequest $request): JsonResponse
    {
        $this->authorize('create', Library::class);

        $library = $this->service->create($request->validated());

        return $this->createdResponse(
            new LibraryResource($library->load($this->with)->loadCount($this->counts)),
            'Perpustakaan berhasil dibuat'
        );
    }

    public function show(Library $library): JsonResponse
    {
        $this->authorize('view', $library);

        return $this->successResponse(
            new LibraryResource($library->load($this->with)->loadCount($this->counts)),
            'Detail perpustakaan berhasil diambil'
        );
    }

    public function update(LibraryRequest $request, Library $library): JsonResponse
    {
        $this->authorize('update', $library);

        $library = $this->service->update($library, $request->validated());

        return $this->successResponse(
            new LibraryResource($library->load($this->with)->loadCount($this->counts)),
            'Perpustakaan berhasil diperbarui'
        );
    }

    public function destroy(Library $library): JsonResponse
    {
        $this->authorize('delete', $library);

        $this->service->delete($library);

        return $this->successResponse(null, 'Perpustakaan berhasil dihapus');
    }

    /**
     * Ubah status (draft → active → inactive → archived).
     */
    public function transition(LibraryTransitionRequest $request, Library $library): JsonResponse
    {
        $this->authorize('transition', $library);

        $validated = $request->validated();

        $library = $this->service->transition($library, $validated['status'], $validated['reason'] ?? null);

        return $this->successResponse(
            new LibraryResource($library->load($this->with)->loadCount($this->counts)),
            "Status perpustakaan diubah menjadi \"{$library->status}\""
        );
    }

    /**
     * Ringkasan dashboard — hanya data yang tersedia (statistik koleksi
     * & anggota menyusul pada Step berikutnya).
     */
    public function dashboard(Library $library): JsonResponse
    {
        $this->authorize('viewDashboard', $library);

        return $this->successResponse(
            $this->dashboard->forLibrary($library),
            'Dashboard perpustakaan berhasil diambil'
        );
    }

    /**
     * Kelengkapan konfigurasi (checklist onboarding).
     */
    public function setupStatus(Library $library): JsonResponse
    {
        $this->authorize('viewDashboard', $library);

        return $this->successResponse(
            $this->setup->summary($library),
            'Status kelengkapan perpustakaan berhasil diambil'
        );
    }
}