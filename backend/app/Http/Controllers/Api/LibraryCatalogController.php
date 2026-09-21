<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Library\LibraryBibliographyRequest;
use App\Http\Resources\LibraryBibliographyResource;
use App\Models\LibraryBibliography;
use App\Services\Library\Catalog\CatalogService;
use App\Services\Library\Opac\OpacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Katalog bibliografi (admin/librarian).
 *
 * Otorisasi ditegakkan middleware rute (`library.catalog.*`); aturan
 * relasional (control number, slug, duplikasi, proteksi hapus) ada di
 * CatalogService.
 */
class LibraryCatalogController extends BaseApiController
{
    public function __construct(
        protected CatalogService $catalog,
        protected OpacService $opac,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->catalog->query($request);

        if ($request->boolean('all')) {
            return $this->successResponse(
                LibraryBibliographyResource::collection($query->get()),
                'Daftar katalog berhasil diambil'
            );
        }

        return $this->successResponse(
            LibraryBibliographyResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar katalog berhasil diambil'
        );
    }

    public function store(LibraryBibliographyRequest $request): JsonResponse
    {
        $bibliography = $this->catalog->create($request->validated());

        return $this->createdResponse(
            new LibraryBibliographyResource($bibliography->load($this->relations())),
            'Record katalog berhasil dibuat'
        );
    }

    public function show(LibraryBibliography $bibliography): JsonResponse
    {
        $bibliography->load($this->relations());
        $bibliography->loadCount(['items']);
        $bibliography->loadCount(['items as available_items_count' => fn ($q) => $q->available()]);

        return $this->successResponse(
            new LibraryBibliographyResource($bibliography),
            'Detail record katalog berhasil diambil'
        );
    }

    public function update(LibraryBibliographyRequest $request, LibraryBibliography $bibliography): JsonResponse
    {
        $bibliography = $this->catalog->update($bibliography, $request->validated());

        return $this->successResponse(
            new LibraryBibliographyResource($bibliography->load($this->relations())),
            'Record katalog berhasil diperbarui'
        );
    }

    public function destroy(LibraryBibliography $bibliography): JsonResponse
    {
        $this->catalog->delete($bibliography);

        return $this->successResponse(null, 'Record katalog berhasil dihapus');
    }

    /**
     * Deteksi kemungkinan duplikat sebelum menyimpan (§18).
     *
     * Memberi peringatan, bukan menolak — kecuali identifier baku yang
     * benar-benar sudah dipakai (ditolak oleh validasi simpan).
     */
    public function duplicateCheck(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'publisher_id' => ['nullable', 'integer'],
            'publication_year' => ['nullable', 'integer'],
            'identifiers' => ['nullable', 'array'],
            'identifiers.*.identifier_type' => ['required_with:identifiers', 'string', 'max:20'],
            'identifiers.*.value' => ['required_with:identifiers', 'string', 'max:100'],
            'ignore_id' => ['nullable', 'integer'],
        ]);

        $candidates = $this->catalog->duplicateCandidates($data, $data['ignore_id'] ?? null);

        return $this->successResponse(
            [
                'has_candidates' => $candidates->isNotEmpty(),
                'message' => $candidates->isNotEmpty() ? 'Kemungkinan record serupa ditemukan.' : null,
                'candidates' => $candidates->map(fn (LibraryBibliography $candidate) => [
                    'id' => $candidate->id,
                    'control_number' => $candidate->control_number,
                    'title' => $candidate->title,
                    'publisher' => $candidate->publisher?->name,
                    'publication_year' => $candidate->publication_year,
                ])->values(),
            ],
            'Pemeriksaan duplikat selesai'
        );
    }

    /**
     * Aktivasi/nonaktivasi massal (§56).
     */
    public function bulkStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'status' => ['required', 'string', 'in:'.implode(',', LibraryBibliography::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $records = LibraryBibliography::query()->whereIn('id', $validated['ids'])->get();

        foreach ($records as $record) {
            $record->update(['status' => $validated['status']]);

            if ($validated['status'] === LibraryBibliography::STATUS_INACTIVE) {
                activity('library')
                    ->event('BIBLIOGRAPHIC_DEACTIVATED')
                    ->performedOn($record)
                    ->withProperties(['reason' => $validated['reason'] ?? null])
                    ->log('Record katalog dinonaktifkan');
            }
        }

        return $this->successResponse(
            ['updated' => $records->count()],
            "{$records->count()} record katalog berhasil diperbarui"
        );
    }

    /**
     * Pratinjau bagaimana record tampil di OPAC.
     *
     * Record non-publik hanya dapat dipratinjau oleh pengelola OPAC
     * (`library.opac.manage`) — pratinjau saja tidak boleh membuka data draf
     * bagi semua pustakawan.
     */
    public function opacPreview(Request $request, LibraryBibliography $bibliography): JsonResponse
    {
        if (! $bibliography->isPublic() && ! $request->user()?->can('library.opac.manage')) {
            abort(403, 'Anda tidak memiliki izin mempratinjau record yang belum publik.');
        }

        $bibliography->load($this->relations());

        return $this->successResponse(
            $this->opac->present($bibliography),
            'Pratinjau OPAC berhasil diambil'
        );
    }

    /**
     * Ringkasan ketersediaan publik untuk sebuah record.
     */
    public function availability(LibraryBibliography $bibliography): JsonResponse
    {
        return $this->successResponse(
            $this->opac->availability($bibliography),
            'Ketersediaan koleksi berhasil diambil'
        );
    }

    /**
     * @return list<string>
     */
    protected function relations(): array
    {
        return [
            'publisher', 'language', 'collectionType', 'category', 'materialType',
            'series', 'classification', 'creatorLinks.creator', 'creatorLinks.creatorRole',
            'subjects', 'genres', 'tags', 'identifiers', 'files',
        ];
    }
}