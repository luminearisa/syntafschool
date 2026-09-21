<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LibraryAcquisitionRequestResource;
use App\Models\Library;
use App\Models\LibraryAcquisitionRequest;
use App\Services\Library\Circulation\AcquisitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pengadaan koleksi: usulan → persetujuan → pesanan → penerimaan.
 *
 * Penerimaan membuat bibliographic record (bila belum ada) + eksemplar,
 * sehingga koleksi baru langsung dapat tersedia di OPAC.
 */
class LibraryAcquisitionController extends BaseApiController
{
    public function __construct(protected AcquisitionService $acquisitions)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->acquisitions->query($request);

        if ($request->boolean('all')) {
            return $this->successResponse(
                LibraryAcquisitionRequestResource::collection($query->get()),
                'Daftar pengadaan berhasil diambil'
            );
        }

        return $this->successResponse(
            LibraryAcquisitionRequestResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar pengadaan berhasil diambil'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $validated['requested_by'] = $request->user()?->id;

        $acquisition = $this->acquisitions->create($validated);

        return $this->createdResponse(
            new LibraryAcquisitionRequestResource($acquisition->load(['supplier', 'items'])),
            'Permintaan pengadaan berhasil dibuat'
        );
    }

    public function show(LibraryAcquisitionRequest $acquisition): JsonResponse
    {
        return $this->successResponse(
            new LibraryAcquisitionRequestResource($acquisition->load(['supplier', 'items.bibliography'])),
            'Detail pengadaan berhasil diambil'
        );
    }

    public function update(Request $request, LibraryAcquisitionRequest $acquisition): JsonResponse
    {
        $validated = $request->validate($this->rules(withItems: true));

        $acquisition = $this->acquisitions->update($acquisition, $validated);

        return $this->successResponse(
            new LibraryAcquisitionRequestResource($acquisition->load(['supplier', 'items'])),
            'Pengadaan berhasil diperbarui'
        );
    }

    /**
     * Hapus pengadaan — ditolak bila sudah diterima (koleksinya sudah dibuat).
     */
    public function destroy(LibraryAcquisitionRequest $acquisition): JsonResponse
    {
        if ($acquisition->status === LibraryAcquisitionRequest::STATUS_RECEIVED) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'acquisition' => 'Pengadaan yang sudah diterima tidak dapat dihapus karena koleksinya telah dibuat.',
            ]);
        }

        $acquisition->delete();

        return $this->successResponse(null, 'Pengadaan berhasil dihapus');
    }

    /**
     * Ubah status mengikuti peta transisi.
     */
    public function transition(Request $request, LibraryAcquisitionRequest $acquisition): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', LibraryAcquisitionRequest::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $acquisition = $this->acquisitions->transition(
            $acquisition,
            $validated['status'],
            $validated['reason'] ?? null,
            $request->user()?->id
        );

        return $this->successResponse(
            new LibraryAcquisitionRequestResource($acquisition->load(['supplier', 'items'])),
            "Status pengadaan diubah menjadi \"{$acquisition->status}\""
        );
    }

    /**
     * Penerimaan (receiving) → membuat eksemplar.
     */
    public function receive(Request $request, LibraryAcquisitionRequest $acquisition): JsonResponse
    {
        $validated = $request->validate(['received_at' => ['nullable', 'date']]);

        $result = $this->acquisitions->receive($acquisition, $validated);

        return $this->successResponse([
            'request' => new LibraryAcquisitionRequestResource($result['request']->load(['supplier', 'items'])),
            'created_items' => $result['created_items'],
        ], "{$result['created_items']} eksemplar dibuat dari pengadaan ini");
    }

    public function summary(Request $request): JsonResponse
    {
        $libraryId = $request->filled('library_id') ? (int) $request->query('library_id') : null;

        return $this->successResponse(
            $this->acquisitions->summary($libraryId, $request->query('from'), $request->query('to')),
            'Ringkasan pengadaan berhasil diambil'
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(bool $withItems = false): array
    {
        $rules = [
            'library_id' => [$withItems ? 'sometimes' : 'required', 'integer', 'exists:libraries,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:publishers,id'],
            'supplier_name' => ['nullable', 'string', 'max:150'],
            'requested_at' => ['nullable', 'date'],
            'expected_at' => ['nullable', 'date', 'after_or_equal:requested_at'],
            'notes' => ['nullable', 'string'],
            'items' => [$withItems ? 'nullable' : 'required', 'array', 'min:1'],
            'items.*.title' => ['required_with:items', 'string', 'max:255'],
            'items.*.isbn' => ['nullable', 'string', 'max:30'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:500'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.bibliography_id' => ['nullable', 'integer', 'exists:library_bibliographies,id'],
            'items.*.collection_type_id' => ['nullable', 'integer', 'exists:library_collection_types,id'],
            'items.*.notes' => ['nullable', 'string'],
        ];

        return $rules;
    }
}